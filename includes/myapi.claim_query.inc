<?php

/**
 * @file
 * Query helper for the claims listing page (SPEC 56).
 *
 * One function, one query: myapi_claims_list_rows() reads published 'reclamo'
 * nodes for admin/content/claims. It is NOT an API endpoint — nothing here
 * returns the JSON envelope, and no resource under resources/ calls into it.
 *
 * The query carries ->addTag('node_access'), which is what makes
 * myapi_building_admin_alter_node_query() narrow it to the assigned
 * condominiums of an 'administrador edificio' automatically. The condominium
 * scoping is NOT reimplemented here — Regla 3 de CLAUDE.md prohíbe duplicar
 * lógica compartida — and the tag has no effect at all on 'administrator' or
 * 'backend': the first guard of that alter function returns immediately for
 * any user without the role.
 */

/**
 * Lists published claims ('reclamo' nodes) for the admin listing page.
 *
 * A single query, LEFT JOINed to every column the listing shows: the
 * condominium (plus its title), the requester (plus their username), the
 * status, the claim type and the reception date. LEFT and not INNER because a
 * claim missing one of these optional-in-practice values must still appear in
 * the listing rather than disappear from it — the same criterion
 * myapi_reservation_calendar_rows() uses for field_area/field_unit.
 *
 * The five filters are all optional: a NULL argument means "no filter on
 * this column", matching the "garbage falls back to no filter" contract the
 * page callback already validates against (see
 * myapi_claims_list_filters() in includes/myapi.claims_admin.inc).
 *
 * Pagination is the caller's job: ->extend('PagerDefault')->limit(20) wires
 * the query into Drupal's own pager, which reads the 'page' GET parameter and
 * runs the COUNT(*) query on its own — nothing here touches $_GET directly,
 * keeping this function pure of request handling.
 *
 * @param int|null $condominium_id
 *   A 'condominio' nid to filter by, or NULL for no filter. The caller is
 *   responsible for checking it against the assigned condominiums of an
 *   'administrador edificio' before passing it in; this function trusts it as
 *   given, same as ->addTag('node_access') trusts the query alter to narrow
 *   the rows underneath regardless of what is asked for here.
 * @param string|null $status
 *   One of 'received'/'in_progress'/'resolved'/'closed'/'duplicated', or NULL
 *   for no filter.
 * @param string|null $claim_type
 *   'requirement' or 'claim', or NULL for no filter.
 * @param string|null $date_from
 *   'Y-m-d', inclusive lower bound on the reception date, or NULL.
 * @param string|null $date_to
 *   'Y-m-d', inclusive upper bound on the reception date, or NULL.
 *
 * @return SelectQueryInterface
 *   The built query, extended with PagerDefault and limited to 20 rows;
 *   ORDER BY nid DESC. The caller executes it and fetches the rows.
 */
function myapi_claims_list_rows($condominium_id = NULL, $status = NULL, $claim_type = NULL, $date_from = NULL, $date_to = NULL) {
  $query = db_select('node', 'n')
    ->fields('n', array('nid'))
    ->condition('n.type', 'reclamo')
    ->condition('n.status', 1);
  $query->addTag('node_access');

  $query->addField('n', 'title', 'subject');

  $query->leftJoin('field_data_field_condominium', 'fc', "fc.entity_id = n.nid AND fc.entity_type = 'node' AND fc.deleted = 0");
  $query->addField('fc', 'field_condominium_target_id', 'condominium_id');
  $query->leftJoin('node', 'nc', 'nc.nid = fc.field_condominium_target_id');
  $query->addField('nc', 'title', 'condominium_title');

  $query->leftJoin('field_data_field_status', 'fs', "fs.entity_id = n.nid AND fs.entity_type = 'node' AND fs.deleted = 0");
  $query->addField('fs', 'field_status_value', 'status');

  $query->leftJoin('field_data_field_claim_type', 'fct', "fct.entity_id = n.nid AND fct.entity_type = 'node' AND fct.deleted = 0");
  $query->addField('fct', 'field_claim_type_value', 'claim_type');

  // SUBSTR: field_reception_date is a day-granularity 'datetime' field (same
  // storage shape as field_date on 'reservation'), so only the first 10
  // characters carry the date.
  $query->leftJoin('field_data_field_reception_date', 'frd', "frd.entity_id = n.nid AND frd.entity_type = 'node' AND frd.deleted = 0");
  $query->addExpression('SUBSTR(frd.field_reception_date_value, 1, 10)', 'reception_date');

  $query->leftJoin('field_data_field_requester', 'fr', "fr.entity_id = n.nid AND fr.entity_type = 'node' AND fr.deleted = 0");
  $query->addField('fr', 'field_requester_target_id', 'requester_uid');
  $query->leftJoin('users', 'u', 'u.uid = fr.field_requester_target_id');
  $query->addField('u', 'name', 'requester_name');

  // Optional filters, applied on the left-joined columns so a claim with no
  // value in one of them is also excluded when filtering by it — the desired
  // reading of "show me this status" or "show me this condominium".
  if ($condominium_id !== NULL) {
    $query->condition('fc.field_condominium_target_id', $condominium_id);
  }
  if ($status !== NULL) {
    $query->condition('fs.field_status_value', $status);
  }
  if ($claim_type !== NULL) {
    $query->condition('fct.field_claim_type_value', $claim_type);
  }
  if ($date_from !== NULL) {
    $query->where('SUBSTR(frd.field_reception_date_value, 1, 10) >= :date_from', array(':date_from' => $date_from));
  }
  if ($date_to !== NULL) {
    $query->where('SUBSTR(frd.field_reception_date_value, 1, 10) <= :date_to', array(':date_to' => $date_to));
  }

  $query->orderBy('n.nid', 'DESC');

  return $query->extend('PagerDefault')->limit(20);
}
