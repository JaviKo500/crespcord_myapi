<?php
/**
 * @file
 * Exports, from a site where they already exist (staging), the definitions of
 * the vocabularies, fields and allowed values that myapi reads but does not
 * create. scripts/import-fields.php recreates them on another site
 * (production), so both sites end up with identical machine names, types and
 * settings instead of fields rebuilt by hand in the back office.
 *
 * What is exported is the list in $myapi_export below: the items that
 * docs/produccion-checklist.md (section 1) asks to create by hand. Add an item
 * there to carry it too.
 *
 * Read-only: it writes nothing to the site, only the JSON file.
 *
 * A one-off maintenance script, not module code: it lives outside myapi.info
 * and is never loaded by Drupal. PHP 5.6 compatible, like the module.
 *
 * Usage (on the source server, from the Drupal root):
 *   MYAPI_FIELDS_FILE=/tmp/myapi-fields.json drush php-script /path/to/export-fields.php
 */

$myapi_export = [
  // Vocabularies, created before the fields because a field may live on them.
  'vocabularies' => ['metodos_pago'],
  // [entity_type, bundle, field_name]: the field and its instance on that bundle.
  'instances' => [
    ['node', 'pagos', 'field_detalle'],
    ['node', 'pagos', 'field_archivo'],
    ['node', 'pagos', 'field_banco'],
    ['node', 'pagos', 'field_canal'],
    ['node', 'pagos', 'field_banco_emisor'],
    ['node', 'pagos', 'field_banco_destino'],
    ['node', 'pagos', 'field_comprobante_ocr'],
    ['node', 'alicuota_extra', 'field_detalle'],
    ['taxonomy_term', 'metodos_pago', 'field_tipo_pago'],
  ],
  // List fields whose allowed values are merged into the target's (never
  // removed): the target keeps its own values and gains the missing ones.
  'allowed_values' => ['field_estado_pago', 'field_forma_de_pago'],
];

$file = (string) getenv('MYAPI_FIELDS_FILE');
if ($file === '') {
  drush_set_error('MYAPI_EXPORT', 'Set MYAPI_FIELDS_FILE to the path of the JSON file to write.');
  return;
}

$out = [
  'source'         => $GLOBALS['base_url'],
  'exported_at'    => date('c'),
  'vocabularies'   => [],
  'fields'         => [],
  'instances'      => [],
  'allowed_values' => [],
];
$errors = [];

foreach ($myapi_export['vocabularies'] as $machine_name) {
  $vocabulary = taxonomy_vocabulary_machine_name_load($machine_name);
  if (!$vocabulary) {
    $errors[] = 'Vocabulary "' . $machine_name . '" does not exist here.';
    continue;
  }
  $out['vocabularies'][] = [
    'machine_name' => $vocabulary->machine_name,
    'name'         => $vocabulary->name,
    'description'  => (string) $vocabulary->description,
    'hierarchy'    => (int) $vocabulary->hierarchy,
    'module'       => $vocabulary->module,
    'weight'       => (int) $vocabulary->weight,
  ];
}

foreach ($myapi_export['instances'] as $item) {
  list($entity_type, $bundle, $field_name) = $item;
  $field = field_info_field($field_name);
  $instance = field_info_instance($entity_type, $field_name, $bundle);
  if (!$field || !$instance) {
    $errors[] = $entity_type . '.' . $bundle . '.' . $field_name . ' does not exist here.';
    continue;
  }
  // A field shared by two bundles (field_detalle) is exported once.
  if (!isset($out['fields'][$field_name])) {
    // Site-specific or derived keys are dropped: field_create_field() fills
    // them in on the target (id, storage, columns, bundles...).
    $out['fields'][$field_name] = array_intersect_key($field, array_flip([
      'field_name', 'type', 'module', 'cardinality', 'translatable', 'locked',
      'settings', 'indexes', 'foreign keys',
    ]));
  }
  $out['instances'][] = array_diff_key($instance, array_flip(['id', 'field_id', 'deleted']));
}

foreach ($myapi_export['allowed_values'] as $field_name) {
  $field = field_info_field($field_name);
  if (!$field || !isset($field['settings']['allowed_values'])) {
    $errors[] = 'List field "' . $field_name . '" does not exist here.';
    continue;
  }
  $out['allowed_values'][$field_name] = $field['settings']['allowed_values'];
}

if ($errors) {
  drush_set_error('MYAPI_EXPORT', "Nothing written:\n  - " . implode("\n  - ", $errors));
  return;
}

$out['fields'] = array_values($out['fields']);
$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (file_put_contents($file, $json . "\n") === FALSE) {
  drush_set_error('MYAPI_EXPORT', 'Cannot write ' . $file);
  return;
}

drush_print(sprintf(
  'Exported %d vocabularies, %d fields, %d instances and %d allowed-value lists to %s',
  count($out['vocabularies']), count($out['fields']), count($out['instances']),
  count($out['allowed_values']), $file
));
