<?php

/**
 * @file
 * Seeds the catalogues the app needs before it can be used: banks, payment
 * methods and common areas.
 *
 * Service categories have their own script (seed-service-categories.php),
 * because their catalogue is fixed and lives in code. These three are data of
 * each installation, so they are read from a JSON file — see
 * seed-catalogs.example.json for the shape.
 *
 * A one-off maintenance script, not module code: it lives outside myapi.info
 * and is never loaded by Drupal.
 *
 * ALL OR NOTHING. Every row of the file is validated before the first write; a
 * single invalid row aborts the run with the full list of errors and nothing
 * is saved.
 *
 * IDEMPOTENT. A row that already exists is skipped and left exactly as it is
 * on the site (it may have been adjusted by hand, and this is not the place to
 * overrule that). Re-running the script after adding rows to the file creates
 * only the new rows. The identity of a row is:
 *   - bank:            its name, in the 'bancos' vocabulary;
 *   - payment method:  its name, in the 'metodos_pago' vocabulary;
 *   - area:            its title within its condominium.
 * Names are compared trimmed and case-insensitively.
 *
 * Usage (on the server, from the Drupal root):
 *   MYAPI_SEED_FILE=/path/to/catalogs.json drush php-script /path/to/seed-catalogs.php
 *
 * Dry run — validates the file and prints what it would create, writes nothing:
 *   MYAPI_SEED_DRY_RUN=1 MYAPI_SEED_FILE=/path/to/catalogs.json drush php-script /path/to/seed-catalogs.php
 */

$dry_run = (bool) getenv('MYAPI_SEED_DRY_RUN');
$file = (string) getenv('MYAPI_SEED_FILE');

if ($file === '' || !is_readable($file)) {
  drush_set_error('MYAPI_SEED', 'Set MYAPI_SEED_FILE to a readable JSON file (see seed-catalogs.example.json).');
  return;
}

$data = json_decode(file_get_contents($file), TRUE);
if (!is_array($data)) {
  drush_set_error('MYAPI_SEED', 'The file is not valid JSON: ' . json_last_error_msg());
  return;
}

$data += ['banks' => [], 'payment_methods' => [], 'areas' => []];
$errors = [];

/**
 * Normalizes a name for the idempotency comparison.
 */
function myapi_seed_key($name) {
  return mb_strtolower(trim((string) $name));
}

/**
 * Indexes the terms of a vocabulary by normalized name.
 *
 * @return array|NULL
 *   Map of normalized name => tid, or NULL when the vocabulary does not exist.
 */
function myapi_seed_terms_by_name($machine_name, &$vocabulary) {
  $vocabulary = taxonomy_vocabulary_machine_name_load($machine_name);
  if (!$vocabulary) {
    return NULL;
  }
  $map = [];
  foreach (taxonomy_get_tree($vocabulary->vid) as $term) {
    $map[myapi_seed_key($term->name)] = (int) $term->tid;
  }
  return $map;
}

/**
 * Returns the allowed_values keys of a list field, or [] when it does not exist.
 */
function myapi_seed_allowed_keys($field_name) {
  $info = field_info_field($field_name);
  return ($info && isset($info['settings']['allowed_values']))
    ? array_map('strval', array_keys($info['settings']['allowed_values']))
    : [];
}

/**
 * Reads an optional positive integer of a row, falling back to a default.
 */
function myapi_seed_int(array $row, $key, $default, $label, array &$errors) {
  if (!isset($row[$key])) {
    return $default;
  }
  if (!is_int($row[$key]) || $row[$key] <= 0) {
    $errors[] = $label . ': "' . $key . '" must be a positive integer.';
    return $default;
  }
  return $row[$key];
}

/* ---------------------------------------------------------------------------
 * 1. Validate everything.
 * ------------------------------------------------------------------------- */

// Banks.
$banks_vocabulary = NULL;
$existing_banks = myapi_seed_terms_by_name('bancos', $banks_vocabulary);
if ($data['banks'] && $existing_banks === NULL) {
  $errors[] = 'The vocabulary "bancos" does not exist.';
}

$banks = [];
$seen = [];
foreach ($data['banks'] as $i => $row) {
  $label = 'banks[' . $i . ']';
  $name = is_array($row) && isset($row['name']) ? trim((string) $row['name']) : '';
  if ($name === '') {
    $errors[] = $label . ': "name" is required.';
    continue;
  }
  if (isset($seen[myapi_seed_key($name)])) {
    $errors[] = $label . ': "' . $name . '" is repeated in the file.';
    continue;
  }
  $seen[myapi_seed_key($name)] = TRUE;
  $banks[] = ['name' => $name];
}

// Payment methods. type_method is what the app sends back as payment_method
// when it creates a payment, and POST /payments only accepts the keys of
// field_forma_de_pago's allowed_values — compared case-sensitively. A
// type_method outside that list would be offered by the app and then rejected
// with 422 invalid_payment_method on every payment.
$methods_vocabulary = NULL;
$existing_methods = myapi_seed_terms_by_name('metodos_pago', $methods_vocabulary);
if ($data['payment_methods'] && $existing_methods === NULL) {
  $errors[] = 'The vocabulary "metodos_pago" does not exist.';
}
$payment_keys = myapi_seed_allowed_keys('field_forma_de_pago');
if ($data['payment_methods'] && !$payment_keys) {
  $errors[] = 'The field "field_forma_de_pago" has no allowed_values: cannot validate type_method.';
}

$methods = [];
$seen = [];
foreach ($data['payment_methods'] as $i => $row) {
  $label = 'payment_methods[' . $i . ']';
  $row = is_array($row) ? $row : [];
  $name = isset($row['name']) ? trim((string) $row['name']) : '';
  $type = isset($row['type_method']) ? trim((string) $row['type_method']) : '';
  $description = isset($row['description']) ? trim((string) $row['description']) : '';

  if ($name === '') {
    $errors[] = $label . ': "name" is required.';
    continue;
  }
  if (isset($seen[myapi_seed_key($name)])) {
    $errors[] = $label . ': "' . $name . '" is repeated in the file.';
    continue;
  }
  $seen[myapi_seed_key($name)] = TRUE;

  if ($type === '') {
    $errors[] = $label . ': "type_method" is required (a term without it is hidden from the app).';
    continue;
  }
  if ($payment_keys && !in_array($type, $payment_keys, TRUE)) {
    $errors[] = $label . ': type_method "' . $type . '" is not a key of field_forma_de_pago. Allowed: ' . implode(', ', $payment_keys) . '.';
    continue;
  }

  $methods[] = ['name' => $name, 'type_method' => $type, 'description' => $description];
}

// Areas. The condominium is named by its title (nids differ between
// environments) or, when a title is ambiguous, by "condominium_nid".
$condominiums_by_title = [];
$condominium_nids = [];
if ($data['areas']) {
  $result = db_select('node', 'n')
    ->fields('n', ['nid', 'title'])
    ->condition('n.type', 'condominio')
    ->execute();
  foreach ($result as $record) {
    $condominiums_by_title[myapi_seed_key($record->title)][] = (int) $record->nid;
    $condominium_nids[(int) $record->nid] = $record->title;
  }
}

$status_keys = myapi_seed_allowed_keys('field_area_status');
$who_keys = myapi_seed_allowed_keys('field_who_can_reserve');
$category_keys = myapi_seed_allowed_keys('field_area_category');
$time_pattern = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';

$areas = [];
$seen = [];
foreach ($data['areas'] as $i => $row) {
  $label = 'areas[' . $i . ']';
  $row = is_array($row) ? $row : [];
  $title = isset($row['title']) ? trim((string) $row['title']) : '';
  if ($title === '') {
    $errors[] = $label . ': "title" is required.';
    continue;
  }
  $label .= ' "' . $title . '"';

  // Condominium.
  $cid = NULL;
  if (isset($row['condominium_nid'])) {
    if (is_int($row['condominium_nid']) && isset($condominium_nids[$row['condominium_nid']])) {
      $cid = $row['condominium_nid'];
    }
    else {
      $errors[] = $label . ': condominium_nid ' . json_encode($row['condominium_nid']) . ' is not a condominio node.';
      continue;
    }
  }
  else {
    $wanted = isset($row['condominium']) ? myapi_seed_key($row['condominium']) : '';
    $matches = $wanted !== '' && isset($condominiums_by_title[$wanted]) ? $condominiums_by_title[$wanted] : [];
    if (count($matches) !== 1) {
      $errors[] = $label . ': condominium "' . ($row['condominium'] ?? '') . '" '
        . (count($matches) ? 'matches ' . count($matches) . ' nodes (nids ' . implode(', ', $matches) . '); use "condominium_nid".' : 'not found.');
      continue;
    }
    $cid = $matches[0];
  }

  $identity = $cid . '|' . myapi_seed_key($title);
  if (isset($seen[$identity])) {
    $errors[] = $label . ': repeated in the file for the same condominium.';
    continue;
  }
  $seen[$identity] = TRUE;

  // Times. Required, HH:MM. A programmatic node_save() skips the form
  // validation of myapi_time_format_validate_fields(), so it is done here.
  $open = isset($row['open_time']) ? trim((string) $row['open_time']) : '';
  $close = isset($row['close_time']) ? trim((string) $row['close_time']) : '';
  if (!preg_match($time_pattern, $open) || !preg_match($time_pattern, $close)) {
    $errors[] = $label . ': open_time and close_time are required as "HH:MM" (00:00-23:59).';
    continue;
  }

  // Lists, with the same defaults as the field instances.
  $status = isset($row['status']) ? (string) $row['status'] : 'active';
  $who = isset($row['who_can_reserve']) ? (string) $row['who_can_reserve'] : 'both';
  $category = isset($row['category']) ? (string) $row['category'] : 'other';
  foreach (['status' => [$status, $status_keys], 'who_can_reserve' => [$who, $who_keys], 'category' => [$category, $category_keys]] as $key => $pair) {
    if (!in_array($pair[0], $pair[1], TRUE)) {
      $errors[] = $label . ': ' . $key . ' "' . $pair[0] . '" is not allowed. Allowed: ' . implode(', ', $pair[1]) . '.';
    }
  }

  // Integers, with the same defaults as the field instances.
  $slot = myapi_seed_int($row, 'slot_minutes', 60, $label, $errors);
  $max = myapi_seed_int($row, 'max_minutes', 120, $label, $errors);
  $cancel = myapi_seed_int($row, 'cancel_deadline_minutes', 120, $label, $errors);
  $concurrent = myapi_seed_int($row, 'concurrent_reservations', 1, $label, $errors);
  if ($max < $slot) {
    $errors[] = $label . ': max_minutes (' . $max . ') is shorter than slot_minutes (' . $slot . ').';
  }

  $areas[] = [
    'title'       => $title,
    'cid'         => $cid,
    'open'        => $open,
    'close'       => $close,
    'status'      => $status,
    'who'         => $who,
    'category'    => $category,
    'slot'        => $slot,
    'max'         => $max,
    'cancel'      => $cancel,
    'concurrent'  => $concurrent,
    'notes'       => isset($row['notes']) ? trim((string) $row['notes']) : '',
  ];
}

if ($errors) {
  drush_print('The file has ' . count($errors) . ' error(s). Nothing was written.');
  foreach ($errors as $error) {
    drush_print('  - ' . $error);
  }
  drush_set_error('MYAPI_SEED', 'Fix the file and run again.');
  return;
}

// Existing areas, indexed by condominium + title, for the idempotency check.
$existing_areas = [];
if ($areas) {
  $query = db_select('node', 'n');
  $query->join('field_data_field_condominium', 'c', "c.entity_id = n.nid AND c.entity_type = 'node'");
  $query->fields('n', ['title'])
    ->fields('c', ['field_condominium_target_id'])
    ->condition('n.type', 'area');
  foreach ($query->execute() as $record) {
    $existing_areas[$record->field_condominium_target_id . '|' . myapi_seed_key($record->title)] = TRUE;
  }
}

/* ---------------------------------------------------------------------------
 * 2. Write.
 * ------------------------------------------------------------------------- */

if ($dry_run) {
  drush_print('DRY RUN — the file is valid; nothing will be written.');
}
$created = 0;
$skipped = 0;

drush_print('');
drush_print('Banks (bancos):');
foreach ($banks as $bank) {
  if (isset($existing_banks[myapi_seed_key($bank['name'])])) {
    $skipped++;
    drush_print('  skip    ' . $bank['name']);
    continue;
  }
  $created++;
  if ($dry_run) {
    drush_print('  would create ' . $bank['name']);
    continue;
  }
  $term = (object) ['vid' => $banks_vocabulary->vid, 'name' => $bank['name'], 'format' => 'plain_text'];
  taxonomy_term_save($term);
  drush_print('  created ' . $bank['name'] . ' (tid ' . $term->tid . ')');
}

drush_print('');
drush_print('Payment methods (metodos_pago):');
foreach ($methods as $method) {
  if (isset($existing_methods[myapi_seed_key($method['name'])])) {
    $skipped++;
    drush_print('  skip    ' . $method['name']);
    continue;
  }
  $created++;
  if ($dry_run) {
    drush_print('  would create ' . $method['name'] . ' [' . $method['type_method'] . ']');
    continue;
  }
  $term = (object) [
    'vid'             => $methods_vocabulary->vid,
    'name'            => $method['name'],
    'description'     => $method['description'],
    'format'          => 'plain_text',
    'field_tipo_pago' => [LANGUAGE_NONE => [['value' => $method['type_method']]]],
  ];
  taxonomy_term_save($term);
  drush_print('  created ' . $method['name'] . ' [' . $method['type_method'] . '] (tid ' . $term->tid . ')');
}

drush_print('');
drush_print('Areas:');
foreach ($areas as $area) {
  $where = $condominium_nids[$area['cid']] . ' / ' . $area['title'];
  if (isset($existing_areas[$area['cid'] . '|' . myapi_seed_key($area['title'])])) {
    $skipped++;
    drush_print('  skip    ' . $where);
    continue;
  }
  $created++;
  if ($dry_run) {
    drush_print('  would create ' . $where . ' (' . $area['open'] . '-' . $area['close'] . ', ' . $area['status'] . ')');
    continue;
  }

  $node = (object) ['type' => 'area'];
  node_object_prepare($node);
  $node->title = $area['title'];
  $node->language = LANGUAGE_NONE;
  $node->uid = 1;
  $node->status = 1;
  $node->field_condominium[LANGUAGE_NONE][0]['target_id'] = $area['cid'];
  $node->field_open_time[LANGUAGE_NONE][0]['value'] = $area['open'];
  $node->field_close_time[LANGUAGE_NONE][0]['value'] = $area['close'];
  $node->field_area_status[LANGUAGE_NONE][0]['value'] = $area['status'];
  $node->field_who_can_reserve[LANGUAGE_NONE][0]['value'] = $area['who'];
  $node->field_area_category[LANGUAGE_NONE][0]['value'] = $area['category'];
  $node->field_slot_minutes[LANGUAGE_NONE][0]['value'] = $area['slot'];
  $node->field_max_minutes[LANGUAGE_NONE][0]['value'] = $area['max'];
  $node->field_cancel_deadline_minutes[LANGUAGE_NONE][0]['value'] = $area['cancel'];
  $node->field_concurrent_reservations[LANGUAGE_NONE][0]['value'] = $area['concurrent'];
  if ($area['notes'] !== '') {
    $node->field_area_notes[LANGUAGE_NONE][0] = ['value' => $area['notes'], 'format' => 'plain_text'];
  }
  node_save($node);
  drush_print('  created ' . $where . ' (nid ' . $node->nid . ')');
}

drush_print('');
drush_print(($dry_run ? 'Would create: ' : 'Created: ') . $created . '   Skipped (already there): ' . $skipped);
