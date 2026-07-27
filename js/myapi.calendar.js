/**
 * @file
 * Detail panel of the back-office reservation calendar (SPEC 47).
 *
 * Loaded from myapi_reservation_calendar_page() with drupal_add_js(), never
 * declared in myapi.info: a scripts[] entry would ship this file on every page
 * of the site, API responses included.
 *
 * Vanilla JS: no jQuery, no external library, no AJAX and no request of any
 * kind. Every detail block is already in the document, rendered on the server;
 * this file only toggles the `hidden` attribute of one of them. It never
 * injects HTML, which is what keeps the escaping guarantee of the PHP side
 * intact.
 *
 * Event delegation on three containers instead of one listener per chip: with
 * the row ceiling at 2000 reservations there can be thousands of chips, and
 * the listener count must not grow with them.
 */

(function () {
  'use strict';

  var grid = document.querySelector('.myapi-cal');
  var details = document.querySelector('.myapi-cal-details');

  if (!grid || !details) {
    return;
  }

  // The panel currently open, or null. Keeping the reference is what
  // guarantees a second chip cannot leave two panels open at once.
  var open = null;

  function hide() {
    if (open) {
      open.setAttribute('hidden', 'hidden');
      open = null;
    }
  }

  function show(nid) {
    var panel = document.getElementById('myapi-cal-detail-' + nid);
    if (!panel) {
      return;
    }
    hide();
    panel.removeAttribute('hidden');
    open = panel;
  }

  /**
   * Climbs from the clicked node up to the container looking for a chip.
   *
   * Written by hand instead of with Element.closest() because Drupal 7 sites
   * still run on browsers that do not implement it.
   */
  function chipFrom(node) {
    while (node && node !== grid) {
      if (node.getAttribute && node.getAttribute('data-nid')) {
        return node;
      }
      node = node.parentNode;
    }
    return null;
  }

  grid.addEventListener('click', function (event) {
    var chip = chipFrom(event.target);
    if (chip) {
      show(chip.getAttribute('data-nid'));
    }
  });

  details.addEventListener('click', function (event) {
    // The backdrop IS the open panel element: a click landing on it, rather
    // than inside its box, closes. So does the X button. A click anywhere
    // inside the box does nothing, which is what lets the operator select the
    // email address.
    var target = event.target;
    var isClose = target.className && String(target.className).indexOf('myapi-cal-detail-close') !== -1;

    if (target === open || isClose) {
      hide();
    }
  });

  document.addEventListener('keydown', function (event) {
    // event.key on modern browsers, keyCode as the fallback for the old ones.
    if (event.key === 'Escape' || event.key === 'Esc' || event.keyCode === 27) {
      hide();
    }
  });
})();
