<?php

/**
 * @file
 * Bulletin resource for the myapi module.
 *
 * Lists published 'boletin' nodes (exposed as bulletins) that the authenticated
 * user is allowed to see, according to the bulletin's audience (the cross of
 * field_tipo_de_boletin x field_enviar_a). Read-only, paginated, with an
 * optional date-range filter on node.created. The visibility query is the
 * reverse of the notification fan-out in myapi_boletin_recipient_uids()
 * (spec 25): it exposes to the reader the same set of bulletins that were
 * notified to them. Mirrors payment.resource.inc in shape.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.token');
module_load_include('inc', 'myapi', 'includes/myapi.auth');
module_load_include('inc', 'myapi', 'includes/myapi.unit_access');

/**
 * Dispatcher for /api/v1/bulletins.
 *
 * Routes by HTTP method. Only GET is supported (list the bulletins visible to
 * the authenticated user); any other method returns 405. Authentication is
 * resolved inside myapi_bulletin_list(), not here.
 */
function myapi_bulletin_dispatch() {
  $method = myapi_request_method();
  if ($method === 'GET') {
    myapi_bulletin_list();
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Builds the reverse-audience visibility condition for the bulletin list query.
 *
 * Returns a db_or() that a caller attaches with $query->condition(...). It is
 * the inverse of the notification fan-out in myapi_boletin_recipient_uids()
 * (spec 25): a 'boletin' is visible to $uid when it matches ANY of the three
 * audience branches (the cross of field_tipo_de_boletin x field_enviar_a).
 *
 * The condition references three aliases the caller MUST join with these exact
 * names (see myapi_bulletin_count() / myapi_bulletin_fetch()):
 *   - ftipo : field_data_field_tipo_de_boletin (inner join; audience axis)
 *   - fenv  : field_data_field_enviar_a        (left join; role, may be NULL)
 *   - fcond : field_data_field_condominio      (left join; 'Condominio' branch)
 * and the outer node alias 'n', which the 'Personalizado' EXISTS sub-selects
 * correlate to (fp.entity_id = n.nid / fo.entity_id = n.nid).
 *
 * Every sub-condition is added only when its set/flag is non-empty, so a reader
 * with no units of a role never produces an "IN ()" (invalid SQL in D7). An
 * unknown field_tipo_de_boletin or field_enviar_a value matches no branch, so
 * the bulletin stays hidden (fail-safe, same criterion as the fan-out).
 *
 * The 'Personalizado' branch is always present: it does not depend on the
 * reader's unit sets but on whether $uid is referenced on the node itself, so
 * the returned db_or() is never empty.
 *
 * @param int   $uid              Authenticated reader uid.
 * @param array $owner_condos     Condominium nids where the reader is owner.
 * @param array $occupant_condos  Condominium nids where the reader is occupant.
 * @param array $member_condos    Condominium nids where owner OR occupant.
 * @param bool  $is_owner         Reader owns at least one unit.
 * @param bool  $is_occupant      Reader occupies at least one unit.
 * @param bool  $is_member        Reader owns OR occupies at least one unit.
 *
 * @return DatabaseCondition  A db_or() to pass to $query->condition().
 */
function myapi_bulletin_visibility_condition($uid, $owner_condos, $occupant_condos, $member_condos, $is_owner, $is_occupant, $is_member) {
  $or = db_or();

  // Branch General: field_tipo_de_boletin = 'General', visible when the reader
  // holds the role field_enviar_a demands. Each option is added only when its
  // flag is set, so the inner OR is never empty when the branch is included.
  $general = db_or();
  $general_has = FALSE;
  if ($is_owner) {
    $general->condition('fenv.field_enviar_a_value', 'Propietarios');
    $general_has = TRUE;
  }
  if ($is_occupant) {
    $general->condition('fenv.field_enviar_a_value', 'Ocupantes');
    $general_has = TRUE;
  }
  if ($is_member) {
    $general->condition('fenv.field_enviar_a_value', 'Todos');
    $general_has = TRUE;
  }
  if ($general_has) {
    $or->condition(db_and()
      ->condition('ftipo.field_tipo_de_boletin_value', 'General')
      ->condition($general));
  }

  // Branch Condominio: field_tipo_de_boletin = 'Condominio', visible when the
  // node's field_condominio is a condominium where the reader holds the role
  // field_enviar_a demands. Each option is added only when its condo set is
  // non-empty, to avoid an "IN ()".
  $condo = db_or();
  $condo_has = FALSE;
  if (!empty($owner_condos)) {
    $condo->condition(db_and()
      ->condition('fenv.field_enviar_a_value', 'Propietarios')
      ->condition('fcond.field_condominio_target_id', $owner_condos, 'IN'));
    $condo_has = TRUE;
  }
  if (!empty($occupant_condos)) {
    $condo->condition(db_and()
      ->condition('fenv.field_enviar_a_value', 'Ocupantes')
      ->condition('fcond.field_condominio_target_id', $occupant_condos, 'IN'));
    $condo_has = TRUE;
  }
  if (!empty($member_condos)) {
    $condo->condition(db_and()
      ->condition('fenv.field_enviar_a_value', 'Todos')
      ->condition('fcond.field_condominio_target_id', $member_condos, 'IN'));
    $condo_has = TRUE;
  }
  if ($condo_has) {
    $or->condition(db_and()
      ->condition('ftipo.field_tipo_de_boletin_value', 'Condominio')
      ->condition($condo));
  }

  // Branch Personalizado: field_tipo_de_boletin = 'Personalizado', visible when
  // $uid is referenced on the node itself (field_condominio is ignored).
  // Propietarios/Todos -> field_personalizar; Ocupantes/Todos -> field_ocupantes.
  // Correlated EXISTS sub-selects tie the membership row to the outer node.
  $owner_sub = db_select('field_data_field_personalizar', 'fp');
  $owner_sub->addExpression('1');
  $owner_sub->where('fp.entity_id = n.nid')
    ->condition('fp.entity_type', 'node')
    ->condition('fp.deleted', 0)
    ->condition('fp.field_personalizar_target_id', $uid);

  $occupant_sub = db_select('field_data_field_ocupantes', 'fo');
  $occupant_sub->addExpression('1');
  $occupant_sub->where('fo.entity_id = n.nid')
    ->condition('fo.entity_type', 'node')
    ->condition('fo.deleted', 0)
    ->condition('fo.field_ocupantes_target_id', $uid);

  $perso = db_or();
  $perso->condition(db_and()
    ->condition('fenv.field_enviar_a_value', ['Propietarios', 'Todos'], 'IN')
    ->exists($owner_sub));
  $perso->condition(db_and()
    ->condition('fenv.field_enviar_a_value', ['Ocupantes', 'Todos'], 'IN')
    ->exists($occupant_sub));

  $or->condition(db_and()
    ->condition('ftipo.field_tipo_de_boletin_value', 'Personalizado')
    ->condition($perso));

  return $or;
}

/**
 * Parses the optional date-range filter from the query string.
 *
 * Reads $_GET['date_from'] and $_GET['date_to'], each expected as an ISO
 * 'YYYY-MM-DD' string, and returns them as Unix timestamps to compare against
 * node.created. A bound is kept only when it matches that format and is a real
 * calendar date (myapi_bulletin_valid_date()); anything else is silently
 * ignored, exactly like page/limit/sort. Because created is an integer
 * timestamp, the lower bound is the site-local start of the day (00:00:00) and
 * the upper bound the end of the day (23:59:59), so the filter is inclusive of
 * the whole date given. If both bounds are present but inverted (from > to),
 * the whole filter is dropped. No 422 is ever raised here.
 *
 * @return array  ['from' => int|NULL, 'to' => int|NULL] Unix timestamps.
 */
function myapi_bulletin_parse_date_range() {
  $from_date = myapi_bulletin_valid_date(isset($_GET['date_from']) ? $_GET['date_from'] : NULL);
  $to_date = myapi_bulletin_valid_date(isset($_GET['date_to']) ? $_GET['date_to'] : NULL);

  $from = $from_date !== NULL ? strtotime($from_date . ' 00:00:00') : NULL;
  $to = $to_date !== NULL ? strtotime($to_date . ' 23:59:59') : NULL;

  // Inverted range: drop the whole filter so the endpoint responds as if no
  // range were given (same lax behaviour as payments).
  if ($from !== NULL && $to !== NULL && $from > $to) {
    return ['from' => NULL, 'to' => NULL];
  }

  return ['from' => $from, 'to' => $to];
}

/**
 * Validates a single 'YYYY-MM-DD' date string.
 *
 * @param mixed $value  Raw query-string value, or NULL when absent.
 *
 * @return string|NULL  The value when it is a real ISO calendar date,
 *                       NULL otherwise.
 */
function myapi_bulletin_valid_date($value) {
  if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
    return NULL;
  }
  if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
    return NULL;
  }
  return $value;
}

/**
 * Parses the optional 'condominium_id' filter from the query string.
 *
 * Reads $_GET['condominium_id'] and classifies it with three sentinels so the
 * caller (myapi_bulletin_list()) can tell "absent" from "malformed" from a
 * valid id, without extra parsing:
 *   - NULL  : absent (unset or empty string) -> no filter, spec-29 behaviour.
 *   - FALSE : present but malformed (not a positive integer) -> 422.
 *   - int   : a positive integer node id (the condominium nid) -> gate + narrow.
 *
 * Pure function, no side effects: it only reads $_GET and returns a value.
 * Existence of the condominium is NOT checked here (a non-existent id is treated
 * exactly like a foreign one by the membership gate, see the spec).
 *
 * @return int|FALSE|NULL  int id when valid, FALSE when malformed, NULL when
 *                         absent.
 */
function myapi_bulletin_parse_condominium_id() {
  if (!isset($_GET['condominium_id']) || $_GET['condominium_id'] === '') {
    return NULL;
  }
  $value = $_GET['condominium_id'];
  if (!ctype_digit((string) $value) || (int) $value <= 0) {
    return FALSE;
  }
  return (int) $value;
}

/**
 * Counts published 'boletin' nodes visible to the reader, unpaginated.
 *
 * Same base as myapi_bulletin_fetch(): published nodes of type 'boletin', with
 * the audience axis inner-joined and the role/condominium columns left-joined,
 * the reverse-visibility condition applied, and the optional node.created range.
 * The joins use the aliases myapi_bulletin_visibility_condition() expects
 * (ftipo/fenv/fcond, plus 'n'). All field tables involved here are single-value,
 * so there is one row per node and the count matches the fetched set.
 *
 * @param int      $uid   Authenticated reader uid.
 * @param array    $sets  Precomputed reader sets/flags (owner_condos,
 *                        occupant_condos, member_condos, is_owner, is_occupant,
 *                        is_member).
 * @param int|NULL $from  Inclusive lower bound as a Unix timestamp, or NULL.
 * @param int|NULL $to    Inclusive upper bound as a Unix timestamp, or NULL.
 *
 * @return int  Total number of matching bulletins.
 */
function myapi_bulletin_count($uid, $sets, $from = NULL, $to = NULL) {
  $query = db_select('node', 'n')
    ->condition('n.type', 'boletin')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_tipo_de_boletin', 'ftipo', "ftipo.entity_id = n.nid AND ftipo.entity_type = 'node' AND ftipo.deleted = 0");
  $query->leftJoin('field_data_field_enviar_a', 'fenv', "fenv.entity_id = n.nid AND fenv.entity_type = 'node' AND fenv.deleted = 0");
  $query->leftJoin('field_data_field_condominio', 'fcond', "fcond.entity_id = n.nid AND fcond.entity_type = 'node' AND fcond.deleted = 0");

  $query->condition(myapi_bulletin_visibility_condition(
    $uid,
    $sets['owner_condos'],
    $sets['occupant_condos'],
    $sets['member_condos'],
    $sets['is_owner'],
    $sets['is_occupant'],
    $sets['is_member']
  ));

  if ($from !== NULL) {
    $query->condition('n.created', $from, '>=');
  }
  if ($to !== NULL) {
    $query->condition('n.created', $to, '<=');
  }

  return (int) $query->countQuery()->execute()->fetchField();
}

/**
 * Fetches a page of published 'boletin' nodes visible to the reader.
 *
 * Same base and visibility rule as myapi_bulletin_count(); additionally selects
 * the exposed columns and left-joins field_mensaje (message) and field_adjunto
 * (file_id). Orders by node.created in the requested direction, with nid as a
 * deterministic tie-breaker so bulletins sharing the same created timestamp keep
 * a stable order across pages (SQL does not guarantee row order for ties).
 *
 * @param int      $uid    Authenticated reader uid.
 * @param array    $sets   Precomputed reader sets/flags (see
 *                         myapi_bulletin_count()).
 * @param int      $page   1-based page number.
 * @param int      $limit  Rows per page, already clamped to [1, 50].
 * @param string   $sort   'asc' or 'desc', applied to node.created.
 * @param int|NULL $from   Inclusive lower bound as a Unix timestamp, or NULL.
 * @param int|NULL $to     Inclusive upper bound as a Unix timestamp, or NULL.
 *
 * @return array  Rows keyed numerically, one per bulletin.
 */
function myapi_bulletin_fetch($uid, $sets, $page, $limit, $sort, $from = NULL, $to = NULL) {
  $query = db_select('node', 'n')
    ->fields('n', ['nid', 'title', 'created'])
    ->condition('n.type', 'boletin')
    ->condition('n.status', 1);

  $query->innerJoin('field_data_field_tipo_de_boletin', 'ftipo', "ftipo.entity_id = n.nid AND ftipo.entity_type = 'node' AND ftipo.deleted = 0");
  $query->addField('ftipo', 'field_tipo_de_boletin_value', 'type');

  $query->leftJoin('field_data_field_enviar_a', 'fenv', "fenv.entity_id = n.nid AND fenv.entity_type = 'node' AND fenv.deleted = 0");
  $query->addField('fenv', 'field_enviar_a_value', 'send_to');

  $query->leftJoin('field_data_field_condominio', 'fcond', "fcond.entity_id = n.nid AND fcond.entity_type = 'node' AND fcond.deleted = 0");
  $query->addField('fcond', 'field_condominio_target_id', 'condominium_id');

  $query->condition(myapi_bulletin_visibility_condition(
    $uid,
    $sets['owner_condos'],
    $sets['occupant_condos'],
    $sets['member_condos'],
    $sets['is_owner'],
    $sets['is_occupant'],
    $sets['is_member']
  ));

  if ($from !== NULL) {
    $query->condition('n.created', $from, '>=');
  }
  if ($to !== NULL) {
    $query->condition('n.created', $to, '<=');
  }

  $query->leftJoin('field_data_field_mensaje', 'fmsg', "fmsg.entity_id = n.nid AND fmsg.entity_type = 'node' AND fmsg.deleted = 0");
  $query->addField('fmsg', 'field_mensaje_value', 'message');

  $query->leftJoin('field_data_field_adjunto', 'fadj', "fadj.entity_id = n.nid AND fadj.entity_type = 'node' AND fadj.deleted = 0");
  $query->addField('fadj', 'field_adjunto_fid', 'file_id');

  $query->orderBy('n.created', $sort === 'asc' ? 'ASC' : 'DESC');
  $query->orderBy('n.nid', $sort === 'asc' ? 'ASC' : 'DESC');
  $query->range(($page - 1) * $limit, $limit);

  return $query->execute()->fetchAll();
}

/**
 * Maps one raw bulletin row into the response shape documented for
 * GET /api/v1/bulletins.
 *
 * 'id' and 'created_at' come from node and are always present, cast to int.
 * 'condominium_id' and 'file_id' are cast to int only when not NULL, and stay
 * NULL when the node has no row for that field. Text fields (title, type,
 * send_to, message) pass through as stored, with no business interpretation:
 * 'message' is the raw HTML of field_mensaje, exposed verbatim (the app is
 * responsible for safe rendering). 'send_to' and 'message' stay NULL when the
 * node has no row for that field ('type' never does, being an inner join).
 *
 * 'file_url' and 'file_mime' describe the field_adjunto attachment, which lives
 * in the public filesystem, so the URL is a directly fetchable link the app
 * renders natively (Image.network for images, a PDF viewer for documents). Both
 * are NULL when the bulletin has no attachment, and also when 'file_id' is set
 * but the managed file no longer resolves (missing from $files) — 'file_id'
 * still carries the stored fid in that case.
 *
 * @param object      $row   One row from myapi_bulletin_fetch().
 * @param object|null $file  The loaded managed file for $row->file_id, or NULL
 *                           when the bulletin has no attachment (or it no longer
 *                           resolves).
 *
 * @return array  Response item with all 10 mapped keys.
 */
function myapi_bulletin_build_item($row, $file = NULL) {
  return [
    'id'             => (int) $row->nid,
    'title'          => $row->title,
    'message'        => $row->message,
    'type'           => $row->type,
    'send_to'        => $row->send_to,
    'condominium_id' => $row->condominium_id !== NULL ? (int) $row->condominium_id : NULL,
    'file_id'        => $row->file_id !== NULL ? (int) $row->file_id : NULL,
    'file_url'       => $file ? file_create_url($file->uri) : NULL,
    'file_mime'      => $file ? $file->filemime : NULL,
    'created_at'     => (int) $row->created,
  ];
}

/**
 * Handles GET /api/v1/bulletins.
 *
 * Returns, for the authenticated user, a paginated list of published 'boletin'
 * nodes they are allowed to see according to the bulletin's audience (the cross
 * of field_tipo_de_boletin x field_enviar_a). There is no 403: the audience is
 * applied inside the query, so the user simply receives their own visible set
 * (empty when nothing matches). Supports page/limit/sort and an optional
 * date-range filter on node.created; total and total_pages reflect the already
 * filtered set.
 *
 * See the ordered plan in specs/bulletins/29-bulletins-list.md, step 8.
 */
function myapi_bulletin_list() {
  // 1. Authentication: valid Bearer access token, or 401.
  $row = myapi_auth_require_access_token();
  $uid = $row->uid;

  // 2. Precompute the reader's role sets once for this request. member_condos
  // is the union of the owner and occupant condominiums; is_member is owner OR
  // occupant. These feed the reverse-visibility condition.
  $owner_units = myapi_user_owned_unit_nids($uid);
  $occupant_units = myapi_user_occupied_unit_nids($uid);
  $owner_condos = myapi_units_condominium_nids($owner_units);
  $occupant_condos = myapi_units_condominium_nids($occupant_units);

  $is_owner = !empty($owner_units);
  $is_occupant = !empty($occupant_units);
  $sets = [
    'owner_condos'    => $owner_condos,
    'occupant_condos' => $occupant_condos,
    'member_condos'   => array_values(array_unique(array_merge($owner_condos, $occupant_condos))),
    'is_owner'        => $is_owner,
    'is_occupant'     => $is_occupant,
    'is_member'       => $is_owner || $is_occupant,
  ];

  // 2b. Optional condominium filter (spec 31). Parsed after the reader's sets
  // are known and before counting, so the gate protects count/fetch. Absent ->
  // no change (spec-29 behaviour); malformed -> 422; a valid id the reader does
  // not belong to (foreign or non-existent) -> 403. When the reader belongs, the
  // three condominium sets are narrowed to their intersection with {$condo}; the
  // role flags are left untouched, so General/Personalizado stay unfiltered.
  $condo = myapi_bulletin_parse_condominium_id();
  if ($condo === FALSE) {
    myapi_error('invalid_field', 422, ['@field' => 'condominium_id']);
  }
  if ($condo !== NULL) {
    if (!in_array($condo, $sets['member_condos'])) {
      myapi_error('condominium_access_denied', 403);
    }
    $sets['owner_condos']    = in_array($condo, $sets['owner_condos']) ? [$condo] : [];
    $sets['occupant_condos'] = in_array($condo, $sets['occupant_condos']) ? [$condo] : [];
    $sets['member_condos']   = [$condo];
  }

  // 3. Pagination/sort: invalid or absent values fall back to their defaults
  // silently (no 422), same as payments.
  $page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0
    ? (int) $_GET['page']
    : 1;

  $limit = isset($_GET['limit']) && ctype_digit((string) $_GET['limit']) && (int) $_GET['limit'] > 0
    ? max(1, min(50, (int) $_GET['limit']))
    : 20;

  $sort = isset($_GET['sort']) && in_array($_GET['sort'], ['asc', 'desc'], TRUE)
    ? $_GET['sort']
    : 'desc';

  // 4. Optional date-range filter on node.created (as Unix timestamps).
  $range = myapi_bulletin_parse_date_range();
  $from = $range['from'];
  $to = $range['to'];

  // 5. Count the visible, filtered set for the pagination block.
  $total = myapi_bulletin_count($uid, $sets, $from, $to);
  $total_pages = $total > 0 ? (int) ceil($total / $limit) : 0;

  // 6. Fetch the requested page and map each row to the response shape. The
  // attachments live in the public filesystem, so their managed files are
  // bulk-loaded once (one query for the whole page, not one per row) and each
  // row resolves its own file to expose file_url/file_mime.
  $rows = myapi_bulletin_fetch($uid, $sets, $page, $limit, $sort, $from, $to);
  $fids = [];
  foreach ($rows as $r) {
    if ($r->file_id !== NULL) {
      $fids[] = $r->file_id;
    }
  }
  $files = $fids ? file_load_multiple($fids) : [];
  $bulletins = array_map(function ($row) use ($files) {
    $file = ($row->file_id !== NULL && isset($files[$row->file_id])) ? $files[$row->file_id] : NULL;
    return myapi_bulletin_build_item($row, $file);
  }, $rows);

  // 7. Respond 200 (read-only endpoint, no message).
  myapi_respond([
    'bulletins' => $bulletins,
    'pagination' => [
      'total'       => (int) $total,
      'page'        => $page,
      'limit'       => $limit,
      'total_pages' => $total_pages,
    ],
  ], 200);
}
