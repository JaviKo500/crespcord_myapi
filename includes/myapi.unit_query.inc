<?php

/**
 * @file
 * The unit ('vivienda') and condominium ('condominio') read queries (SPEC 127).
 *
 * These two functions used to live in resources/unit.resource.inc. They moved
 * here unchanged the moment a second consumer appeared: resources/bot.resource.inc
 * needs the same rows to answer which units a phone number belongs to, and a
 * resource may not call another resource's internal functions (rule 5 of
 * CLAUDE.md) any more than it may copy the query.
 *
 * Both are pure reads: no access check, no filtering by user. The caller
 * decides which nids it is allowed to ask for.
 */

/**
 * Fetches unit ('vivienda') data for a set of nids.
 *
 * Only published units are returned. Fields not set on a given unit come back
 * as NULL via the LEFT JOINs (e.g. a unit without an owner has owner_uid NULL).
 *
 * @param array $nids  'vivienda' nids to fetch.
 *
 * @return array  Rows keyed numerically, each with nid, name, category,
 *                area_m2, condominio_nid, owner_uid, saldo_actual.
 */
function myapi_unit_fetch_units($nids) {
  $query = db_select('node', 'n')
    ->fields('n', ['nid'])
    ->condition('n.type', 'vivienda')
    ->condition('n.status', 1)
    ->condition('n.nid', $nids, 'IN');

  $query->leftJoin('field_data_field_nombre_vivienda', 'fnv', "fnv.entity_id = n.nid AND fnv.entity_type = 'node' AND fnv.deleted = 0");
  $query->addField('fnv', 'field_nombre_vivienda_value', 'name');

  $query->leftJoin('field_data_field_categoria', 'fc', "fc.entity_id = n.nid AND fc.entity_type = 'node' AND fc.deleted = 0");
  $query->leftJoin('taxonomy_term_data', 'tcat', 'tcat.tid = fc.field_categoria_tid');
  $query->addField('tcat', 'name', 'category');

  $query->leftJoin('field_data_field_total_m2', 'fm2', "fm2.entity_id = n.nid AND fm2.entity_type = 'node' AND fm2.deleted = 0");
  $query->addField('fm2', 'field_total_m2_value', 'area_m2');

  $query->leftJoin('field_data_field_condominio', 'fcond', "fcond.entity_id = n.nid AND fcond.entity_type = 'node' AND fcond.deleted = 0");
  $query->addField('fcond', 'field_condominio_target_id', 'condominio_nid');

  $query->leftJoin('field_data_field_propietario', 'fown', "fown.entity_id = n.nid AND fown.entity_type = 'node' AND fown.deleted = 0");
  $query->addField('fown', 'field_propietario_target_id', 'owner_uid');

  $query->leftJoin('field_data_field_saldo_actual', 'fsa', "fsa.entity_id = n.nid AND fsa.entity_type = 'node' AND fsa.deleted = 0");
  $query->addField('fsa', 'field_saldo_actual_value', 'saldo_actual');

  return $query->execute()->fetchAll();
}

/**
 * Fetches published 'condominio' data (title and payment information) for a
 * set of nids.
 *
 * Nids not present in the returned map either do not exist or are
 * unpublished; callers use this to drop units whose condominium is not
 * visible.
 *
 * @param array $nids  'condominio' nids to fetch.
 *
 * @return array  Map of nid => object with 'title' and 'payment_information'
 *                (the raw field_informacion_pago value, NULL when unset), only
 *                for published condominiums.
 */
function myapi_unit_fetch_condominiums($nids) {
  if (empty($nids)) {
    return [];
  }

  $query = db_select('node', 'n')
    ->fields('n', ['nid', 'title'])
    ->condition('n.type', 'condominio')
    ->condition('n.status', 1)
    ->condition('n.nid', $nids, 'IN');

  $query->leftJoin('field_data_field_informacion_pago', 'fip', "fip.entity_id = n.nid AND fip.entity_type = 'node' AND fip.deleted = 0");
  $query->addField('fip', 'field_informacion_pago_value', 'payment_information');

  $result = $query->execute();

  $condominiums = [];
  foreach ($result as $condominium) {
    $condominiums[$condominium->nid] = $condominium;
  }

  return $condominiums;
}

/**
 * Fetches every published 'condominio' as a nid => title map (SPEC 128).
 *
 * No argument and no filter: there are around 150 of them, they fit in memory,
 * and the search of SPEC 128 wants all of them at once. Comparing the titles in
 * PHP — rather than with a LIKE in SQL — is what lets that search fold accents,
 * split the term into tokens and measure an edit distance without any of it
 * depending on the collation the database happens to be running.
 *
 * The sibling myapi_unit_fetch_condominiums() above stays for the case it was
 * written for: a known set of nids, with the payment information attached. This
 * one is the first phase of a search, and carries nothing but the title.
 *
 * @return array  Map of nid => title, for published condominiums only.
 */
function myapi_unit_fetch_all_condominiums() {
  $result = db_select('node', 'n')
    ->fields('n', ['nid', 'title'])
    ->condition('n.type', 'condominio')
    ->condition('n.status', 1)
    ->execute();

  $titles = [];
  foreach ($result as $condominium) {
    $titles[$condominium->nid] = $condominium->title;
  }

  return $titles;
}

/**
 * Fetches the nids of the published units that belong to a set of condominiums
 * (SPEC 128).
 *
 * This is what keeps the search of SPEC 128 off the 35.000-row unit table: it
 * narrows the candidates to the couple of hundred units of the condominiums
 * already resolved, and myapi_unit_fetch_units() above then returns the detail
 * of those and only those. Nothing about which fields a unit carries is
 * duplicated here — this decides WHICH nids to ask for, not what to bring back.
 *
 * An empty input returns an empty array without running a query: an IN () with
 * no values is both meaningless and, on the caller's side, the normal case when
 * no condominium matched the term.
 *
 * @param array $condominium_nids  'condominio' nids to look under.
 *
 * @return array  A flat list of 'vivienda' nids.
 */
function myapi_unit_fetch_unit_nids_by_condominium(array $condominium_nids) {
  if (empty($condominium_nids)) {
    return [];
  }

  $query = db_select('node', 'n')
    ->fields('n', ['nid'])
    ->condition('n.type', 'vivienda')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_condominio', 'fcond', "fcond.entity_id = n.nid AND fcond.entity_type = 'node' AND fcond.deleted = 0");
  $query->condition('fcond.field_condominio_target_id', $condominium_nids, 'IN');

  return $query->execute()->fetchCol();
}
