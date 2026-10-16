<?php

/**
 * @file
 * Seeds the service_category vocabulary with the marketplace catalogue.
 *
 * SPEC 77 creates the vocabulary and its two fields but deliberately leaves the
 * terms to the operator, so this is a one-off maintenance script, not module
 * code: it lives outside myapi.info and is never loaded by Drupal.
 *
 * Idempotent by field_category_code, which is the identifier the app hangs its
 * icons on — the tid is not stable across a re-import, the code is. A term
 * whose code is already in the vocabulary is left exactly as it is: its name,
 * description and icon may have been adjusted on the site, and this is not the
 * place to overrule that. Re-running the script after adding a row to the
 * catalogue below creates only that row.
 *
 * field_category_icon is deliberately left empty: the endpoint answers
 * icon_id: null / icon_url: null, and the app falls back to its local icon map
 * keyed by code. The operator uploads the images later from
 * admin/structure/taxonomy/service_category.
 *
 * Usage (on the server, from the Drupal root):
 *   drush php-script /path/to/seed-service-categories.php
 *
 * Dry run — prints what it would create and writes nothing:
 *   MYAPI_SEED_DRY_RUN=1 drush php-script /path/to/seed-service-categories.php
 */

/**
 * The catalogue: stable code, human name, short description.
 *
 * Codes are lowercase ASCII snake_case, no accents, 32 chars max
 * (field_category_code is a text(32)), following the eight terms already
 * loaded on the site. Order here is the order of creation and has no effect on
 * the API, which sorts alphabetically by name.
 */
$catalogue = [
  ['albanileria',         'Albañilería',               'Obra menor, muros, reparaciones'],
  ['carpinteria',         'Carpintería',               'Muebles, puertas, reparación'],
  ['cerrajeria',          'Cerrajería',                'Cerraduras, llaves, aperturas'],
  ['climatizacion',       'Climatización',             'A/C, ventilación, mantención'],
  ['cuidado_ninos',       'Cuidado infantil',          'Niñeras y apoyo escolar'],
  ['electricidad',        'Electricidad',              'Instalaciones y reparaciones'],
  ['jardineria',          'Jardinería',                'Poda, césped, mantenimiento'],
  ['limpieza',            'Limpieza',                  'Profunda, mudanza, vidrios'],
  ['mascotas',            'Mascotas',                  'Paseo, peluquería, cuidado'],
  ['otros',               'Otros servicios',           'Servicios que no calzan en el resto'],
  ['pintura',             'Pintura',                   'Interiores y exteriores'],
  ['plomeria',            'Plomería',                  'Fugas, grifería, desagües'],
];

$vocabulary_name = defined('MYAPI_SERVICES_CATEGORY_VOCABULARY')
  ? MYAPI_SERVICES_CATEGORY_VOCABULARY
  : 'service_category';

$dry_run = (bool) getenv('MYAPI_SEED_DRY_RUN');

$vocabulary = taxonomy_vocabulary_machine_name_load($vocabulary_name);
if (!$vocabulary) {
  drush_set_error('MYAPI_SEED', 'The vocabulary ' . $vocabulary_name . ' does not exist. Run "drush updb" first so the module installs it.');
  return;
}

// Index the terms already loaded by their code. taxonomy_get_tree() with
// $load_entities = TRUE returns fully loaded terms, so field_category_code
// comes along without a second query per term.
$existing = [];
$codeless = [];
foreach (taxonomy_get_tree($vocabulary->vid, 0, NULL, TRUE) as $term) {
  $code = isset($term->field_category_code[LANGUAGE_NONE][0]['value'])
    ? trim($term->field_category_code[LANGUAGE_NONE][0]['value'])
    : '';

  if ($code === '') {
    $codeless[] = $term->name . ' (tid ' . $term->tid . ')';
    continue;
  }

  $existing[$code] = $term->name;
}

drush_print('Vocabulary: ' . $vocabulary_name . ' (vid ' . $vocabulary->vid . ')');
drush_print('Terms with a code already loaded: ' . count($existing));
if ($dry_run) {
  drush_print('DRY RUN — nothing will be written.');
}
drush_print('');

$created = 0;
$skipped = 0;

foreach ($catalogue as $row) {
  list($code, $name, $description) = $row;

  if (isset($existing[$code])) {
    $skipped++;
    drush_print('  skip    ' . str_pad($code, 22) . ' already there as "' . $existing[$code] . '"');
    continue;
  }

  if ($dry_run) {
    $created++;
    drush_print('  would create ' . str_pad($code, 22) . $name);
    continue;
  }

  $term = (object) [
    'vid'         => $vocabulary->vid,
    'name'        => $name,
    'description' => $description,
    'format'      => 'plain_text',
    'weight'      => 0,
    // The code the app keys its icons on. field_category_icon is left out on
    // purpose: the category is born without an image and the endpoint answers
    // icon_id: null / icon_url: null.
    'field_category_code' => [
      LANGUAGE_NONE => [
        ['value' => $code],
      ],
    ],
  ];

  taxonomy_term_save($term);
  $existing[$code] = $name;
  $created++;
  drush_print('  created ' . str_pad($code, 22) . $name . ' (tid ' . $term->tid . ')');
}

drush_print('');
drush_print('Created: ' . $created . '   Skipped: ' . $skipped . '   Total in catalogue: ' . count($catalogue));

if ($codeless) {
  drush_print('');
  drush_print('WARNING — terms with an empty field_category_code, invisible to this script:');
  foreach ($codeless as $line) {
    drush_print('  - ' . $line);
  }
  drush_print('Give them a code by hand, or this script will create a duplicate on the next run.');
}
