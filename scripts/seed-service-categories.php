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
  // Repairs and building work.
  ['plomeria',            'Plomería',                  'Fugas, grifería, desagües'],
  ['electricidad',        'Electricidad',              'Instalaciones y reparaciones'],
  ['gasfiteria_gas',      'Gas y calefón',             'Instalación y mantención de gas'],
  ['climatizacion',       'Climatización',             'A/C, ventilación, mantención'],
  ['calefaccion',         'Calefacción',               'Estufas, radiadores, caldera'],
  ['carpinteria',         'Carpintería',               'Muebles, puertas, reparación'],
  ['cerrajeria',          'Cerrajería',                'Cerraduras, llaves, aperturas'],
  ['pintura',             'Pintura',                   'Interiores y exteriores'],
  ['albanileria',         'Albañilería',               'Obra menor, muros, reparaciones'],
  ['pisos_ceramica',      'Pisos y cerámica',          'Instalación y reparación de pisos'],
  ['techos',              'Techumbre',                 'Techos, canaletas, filtraciones'],
  ['impermeabilizacion',  'Impermeabilización',        'Sellado y tratamiento de humedad'],
  ['vidrieria',           'Vidriería',                 'Vidrios, espejos, termopaneles'],
  ['ventanas_puertas',    'Ventanas y puertas',        'PVC, aluminio, instalación'],
  ['tabiqueria_yeso',     'Tabiquería y yeso',         'Volcanita, cielos, molduras'],
  ['herreria_soldadura',  'Herrería y soldadura',      'Rejas, portones, estructuras'],
  ['multiservicios',      'Maestro multiservicios',    'Arreglos varios del hogar'],

  // Cleaning and sanitation.
  ['limpieza',            'Limpieza',                  'Profunda, mudanza, vidrios'],
  ['lavado_tapiz',        'Lavado de alfombras',       'Alfombras, sillones, tapiz'],
  ['sanitizacion',        'Sanitización',              'Desinfección de espacios'],
  ['control_plagas',      'Control de plagas',         'Fumigación y desratización'],
  ['destape_desagues',    'Destape de desagües',       'Desatascos y limpieza de ductos'],
  ['retiro_escombros',    'Retiro de escombros',       'Retiro de restos de obra'],
  ['reciclaje',           'Reciclaje y basura',        'Retiro y gestión de residuos'],

  // Outdoors.
  ['jardineria',          'Jardinería',                'Poda, césped, mantenimiento'],
  ['poda_arboles',        'Poda de árboles',           'Poda en altura y tala'],
  ['riego',               'Riego automático',          'Instalación y reparación de riego'],
  ['piscinas',            'Piscinas',                  'Mantención, químicos, reparación'],

  // Appliances and technology.
  ['electrodomesticos',   'Electrodomésticos',         'Reparación de línea blanca'],
  ['lavadoras',           'Lavadoras y secadoras',     'Reparación y mantención'],
  ['refrigeracion',       'Refrigeración',             'Refrigeradores y equipos de frío'],
  ['computacion',         'Computación',               'Soporte técnico y reparación'],
  ['redes_wifi',          'Redes y WiFi',              'Cableado, routers, cobertura'],
  ['tv_audio',            'TV, audio y antenas',       'Instalación y configuración'],

  // Security and building systems.
  ['camaras_cctv',        'Cámaras y CCTV',            'Instalación y mantención'],
  ['alarmas',             'Alarmas',                   'Sistemas de alarma y sensores'],
  ['citofonia',           'Citofonía',                 'Citófonos e intercomunicadores'],
  ['control_acceso',      'Control de acceso',         'Tarjetas, huella, barreras'],
  ['portones_automaticos','Portones automáticos',      'Motores, controles, reparación'],
  ['ascensores',          'Ascensores',                'Mantención y certificación'],
  ['extintores',          'Extintores y red seca',     'Recarga, certificación, red húmeda'],
  ['paneles_solares',     'Paneles solares',           'Instalación y mantención'],
  ['generadores',         'Grupos electrógenos',       'Instalación y mantención'],
  ['domotica',            'Domótica',                  'Automatización del hogar'],

  // Home and lifestyle.
  ['mudanzas',            'Mudanzas y fletes',         'Traslados y transporte'],
  ['tapiceria',           'Tapicería',                 'Restauración de muebles'],
  ['cortinas_persianas',  'Cortinas y persianas',      'Confección e instalación'],
  ['decoracion',          'Decoración e interiorismo', 'Asesoría y diseño de espacios'],
  ['mascotas',            'Mascotas',                  'Paseo, peluquería, cuidado'],
  ['belleza',             'Belleza a domicilio',       'Peluquería, manicura, estética'],
  ['masajes_spa',         'Masajes y bienestar',       'Masajes y terapias a domicilio'],
  ['salud_domicilio',     'Salud a domicilio',         'Enfermería, kinesiología, exámenes'],
  ['cuidado_mayores',     'Cuidado de adultos mayores','Acompañamiento y cuidados'],
  ['cuidado_ninos',       'Cuidado infantil',          'Niñeras y apoyo escolar'],
  ['clases_particulares', 'Clases particulares',       'Refuerzo escolar, idiomas, música'],
  ['entrenamiento',       'Entrenador personal',       'Entrenamiento y yoga a domicilio'],
  ['catering',            'Banquetería y catering',    'Comida para eventos'],
  ['eventos',             'Eventos y arriendo',        'Producción, mobiliario, sonido'],
  ['fotografia',          'Fotografía y video',        'Sesiones y cobertura de eventos'],
  ['lavado_autos',        'Lavado de autos',           'Lavado y detailing a domicilio'],
  ['mecanica_domicilio',  'Mecánica a domicilio',      'Mecánica menor y batería'],

  // Professional services.
  ['arquitectura',        'Arquitectura y proyectos',  'Proyectos, permisos, remodelación'],
  ['legal_contable',      'Legal y contable',          'Asesoría jurídica y contable'],
  ['otros',               'Otros servicios',           'Servicios que no calzan en el resto'],
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
