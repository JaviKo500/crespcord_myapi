/**
 * @file
 * Hour and minute selects for an 'HH:MM' text input (SPEC 136).
 *
 * Attached by myapi_area_form_alter() to the 'area' node form, through the
 * form's #attached and never from myapi.info. Every input tagged with
 * data-myapi-time-select is hidden and gets two selects next to it that write
 * a well-formed 24-hour 'HH:MM' into it, so the time cannot be mistyped and
 * always reads the same whatever the browser's locale is.
 *
 * It is a convenience, not the rule: the input is still the field Drupal
 * reads, and myapi_time_format_validate_fields() still checks it on the
 * server. A browser without JavaScript keeps the text input.
 *
 * Minutes go in steps of five, plus 59 — "end of day" is written 23:59 in
 * this module — plus whatever minute the stored value has, so opening the
 * form never changes a time by itself. A stored value that is not 'HH:MM' at
 * all is left as the text input it was: the operator has to see it to fix it.
 *
 * Written for the jQuery 1.4 that Drupal 7 ships: attr() and bind(), no
 * prop() and no on().
 */

(function ($) {
  'use strict';

  var PATTERN = /^([01][0-9]|2[0-3]):([0-5][0-9])$/;

  function pad(number) {
    return (number < 10 ? '0' : '') + number;
  }

  function option(value, label) {
    return $('<option/>').attr('value', value).text(label);
  }

  /**
   * The minutes offered: every five, 59, and the stored one.
   *
   * @param {string} stored
   *   The minute of the stored value, or '' when there is none.
   * @return {Array} Two-digit strings, ascending.
   */
  function minutes(stored) {
    var list = [];
    var minute;

    for (minute = 0; minute < 60; minute++) {
      if (minute % 5 === 0 || minute === 59 || pad(minute) === stored) {
        list.push(pad(minute));
      }
    }

    return list;
  }

  Drupal.behaviors.myapiTimeSelect = {
    attach: function (context) {
      $('input[data-myapi-time-select]', context).once('myapi-time-select', function () {
        var $input = $(this);
        var match = PATTERN.exec($input.val());

        if ($input.val() !== '' && !match) {
          return;
        }

        var classes = $input.attr('class') || '';
        var id = $input.attr('id');
        var $hour = $('<select/>').attr('id', id + '-hour').attr('class', classes);
        var $minute = $('<select/>').attr('id', id + '-minute').attr('class', classes);
        var hour;

        $hour.append(option('', 'HH'));
        for (hour = 0; hour < 24; hour++) {
          $hour.append(option(pad(hour), pad(hour)));
        }

        $minute.append(option('', 'MM'));
        $.each(minutes(match ? match[2] : ''), function (index, value) {
          $minute.append(option(value, value));
        });

        $hour.add($minute)
          .removeClass('form-text')
          .addClass('form-select')
          .css({display: 'inline-block', width: 'auto'});

        if (match) {
          $hour.val(match[1]);
          $minute.val(match[2]);
        }

        function write() {
          // An hour alone means "on the hour"; minutes alone are no time yet.
          if ($hour.val() !== '' && $minute.val() === '') {
            $minute.val('00');
          }

          $input.val($hour.val() === '' ? '' : $hour.val() + ':' + $minute.val());
        }

        $hour.bind('change', write);
        $minute.bind('change', write);

        $input.hide().after(
          $('<span class="myapi-time-select"/>').append($hour).append(' : ').append($minute)
        );
        $('label[for="' + id + '"]').attr('for', id + '-hour');
      });
    }
  };

})(jQuery);
