<?php
/**
 * @file
 * Recreates on this site (production) the vocabularies, fields and allowed
 * values exported from another one (staging) by scripts/export-fields.php.
 *
 * VALIDATES EVERYTHING FIRST. Every item of the file is checked before the
 * first write; a single problem aborts the run with the full list and nothing
 * is created. Field API writes are DDL (CREATE TABLE) and cannot be rolled
 * back, so this check is what keeps a half-imported site from happening.
 *
 * NEVER CHANGES WHAT EXISTS. A vocabulary, field or instance that already
 * exists is skipped and left as it is. The only change to an existing field is
 * adding the allowed values it is missing; values are never removed or
 * relabelled. A field that exists with a different type is an error, not a
 * skip: the API would read it wrongly.
 *
 * IDEMPOTENT. Running it again after a successful run creates nothing.
 *
 * A one-off maintenance script, not module code: it lives outside myapi.info
 * and is never loaded by Drupal. PHP 5.6 compatible, like the module.
 *
 * Usage (on the target server, from the Drupal root):
 *   MYAPI_FIELDS_FILE=/tmp/myapi-fields.json drush php-script /path/to/import-fields.php
 *
 * Dry run — validates the file and prints what it would do, writes nothing:
 *   MYAPI_IMPORT_DRY_RUN=1 MYAPI_FIELDS_FILE=/tmp/myapi-fields.json drush php-script /path/to/import-fields.php
 */

$dry_run = (bool) getenv('MYAPI_IMPORT_DRY_RUN');
$file = (string) getenv('MYAPI_FIELDS_FILE');
if ($file === '' || !is_readable($file)) {
  drush_set_error('MYAPI_IMPORT', 'Set MYAPI_FIELDS_FILE to a readable JSON file produced by export-fields.php.');
  return;
}
$data = json_decode(file_get_contents($file), TRUE);
if (!is_array($data) || !isset($data['vocabularies'], $data['fields'], $data['instances'], $data['allowed_values'])) {
  drush_set_error('MYAPI_IMPORT', 'The file is not an export-fields.php export.');
  return;
}

$errors = [];
$plan = [
  'vocabularies'   => [],
  'fields'         => [],
  'instances'      => [],
  'allowed_values' => [],
];
$skipped = [];

/* ---------------------------------------------------------------------------
 * 1. Validate everything.
 * ------------------------------------------------------------------------- */

// Vocabularies.
$new_vocabularies = [];
foreach ($data['vocabularies'] as $vocabulary) {
  if (taxonomy_vocabulary_machine_name_load($vocabulary['machine_name'])) {
    $skipped[] = 'vocabulary ' . $vocabulary['machine_name'] . ' (already exists)';
    continue;
  }
  $plan['vocabularies'][] = $vocabulary;
  $new_vocabularies[$vocabulary['machine_name']] = TRUE;
}

/**
 * Whether a vocabulary exists here or is created by this run.
 */
function myapi_import_vocabulary_available($machine_name, array $new_vocabularies) {
  return isset($new_vocabularies[$machine_name]) || taxonomy_vocabulary_machine_name_load($machine_name);
}

// Fields.
$fields_by_name = [];
foreach ($data['fields'] as $field) {
  $name = $field['field_name'];
  $fields_by_name[$name] = $field;
  $existing = field_info_field($name);
  if ($existing) {
    if ($existing['type'] !== $field['type']) {
      $errors[] = 'Field ' . $name . ' exists here as "' . $existing['type'] . '", the export has "' . $field['type'] . '".';
    }
    else {
      $skipped[] = 'field ' . $name . ' (already exists)';
    }
    continue;
  }
  if (!module_exists($field['module'])) {
    $errors[] = 'Field ' . $name . ' needs the module "' . $field['module'] . '", which is not enabled.';
  }
  // References must point at vocabularies / content types that exist.
  if ($field['type'] === 'taxonomy_term_reference' && isset($field['settings']['allowed_values'])) {
    foreach ($field['settings']['allowed_values'] as $tree) {
      if (!empty($tree['vocabulary']) && !myapi_import_vocabulary_available($tree['vocabulary'], $new_vocabularies)) {
        $errors[] = 'Field ' . $name . ' references the vocabulary "' . $tree['vocabulary'] . '", which does not exist.';
      }
    }
  }
  if ($field['type'] === 'entityreference' && !empty($field['settings']['handler_settings']['target_bundles'])) {
    $target_type = $field['settings']['target_type'];
    foreach (array_keys($field['settings']['handler_settings']['target_bundles']) as $target_bundle) {
      $ok = $target_type === 'node' ? (bool) node_type_load($target_bundle)
        : ($target_type === 'taxonomy_term' ? myapi_import_vocabulary_available($target_bundle, $new_vocabularies) : TRUE);
      if (!$ok) {
        $errors[] = 'Field ' . $name . ' references the ' . $target_type . ' bundle "' . $target_bundle . '", which does not exist.';
      }
    }
  }
  if (isset($field['settings']['uri_scheme']) && $field['settings']['uri_scheme'] === 'private' && variable_get('file_private_path', '') === '') {
    $errors[] = 'Field ' . $name . ' stores private files, but file_private_path is not set (docs/produccion-checklist.md, section 2).';
  }
  $plan['fields'][] = $field;
}

// Instances.
foreach ($data['instances'] as $instance) {
  $label = $instance['entity_type'] . '.' . $instance['bundle'] . '.' . $instance['field_name'];
  if (!isset($fields_by_name[$instance['field_name']])) {
    $errors[] = 'Instance ' . $label . ' has no field definition in the file.';
    continue;
  }
  if (field_info_instance($instance['entity_type'], $instance['field_name'], $instance['bundle'])) {
    $skipped[] = 'instance ' . $label . ' (already exists)';
    continue;
  }
  if ($instance['entity_type'] === 'node') {
    $bundle_ok = (bool) node_type_load($instance['bundle']);
  }
  elseif ($instance['entity_type'] === 'taxonomy_term') {
    $bundle_ok = myapi_import_vocabulary_available($instance['bundle'], $new_vocabularies);
  }
  else {
    $bundle_ok = $instance['entity_type'] === 'user' && $instance['bundle'] === 'user';
  }
  if (!$bundle_ok) {
    $errors[] = 'Instance ' . $label . ': the bundle "' . $instance['bundle'] . '" does not exist.';
    continue;
  }
  $plan['instances'][] = $instance;
}

// Allowed values: only the keys this site is missing.
foreach ($data['allowed_values'] as $field_name => $values) {
  $existing = field_info_field($field_name);
  if (!$existing || !isset($existing['settings']['allowed_values'])) {
    $errors[] = 'List field ' . $field_name . ' does not exist here.';
    continue;
  }
  $missing = array_diff_key($values, $existing['settings']['allowed_values']);
  if (!$missing) {
    $skipped[] = 'allowed values of ' . $field_name . ' (none missing)';
    continue;
  }
  $plan['allowed_values'][$field_name] = $missing;
}

if ($errors) {
  drush_set_error('MYAPI_IMPORT', "Nothing imported. Fix these first:\n  - " . implode("\n  - ", $errors));
  return;
}

/* ---------------------------------------------------------------------------
 * 2. Report the plan.
 * ------------------------------------------------------------------------- */

drush_print(($dry_run ? 'DRY RUN, nothing is written. ' : '') . 'Source: ' . $data['source'] . ' (' . $data['exported_at'] . ')');
foreach ($plan['vocabularies'] as $vocabulary) {
  drush_print('  create vocabulary  ' . $vocabulary['machine_name']);
}
foreach ($plan['fields'] as $field) {
  drush_print('  create field       ' . $field['field_name'] . ' (' . $field['type'] . ')');
}
foreach ($plan['instances'] as $instance) {
  drush_print('  create instance    ' . $instance['entity_type'] . '.' . $instance['bundle'] . '.' . $instance['field_name']);
}
foreach ($plan['allowed_values'] as $field_name => $missing) {
  drush_print('  add allowed values ' . $field_name . ': ' . implode(', ', array_map('strval', array_keys($missing))));
}
foreach ($skipped as $line) {
  drush_print('  skip               ' . $line);
}
if (!array_filter($plan)) {
  drush_print('Nothing to do: the site already has everything in the file.');
  return;
}
if ($dry_run) {
  return;
}

/* ---------------------------------------------------------------------------
 * 3. Write, in dependency order.
 * ------------------------------------------------------------------------- */

foreach ($plan['vocabularies'] as $vocabulary) {
  taxonomy_vocabulary_save((object) $vocabulary);
}
foreach ($plan['fields'] as $field) {
  field_create_field($field);
}
foreach ($plan['instances'] as $instance) {
  field_create_instance($instance);
}
foreach ($plan['allowed_values'] as $field_name => $missing) {
  $field = field_info_field($field_name);
  $field['settings']['allowed_values'] += $missing;
  field_update_field($field);
}

field_info_cache_clear();
drush_print('Done.');
