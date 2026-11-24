<?php

/**
 * @file
 * Dependent selectors of the back-office 'reservation' node form (SPEC 134).
 *
 * The form has four entityreference autocompletes — condominium, unit,
 * requester, area — and until this file they were independent: a 'backend'
 * user was offered the units, people and areas of the whole site whatever
 * condominium was picked, and nothing refused a reservation whose unit, area
 * or requester belonged somewhere else.
 *
 * THE CHAIN.
 *
 *   condominium ─┬─> unit ──> requester
 *                └─> area
 *
 * - unit and area are offered from the picked condominium only;
 * - requester is offered from the owner and occupants of the picked UNIT, not
 *   from the whole condominium;
 * - a selector stays disabled until its parent holds a value.
 *
 * THREE LAYERS, and all three stay:
 * 1. myapi_reservation_form_alter() points the three dependent selectors at
 *    this module's own autocomplete route and attaches
 *    js/myapi.reservation_form.js, which re-points, empties and
 *    enables/disables them as the parent changes.
 * 2. myapi_reservation_form_autocomplete() answers that route, scoped to the
 *    parent id that travels in the path.
 * 3. myapi_reservation_form_validate_scope() refuses the save when the four
 *    references do not agree. Shaping a form is not enforcing a rule: a
 *    hand-crafted POST, or a browser without JavaScript, never goes through
 *    layers 1 and 2.
 *
 * The role narrowing of SPEC 49/51 is untouched and still applies on top: the
 * node queries here carry the 'node_access' tag, so a building admin is only
 * ever offered units and areas of the condominiums assigned to them, and
 * myapi_building_admin_validate_reservation() keeps running after this file's
 * validation.
 *
 * WHAT IT DOES NOT DO. Availability. Overlap with another reservation, the
 * area's concurrent capacity, its opening window and a date in the past are
 * still checked on POST /api/v1/reservations only — the gap written down in
 * includes/myapi.reservation_admin.inc is exactly as open as it was.
 *
 * POST /api/v1/reservations is untouched: the endpoint saves its node
 * programmatically and never reaches a form alter or hook_node_validate().
 */

/* ---------------------------------------------------------------------------
 * Pure helpers.
 *
 * No database and no globals: plain values in, plain values out. The one
 * Drupal call is the module_load_include() of the shared request helpers.
 * ------------------------------------------------------------------------ */

/**
 * The four selectors of the chain, keyed by the short name the route and the
 * JavaScript use.
 *
 * 'parent' is the short name of the selector this one depends on, NULL for the
 * root. The order matters: a parent is always listed before its children,
 * which is the order the JavaScript walks them in.
 *
 * @return array
 *   Short name => ['field' => machine name, 'parent' => short name or NULL,
 *   'placeholder' => hint shown while the selector is disabled].
 */
function myapi_reservation_form_selectors() {
  return [
    'condominium' => [
      'field'       => 'field_condominium',
      'parent'      => NULL,
      'placeholder' => '',
    ],
    'unit' => [
      'field'       => 'field_unit',
      'parent'      => 'condominium',
      'placeholder' => '- Elige un condominio -',
    ],
    'area' => [
      'field'       => 'field_area',
      'parent'      => 'condominium',
      'placeholder' => '- Elige un condominio -',
    ],
    'requester' => [
      'field'       => 'field_requester',
      'parent'      => 'unit',
      'placeholder' => '- Elige una vivienda -',
    ],
  ];
}

/**
 * Whether a short name is one of the DEPENDENT selectors.
 *
 * The autocomplete route takes the short name from the URL, so this is its
 * allowlist: 'condominium' has no parent and is not served by this module.
 *
 * @param mixed $kind
 *   The value that arrived in the path.
 *
 * @return bool
 */
function myapi_reservation_form_is_dependent($kind) {
  $selectors = myapi_reservation_form_selectors();

  return is_string($kind) && isset($selectors[$kind]) && $selectors[$kind]['parent'] !== NULL;
}

/**
 * Reads a strictly positive integer id from a path argument.
 *
 * Delegates to myapi_is_positive_int_param() (SPEC 122), the module's single
 * definition of "a positive integer or nothing" — same reason
 * myapi_calendar_positive_int() does. The only thing added here is the answer
 * for "nothing": 0, which every caller reads as "no parent" and which is also
 * the parent id the form starts the dependent selectors at.
 *
 * @param mixed $value
 *   The raw path segment.
 *
 * @return int
 *   The id, or 0 for anything that is not a run of digits spelling a number
 *   >= 1: '0', '', '12abc', '-3' and an array all answer 0.
 */
function myapi_reservation_form_positive_int($value) {
  module_load_include('inc', 'myapi', 'includes/myapi.request');

  return myapi_is_positive_int_param($value) ? (int) $value : 0;
}

/**
 * Builds the key of one autocomplete suggestion: 'Label (id)'.
 *
 * It is the text Drupal writes into the input when the suggestion is picked,
 * and it has to be exactly what entityreference's own autocomplete produces,
 * because the element is still validated by
 * _entityreference_autocomplete_validate(), which reads the id back from the
 * trailing parentheses. Same normalisation and same quoting rule as
 * entityreference_autocomplete_callback_get_matches().
 *
 * @param string $label
 *   The raw (unescaped) label: a node title or a user name.
 * @param int $id
 *   The entity id.
 *
 * @return string
 */
function myapi_reservation_form_autocomplete_key($label, $id) {
  $key = trim(strip_tags((string) $label)) . ' (' . (int) $id . ')';
  $key = preg_replace('/\s\s+/', ' ', str_replace("\n", '', $key));

  // Names containing commas or quotes must be wrapped in quotes.
  if (strpos($key, ',') !== FALSE || strpos($key, '"') !== FALSE) {
    $key = '"' . str_replace('"', '""', $key) . '"';
  }

  return $key;
}

/**
 * Whether any of the four references differs from the stored reservation.
 *
 * The scope rule is only enforced on a reservation whose references are being
 * written: a new one, or an edit that touches them. An operator cancelling a
 * years-old reservation — the everyday edit of this bundle — must not be
 * blocked because its requester has since moved out of the unit.
 *
 * @param array $submitted
 *   Field machine name => submitted target id (or NULL).
 * @param array $stored
 *   Field machine name => stored target id (or NULL).
 *
 * @return bool
 */
function myapi_reservation_form_references_changed(array $submitted, array $stored) {
  foreach (myapi_reservation_form_selectors() as $selector) {
    $field = $selector['field'];
    $new = isset($submitted[$field]) ? (int) $submitted[$field] : 0;
    $old = isset($stored[$field]) ? (int) $stored[$field] : 0;

    if ($new !== $old) {
      return TRUE;
    }
  }

  return FALSE;
}

/**
 * Decides whether the four references of a reservation agree with each other.
 *
 * An empty end of a pair is skipped, not reported: the four fields are
 * required, so Drupal's own validation already names the empty one, and a
 * second message about the same input would only be noise.
 *
 * The messages are admin form strings: they do NOT go through myapi_t(), which
 * is the API translation catalogue.
 *
 * @param int|null $condominium
 *   The field_condominium target id.
 * @param int|null $unit
 *   The field_unit target id.
 * @param array $unit_condominiums
 *   The condominiums the unit belongs to (its field_condominio).
 * @param int|null $area
 *   The field_area target id.
 * @param array $area_condominiums
 *   The condominiums the area belongs to (its field_condominium).
 * @param int|null $requester
 *   The field_requester target id.
 * @param array $unit_members
 *   The uids of the owner and occupants of the unit.
 *
 * @return array
 *   List of errors, each ['field' => machine name, 'message' => text]. Empty
 *   when the reservation may be saved.
 */
function myapi_reservation_form_scope_errors($condominium, $unit, array $unit_condominiums, $area, array $area_condominiums, $requester, array $unit_members) {
  $errors = [];

  if ($condominium && $unit
    && !in_array((int) $condominium, array_map('intval', $unit_condominiums), TRUE)) {
    $errors[] = [
      'field'   => 'field_unit',
      'message' => 'La vivienda no pertenece al condominio seleccionado.',
    ];
  }

  if ($condominium && $area
    && !in_array((int) $condominium, array_map('intval', $area_condominiums), TRUE)) {
    $errors[] = [
      'field'   => 'field_area',
      'message' => 'El área no pertenece al condominio seleccionado.',
    ];
  }

  if ($unit && $requester
    && !in_array((int) $requester, array_map('intval', $unit_members), TRUE)) {
    $errors[] = [
      'field'   => 'field_requester',
      'message' => 'El solicitante debe ser propietario u ocupante de la vivienda seleccionada.',
    ];
  }

  return $errors;
}

/* ---------------------------------------------------------------------------
 * Drupal-facing helpers.
 *
 * They fetch from Drupal and delegate the decisions to the pure block. All of
 * them are exercised by tests/unit/ReservationFormTest.php under the stubs of
 * tests/unit/bootstrap.php. What no unit test can say is how the form behaves
 * in a browser — the JavaScript and the theme — which is the manual matrix of
 * the spec.
 * ------------------------------------------------------------------------ */

/**
 * The route the dependent selectors ask, without the kind and the parent id.
 *
 * @return string
 */
function myapi_reservation_form_autocomplete_base_path() {
  return 'admin/content/reservations/autocomplete';
}

/**
 * Wires the four selectors of the 'reservation' node form.
 *
 * Called from myapi_form_reservation_node_form_alter(). It applies to EVERY
 * user who reaches the form, create and edit alike.
 *
 * All or nothing: if any of the four is not the single-value entityreference
 * autocomplete this was written for — somebody switched a widget to a select
 * list from the field UI — the form is left exactly as it was. The validation
 * does not depend on this function, so the rule still holds; only the
 * convenience is lost.
 *
 * The dependent selectors are deliberately NOT rendered with '#disabled':
 * Drupal ignores the submitted value of a disabled element, which would throw
 * away what the operator picked. Enabling and disabling is the JavaScript's
 * job. Their autocomplete path always starts at parent 0 (which answers
 * nothing) for the same reason: the script re-points each one from the value
 * actually in its parent input, which is the only source that is right on a
 * new form, on an edit form and on a form re-rendered after a validation
 * error.
 *
 * @param array $form
 *   The node form, by reference.
 * @param array $form_state
 *   The form state, by reference. Unused; kept to mirror the hook.
 */
function myapi_reservation_form_alter(&$form, &$form_state) {
  $selectors = myapi_reservation_form_selectors();
  $elements = [];

  foreach ($selectors as $kind => $selector) {
    $field = $selector['field'];
    if (empty($form[$field]['#language'])) {
      return;
    }

    $language = $form[$field]['#language'];
    if (!isset($form[$field][$language][0]['target_id']['#autocomplete_path'])) {
      return;
    }

    $elements[$kind] = &$form[$field][$language][0]['target_id'];
  }

  $base = myapi_reservation_form_autocomplete_base_path();
  $parents = [];
  $placeholders = [];

  foreach ($selectors as $kind => $selector) {
    $elements[$kind]['#attributes']['data-myapi-reservation-selector'] = $kind;
    $parents[$kind] = $selector['parent'];

    if ($selector['parent'] !== NULL) {
      $elements[$kind]['#autocomplete_path'] = $base . '/' . $kind . '/0';
      $placeholders[$kind] = $selector['placeholder'];
    }
  }

  $form['#attached']['js'][] = drupal_get_path('module', 'myapi') . '/js/myapi.reservation_form.js';
  $form['#attached']['js'][] = [
    'type' => 'setting',
    'data' => [
      'myapiReservationForm' => [
        'basePath'     => url($base, ['absolute' => TRUE]),
        'parents'      => $parents,
        'placeholders' => $placeholders,
      ],
    ],
  ];
}

/**
 * Page callback of admin/content/reservations/autocomplete/%/%.
 *
 * Answers Drupal's autocomplete contract — a JSON object of
 * 'Label (id)' => HTML — and not the API envelope: this is asked by
 * misc/autocomplete.js on a back-office form, not by the app. The body is
 * printed by myapi_respond_autocomplete(), in includes/myapi.response.inc.
 *
 * Everything after the parent id is the search string; Drupal splits it on
 * '/', so the pieces are glued back the way entityreference's own callback
 * does.
 *
 * Never an error: an unknown kind, a parent of 0 or an empty string all answer
 * an empty object, which the widget shows as "no suggestions".
 *
 * @param string $kind
 *   'unit', 'area' or 'requester'.
 * @param string $parent
 *   The id the suggestions are scoped to: a condominium nid for 'unit' and
 *   'area', a unit nid for 'requester'.
 */
function myapi_reservation_form_autocomplete($kind = '', $parent = '') {
  $string = trim(implode('/', array_slice(func_get_args(), 2)));
  $parent_id = myapi_reservation_form_positive_int($parent);

  $matches = [];
  if (myapi_reservation_form_is_dependent($kind) && $parent_id && $string !== '') {
    $matches = $kind === 'requester'
      ? myapi_reservation_form_requester_matches($parent_id, $string)
      : myapi_reservation_form_node_matches($kind, $parent_id, $string);
  }

  module_load_include('inc', 'myapi', 'includes/myapi.response');
  myapi_respond_autocomplete($matches);
}

/**
 * Units or areas of one condominium whose title contains a string.
 *
 * Tagged 'node_access', so the role narrowing of SPEC 49 applies: a building
 * admin asking for a condominium that is not theirs gets nothing.
 *
 * Published nodes only, same criterion as the calendar's area filter and as
 * myapi_unit_fetch_unit_nids_by_condominium().
 *
 * NOT A REUSE of myapi_calendar_area_options() or
 * myapi_unit_fetch_unit_nids_by_condominium(), although the base of the query
 * is the same as theirs. Those answer the WHOLE list of a condominium for a
 * select; this one needs the title filter, the 10-row ceiling and the
 * 'node_access' tag inside the SQL, and neither of them can take those without
 * growing three optional parameters their own callers would never pass.
 *
 * @param string $kind
 *   'unit' or 'area'.
 * @param int $condominium_nid
 *   The condominium the suggestions belong to.
 * @param string $string
 *   What the operator typed.
 *
 * @return array
 *   'Title (nid)' => escaped HTML, at most 10, ordered by title.
 */
function myapi_reservation_form_node_matches($kind, $condominium_nid, $string) {
  // The two bundles name their condominium field differently: 'vivienda' is a
  // pre-existing site type (field_condominio), 'area' is this module's
  // (field_condominium).
  $type = $kind === 'unit' ? 'vivienda' : 'area';
  $field = $kind === 'unit' ? 'field_condominio' : 'field_condominium';

  $query = db_select('node', 'n')
    ->fields('n', ['nid', 'title'])
    ->condition('n.type', $type)
    ->condition('n.status', 1)
    ->condition('n.title', '%' . db_like($string) . '%', 'LIKE');
  $query->innerJoin('field_data_' . $field, 'fc', "fc.entity_id = n.nid AND fc.entity_type = 'node' AND fc.deleted = 0");
  $query->condition('fc.' . $field . '_target_id', $condominium_nid);
  $query->addTag('node_access');
  $query->orderBy('n.title');
  $query->range(0, 10);

  $matches = [];
  foreach ($query->execute()->fetchAllKeyed() as $nid => $title) {
    $matches[myapi_reservation_form_autocomplete_key($title, $nid)] = '<div class="reference-autocomplete">' . check_plain($title) . '</div>';
  }

  return $matches;
}

/**
 * Owner and occupants of one unit whose user name contains a string.
 *
 * The unit is resolved first through a 'node_access' query, so a building
 * admin asking for a unit of somebody else's condominium gets nothing — the
 * people of a unit are only as visible as the unit is.
 *
 * USER NAME ONLY, and no e-mail in the answer. The route is open to every role
 * holding a reservation permission, which on this site includes 'supervisor'
 * and 'pagos', and for those roles nothing narrows the query: matching or
 * printing u.mail would turn this route into a way of reading the e-mail of
 * the residents of any unit. It is the same data entityreference's own
 * autocomplete exposed before this file: a name and a uid.
 *
 * Active accounts only: a blocked user cannot use the app, so there is nobody
 * to book for.
 *
 * @param int $unit_nid
 *   The unit whose people are offered.
 * @param string $string
 *   What the operator typed.
 *
 * @return array
 *   'name (uid)' => escaped HTML, at most 10, ordered by name.
 */
function myapi_reservation_form_requester_matches($unit_nid, $string) {
  module_load_include('inc', 'myapi', 'includes/myapi.unit_access');

  $visible = db_select('node', 'n')
    ->fields('n', ['nid'])
    ->condition('n.nid', $unit_nid)
    ->condition('n.type', 'vivienda')
    ->addTag('node_access')
    ->execute()
    ->fetchField();
  if (!$visible) {
    return [];
  }

  $uids = myapi_unit_member_uids([$unit_nid], 'todos');
  if (!$uids) {
    return [];
  }

  $names = db_select('users', 'u')
    ->fields('u', ['uid', 'name'])
    ->condition('u.uid', $uids, 'IN')
    ->condition('u.status', 1)
    ->condition('u.name', '%' . db_like($string) . '%', 'LIKE')
    ->orderBy('u.name')
    ->range(0, 10)
    ->execute()
    ->fetchAllKeyed();

  $matches = [];
  foreach ($names as $uid => $name) {
    $matches[myapi_reservation_form_autocomplete_key($name, $uid)] = '<div class="reference-autocomplete">' . check_plain($name) . '</div>';
  }

  return $matches;
}

/**
 * The condominiums an 'area' node belongs to.
 *
 * @param int $area_nid
 *   The area nid.
 *
 * @return array
 *   Flat list of 'condominio' nids; one in practice, the field is single-value.
 */
function myapi_reservation_form_area_condominiums($area_nid) {
  return db_select('field_data_field_condominium', 'fc')
    ->fields('fc', ['field_condominium_target_id'])
    ->condition('fc.entity_id', $area_nid)
    ->condition('fc.entity_type', 'node')
    ->condition('fc.bundle', 'area')
    ->condition('fc.deleted', 0)
    ->execute()
    ->fetchCol();
}

/**
 * Refuses a reservation whose unit, area or requester do not belong together.
 *
 * Called from myapi_node_validate() for 'reservation' nodes, for EVERY user.
 * Reached from node_form_validate() only, so POST /api/v1/reservations is
 * untouched.
 *
 * On an edit it only runs when one of the four references changed — see
 * myapi_reservation_form_references_changed() for why.
 *
 * @param object $node
 *   The pseudo-node built from the submitted values.
 * @param array $form
 *   The node form; read only to name the offending input.
 * @param array $form_state
 *   The form state, by reference. Unused; kept to mirror the hook.
 */
function myapi_reservation_form_validate_scope($node, $form, &$form_state) {
  module_load_include('inc', 'myapi', 'includes/myapi.building_admin');
  module_load_include('inc', 'myapi', 'includes/myapi.unit_access');

  $submitted = [];
  foreach (myapi_reservation_form_selectors() as $selector) {
    $submitted[$selector['field']] = myapi_building_admin_field_target_id($node, $selector['field']);
  }

  if (!empty($node->nid)) {
    $original = node_load($node->nid);
    $stored = [];
    foreach (array_keys($submitted) as $field) {
      $stored[$field] = $original ? myapi_building_admin_field_target_id($original, $field) : NULL;
    }
    if (!myapi_reservation_form_references_changed($submitted, $stored)) {
      return;
    }
  }

  $unit = $submitted['field_unit'];
  $area = $submitted['field_area'];

  $errors = myapi_reservation_form_scope_errors(
    $submitted['field_condominium'],
    $unit,
    $unit ? myapi_units_condominium_nids([$unit]) : [],
    $area,
    $area ? myapi_reservation_form_area_condominiums($area) : [],
    $submitted['field_requester'],
    $unit ? myapi_unit_member_uids([$unit], 'todos') : []
  );

  foreach ($errors as $error) {
    // The full element path, so the input itself is flagged in red and not
    // just the message printed.
    $field = $error['field'];
    $language = isset($form[$field]['#language']) ? $form[$field]['#language'] : 'und';
    form_set_error($field . '][' . $language . '][0][target_id', $error['message']);
  }
}
