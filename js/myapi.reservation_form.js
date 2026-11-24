/**
 * @file
 * Dependent selectors of the back-office 'reservation' node form (SPEC 134).
 *
 * Attached by myapi_reservation_form_alter() through the form's #attached,
 * never declared in myapi.info: a scripts[] entry would ship this file on
 * every page of the site.
 *
 * The chain is condominium -> unit -> requester and condominium -> area. Each
 * dependent selector is a core autocomplete textfield whose URL ends in the id
 * of its parent. This file keeps that URL in step with what the parent input
 * actually holds, empties the selector when the parent changes, and keeps it
 * disabled while the parent has no value.
 *
 * It is a convenience, not the rule: the same coherence is enforced on the
 * server by myapi_reservation_form_validate_scope(), so a browser without
 * JavaScript loses the suggestions and nothing else.
 *
 * HOW A SELECTOR IS RE-POINTED. misc/autocomplete.js reads the URL once, from
 * a hidden input next to the textfield, and keeps it in a closure nothing can
 * reach afterwards. So instead of patching core, this does what an AJAX
 * replacement of the element would do: swap the textfield for a clone that
 * carries no event handlers, write the new URL in the hidden input, clear its
 * once() marker, and let core's own behaviour attach again.
 */

(function ($) {
  'use strict';

  /**
   * The parent id an input holds: the number in the trailing 'Label (123)'.
   *
   * A label typed by hand and not picked from the list has no id, and reads as
   * "no parent" — the children stay disabled until a suggestion is chosen.
   * The optional quote covers entityreference's wrapping of labels that carry
   * a comma or a quote.
   *
   * @param {string} value
   * @return {string} The id, or '0'.
   */
  function parseId(value) {
    var match = /\((\d+)\)"?\s*$/.exec(value || '');
    return match ? match[1] : '0';
  }

  Drupal.behaviors.myapiReservationForm = {
    attach: function (context, settings) {
      var config = settings.myapiReservationForm;
      // Without core's autocomplete behaviour there is nothing to re-attach:
      // leave the form alone rather than disable inputs nobody could fill.
      if (!config || !Drupal.behaviors.autocomplete) {
        return;
      }

      $('form', context).add($(context).filter('form')).has('[data-myapi-reservation-selector]').once('myapi-reservation-form', function () {
        var $form = $(this);

        function input(kind) {
          return $form.find('[data-myapi-reservation-selector="' + kind + '"]');
        }

        /**
         * Points a dependent selector at a parent id. No-op when it already is.
         */
        function repoint(kind, parentId) {
          var $input = input(kind);
          var hidden = document.getElementById($input.attr('id') + '-autocomplete');
          var uri = config.basePath + '/' + kind + '/' + parentId;

          if (!hidden || hidden.value === uri) {
            return;
          }

          var value = $input.val();
          var $fresh = $input.clone(false);
          $input.replaceWith($fresh);
          $fresh.val(value);

          $('#' + $fresh.attr('id') + '-autocomplete-aria-live').remove();
          hidden.value = uri;
          $(hidden).removeClass('autocomplete-processed');
          Drupal.behaviors.autocomplete.attach(document, Drupal.settings);

          // The clone lost this file's handlers too, and 'unit' is a parent.
          watch(kind);
        }

        /**
         * Brings every dependent selector in line with its parent.
         *
         * Walks parents before children (the order of config.parents), so a
         * unit emptied because the condominium changed is already empty when
         * the requester reads it.
         *
         * @param {boolean} initial
         *   TRUE on page load: values are kept, because an edit form and a
         *   form re-rendered after a validation error arrive filled in.
         */
        function sync(initial) {
          $.each(config.parents, function (kind, parent) {
            if (!parent) {
              return;
            }

            var $parent = input(parent);
            var parentId = $parent.is(':disabled') ? '0' : parseId($parent.val());
            var $input = input(kind);
            var previous = $input.data('myapiParentId');

            if (!initial && previous !== undefined && previous !== parentId) {
              $input.val('');
            }

            repoint(kind, parentId);

            $input = input(kind);
            $input.data('myapiParentId', parentId);
            if (parentId === '0') {
              $input.val('').attr('disabled', 'disabled').attr('placeholder', config.placeholders[kind] || '');
            }
            else {
              $input.removeAttr('disabled').removeAttr('placeholder');
            }
          });
        }

        /**
         * Listens to a selector that other selectors depend on.
         *
         * 'autocompleteSelect' is what core fires when a suggestion is picked
         * with the mouse or the keyboard; 'change' and 'blur' cover a value
         * edited or erased by hand.
         */
        function watch(kind) {
          var isParent = false;
          $.each(config.parents, function (child, parent) {
            isParent = isParent || parent === kind;
          });
          if (!isParent) {
            return;
          }

          input(kind)
            .unbind('.myapiReservationForm')
            .bind('autocompleteSelect.myapiReservationForm change.myapiReservationForm blur.myapiReservationForm', function () {
              sync(false);
            });
        }

        $.each(config.parents, function (kind) {
          watch(kind);
        });
        sync(true);
      });
    }
  };

})(jQuery);
