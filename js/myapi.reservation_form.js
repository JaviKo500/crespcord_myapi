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
 * THE TIME SELECTS (SPEC 136). The start and end times are text inputs that
 * must hold 'HH:MM'. Each is hidden behind a select fed by
 * admin/content/reservations/area-schedule/{area}: the times of the picked
 * area, from its opening to its closing time in steps of its block size. The
 * select has no name — it writes into the text input, which is still the field
 * Drupal reads and validates. The end select only offers times after the
 * picked start and within the area's maximum duration. While no area is
 * picked both are disabled; when the area has no usable schedule, or the
 * request fails, the text inputs come back as they were. A stored time that
 * is off the grid is kept as an extra option when the form opens, so editing
 * a reservation never changes its times by itself.
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

          syncTimes(initial);
        }

        /* -----------------------------------------------------------------
         * The time selects.
         * -------------------------------------------------------------- */

        var times = config.times;
        // The schedule of the area the times are in step with; null while no
        // area is picked or its schedule is on its way.
        var schedule = null;
        // The area id the times were last brought in line with.
        var scheduleArea;
        // Guards against an answer that arrives after the area changed again.
        var scheduleRequest = 0;

        function timeInput(which) {
          return $form.find('[data-myapi-reservation-time="' + which + '"]');
        }

        /**
         * The select standing in for a time input, created on first use.
         */
        function timeSelect(which) {
          var $input = timeInput(which);
          var id = $input.attr('id') + '-select';
          var $select = $form.find('#' + id);

          if (!$select.length) {
            $select = $('<select/>')
              .attr('id', id)
              .attr('class', $input.attr('class') || '')
              .removeClass('form-text')
              .addClass('form-select');
            $select.data('myapiLabel', $form.find('label[for="' + $input.attr('id') + '"]'));
            $select.bind('change', function () {
              $input.val($select.val());
              if (which === 'start') {
                renderTime('end', false);
              }
            });
            $input.after($select);
          }

          return $select;
        }

        /**
         * Shows the selects, or gives the text inputs back.
         */
        function showTimeSelects(show) {
          $.each(['start', 'end'], function (index, which) {
            var $input = timeInput(which);
            var $select = timeSelect(which);

            $input[show ? 'hide' : 'show']();
            $select[show ? 'show' : 'hide']();
            $select.data('myapiLabel').attr('for', (show ? $select : $input).attr('id'));
          });
        }

        /**
         * The times a select offers under the current schedule.
         *
         * Every entry of the grid but the last (the closing time) can be a
         * start. An end comes after the picked start and no further from it
         * than the area's maximum duration; with no start picked, or one that
         * is off the grid, every entry but the first is offered.
         */
        function timeOptions(which) {
          var list = [];
          if (!schedule) {
            return list;
          }

          var grid = schedule.grid;
          var last = grid.length - 1;
          var i;

          if (which === 'start') {
            for (i = 0; i < last; i++) {
              list.push(grid[i].time);
            }
            return list;
          }

          // null is the only "no limit", as on the server: an area whose
          // maximum is 0 has no valid end, and offering one would only fail
          // on save.
          var limited = schedule.max_minutes !== null && schedule.max_minutes !== undefined;
          var start = timeInput('start').val();
          var from = -1;
          for (i = 0; i < last; i++) {
            if (grid[i].time === start) {
              from = i;
              break;
            }
          }

          for (i = from + 1 || 1; i <= last; i++) {
            if (from >= 0 && limited && grid[i].offset - grid[from].offset > schedule.max_minutes) {
              break;
            }
            list.push(grid[i].time);
          }

          return list;
        }

        /**
         * Rebuilds one select from the schedule and the value of its input.
         *
         * @param {string} which
         *   'start' or 'end'.
         * @param {boolean} keep
         *   TRUE keeps a value the select does not offer, as an extra option:
         *   a stored time must survive opening the form. FALSE empties it:
         *   the operator just changed the area or the start, and a time that
         *   no longer applies must not be saved unseen.
         */
        function renderTime(which, keep) {
          var $input = timeInput(which);
          var $select = timeSelect(which);
          var value = $input.val();
          var found = false;

          $select.empty().append($('<option/>').attr('value', '').text(schedule ? times.pick : times.noArea));
          $.each(timeOptions(which), function (index, time) {
            $select.append($('<option/>').attr('value', time).text(time));
            found = found || time === value;
          });

          if (value !== '' && !found) {
            if (keep) {
              $select.append($('<option/>').attr('value', value).text(value));
            }
            else {
              value = '';
              $input.val('');
            }
          }

          $select.val(value);
          if (schedule) {
            $select.removeAttr('disabled');
          }
          else {
            $select.attr('disabled', 'disabled');
          }
        }

        /**
         * Brings the time selects in line with the area. No-op while the area
         * is the one they already follow.
         *
         * @param {boolean} initial
         *   TRUE on page load: the stored times are kept whatever the grid
         *   says.
         */
        function syncTimes(initial) {
          if (!times || !timeInput('start').length || !timeInput('end').length) {
            return;
          }

          var $area = input('area');
          var areaId = $area.is(':disabled') ? '0' : parseId($area.val());
          if (areaId === scheduleArea) {
            return;
          }

          var keep = initial;
          var request = ++scheduleRequest;
          scheduleArea = areaId;
          schedule = null;
          showTimeSelects(true);

          if (areaId === '0') {
            renderTime('start', keep);
            renderTime('end', keep);
            return;
          }

          // Disabled, values untouched, until the schedule arrives.
          renderTime('start', true);
          renderTime('end', true);

          $.ajax({
            url: times.schedulePath + '/' + areaId,
            dataType: 'json',
            success: function (response) {
              if (request !== scheduleRequest) {
                return;
              }

              var data = response && response.success ? response.data : null;
              if (!data || !data.grid || data.grid.length < 2) {
                showTimeSelects(false);
                return;
              }

              schedule = data;
              renderTime('start', keep);
              renderTime('end', keep);
            },
            error: function () {
              if (request === scheduleRequest) {
                showTimeSelects(false);
              }
            }
          });
        }

        /**
         * Listens to a selector that other selectors depend on.
         *
         * 'autocompleteSelect' is what core fires when a suggestion is picked
         * with the mouse or the keyboard; 'change' and 'blur' cover a value
         * edited or erased by hand.
         *
         * The area has no child selector, but the time selects follow it, so
         * it is listened to as well when they are in use.
         */
        function watch(kind) {
          var isParent = !!times && kind === 'area';
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
