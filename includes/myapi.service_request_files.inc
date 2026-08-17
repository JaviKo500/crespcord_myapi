<?php

/**
 * @file
 * Ownership and access rules for the files of a service request (SPEC 89).
 *
 * The third of its kind, and deliberately so: SPEC 65 solved this exact problem
 * for claims and SPEC 82 for provider galleries, so this file copies their
 * division of work rather than inventing a fourth one.
 *
 * Since SPEC 77 the two file fields of 'service_request' — field_images and
 * field_attachment — live in private://, so nothing serves them without an
 * explicit decision. That decision has TWO consumers, and this file is what
 * they share:
 *
 *   - myapi_file_download() in myapi.module, hook_file_download(): the
 *     back-office operator opening node/%/edit or a listing. Drupal hands it a
 *     URI and expects headers, -1 or NULL.
 *   - myapi_service_request_file_download() in
 *     resources/service_request.resource.inc,
 *     GET /api/v1/service-requests/%/files/%: the Flutter app with a Bearer
 *     token. It already knows the request it is asking about; what it needs
 *     from here is whether the fid really belongs to that request.
 *
 * The two ask different questions of the same fact — which request owns this
 * file — so the resolution lives here once, and neither consumer reimplements
 * it (Regla 3 de CLAUDE.md).
 *
 * The two consumers do NOT share the access rule, and that is not an oversight:
 * the app's rule is myapi_service_request_viewer() — the requester, or a
 * provider who may still bid — and the back office's is
 * myapi_service_request_file_access() below. They answer different questions
 * about different populations, the same split claims made.
 *
 * Why a new include and not includes/myapi.services_common.inc: that file's own
 * @file declares it a catalogue of pure helpers with no Field API and no
 * database, which is what makes it testable from tests/unit. Resolving the
 * owner of a fid is a query. Same reason claims did not put this in
 * includes/myapi.claims_common.inc.
 *
 * MAINTENANCE RULE — if a future spec adds another file field to
 * 'service_request', two things must happen or the file is born public, or
 * private and unreachable by both consumers: create the field with
 * 'settings' => ['uri_scheme' => 'private'], and add its name to the list
 * myapi_service_request_file_request_nid() walks below. Same note in
 * docs/service-request.md.
 */

/**
 * Resolves the service request that owns a file, or NULL when none does.
 *
 * A fid reaches a service request through two places:
 *
 *   field_images     of a 'service_request' → that nid
 *   field_attachment of a 'service_request' → that nid
 *
 * Anything else — a claim image, a provider gallery photo, a payment receipt in
 * private://comprobantes_pago, a file of another module — resolves to NULL, and
 * NULL is what keeps this module's hands off files that are not this bundle's.
 *
 * The bundle check in the query is what makes the two field names safe to
 * reuse: 'reclamo' and 'claim_transaction' carry field_images and
 * field_attachment too (SPEC 65), and without the INNER JOIN to node a claim's
 * photo would resolve to a service request nid that does not own it.
 *
 * Published or not is NOT asked here. A request unpublished by an operator
 * still has to render its thumbnails on node/%/edit, and the app's side never
 * reaches this function without having loaded the request first through
 * myapi_service_request_detail_row(), which does require status = 1.
 *
 * A file referenced by two requests — which no form in this module produces —
 * resolves to the lowest nid, and the membership check then answers 404 for the
 * other one: it fails closed.
 *
 * @param mixed $fid  file_managed.fid, as it arrives from a route argument or
 *                    from a database column (string or int).
 *
 * @return int|NULL  The nid of the owning service request, or NULL.
 */
function myapi_service_request_file_request_nid($fid) {
  $fid = (int) $fid;

  if ($fid <= 0) {
    return NULL;
  }

  module_load_include('inc', 'myapi', 'includes/myapi.services_common');

  // The two file fields of the service request data model. See the maintenance
  // rule above before adding a third one.
  foreach (['field_images', 'field_attachment'] as $field_name) {
    $query = db_select('field_data_' . $field_name, 'f');
    $query->condition('f.entity_type', 'node');
    $query->condition('f.deleted', 0);
    $query->condition('f.' . $field_name . '_fid', $fid);

    $query->innerJoin('node', 'n', 'n.nid = f.entity_id');
    $query->condition('n.type', MYAPI_SERVICES_REQUEST_TYPE);

    $query->addField('n', 'nid', 'nid');
    // Deterministic when the same file is referenced more than once, which is
    // the only case where more than one row can come back.
    $query->orderBy('n.nid', 'ASC');
    $query->range(0, 1);

    $nid = $query->execute()->fetchField();

    if ($nid !== FALSE && $nid !== NULL) {
      return (int) $nid;
    }
  }

  return NULL;
}

/**
 * Resolves a stream URI into its file_managed.fid, or NULL.
 *
 * Only hook_file_download() needs this: Drupal hands it a URI, while everything
 * else in this file — and the whole API — speaks fids. The endpoint gets its
 * fid from the route and never calls this.
 *
 * An unknown URI answers NULL, which is the normal outcome for most calls:
 * hook_file_download() fires for EVERY private file of the site, including
 * image-style derivatives (private://styles/...), which have no row in
 * file_managed at all.
 *
 * @param mixed $uri  The stream wrapper URI Drupal passed to the hook.
 *
 * @return int|NULL  The fid, or NULL when no file has that exact URI.
 */
function myapi_service_request_file_fid_by_uri($uri) {
  if (!is_string($uri) || $uri === '') {
    return NULL;
  }

  $fid = db_select('file_managed', 'fm')
    ->fields('fm', ['fid'])
    ->condition('fm.uri', $uri)
    ->range(0, 1)
    ->execute()
    ->fetchField();

  return $fid === FALSE ? NULL : (int) $fid;
}

/**
 * Whether a BACK-OFFICE account may read the files of a service request.
 *
 * This is the operator's rule, not the app's: the three administrative roles of
 * myapi_claims_admin_roles(), with 'administrador edificio' scoped to its
 * assigned condominiums. The app's rule is myapi_service_request_viewer() in
 * resources/service_request.resource.inc, and nothing here calls it — a
 * resident is not an operator, and a provider bidding on a request has no back
 * office at all.
 *
 * The role list is reused and not rewritten: those three names are the back
 * office of this module, they are already the answer claims files give
 * (includes/myapi.claims_files.inc), and a second literal list here would be a
 * second truth to keep in sync. The day services needs to diverge from claims,
 * this line is where the divergence gets written.
 *
 * The condominium scoping is NOT delegated to
 * myapi_building_admin_node_condominium(): 'service_request' is deliberately
 * absent from myapi_building_admin_condominium_map(), so that resolver answers
 * NULL for this bundle and NULL means "out of the rule" — which would hand a
 * building admin the photos of every request of the site. Adding the bundle to
 * that map would change hook_node_access() for it, which is a permission
 * change and out of SPEC 89's scope. So the condominium is read here from
 * field_condominium — required on this bundle since SPEC 77 — with the same
 * pure helper the map-driven path uses, and handed to the same decision
 * function, so the two cannot answer differently about the same pair.
 *
 * uid 1 is let in explicitly, same guard and same reason as
 * myapi_claims_file_access(): the superuser bypass lives inside user_access(),
 * which this never calls.
 *
 * @param mixed  $request_nid  The nid of the owning request, as returned by
 *                             myapi_service_request_file_request_nid().
 * @param object $account      The account to decide for. Anonymous (uid 0, no
 *                             roles) falls through to FALSE.
 *
 * @return bool  TRUE when the account may read that request's files.
 */
function myapi_service_request_file_access($request_nid, $account) {
  if (!is_object($account)) {
    return FALSE;
  }

  if ((int) $account->uid === 1) {
    return TRUE;
  }

  // $account->roles is rid => name in Drupal 7; names are compared, never rids.
  $roles = (!empty($account->roles) && is_array($account->roles))
    ? array_values($account->roles)
    : [];

  $admin_roles = myapi_claims_admin_roles();

  if (!array_intersect($roles, $admin_roles)) {
    return FALSE;
  }

  module_load_include('inc', 'myapi', 'includes/myapi.building_admin');

  // 'administrator' and 'backend' see every request of the site. Taken as "the
  // admin roles minus the scoped one" so the list stays declared in
  // myapi_claims_admin_roles() alone.
  if (array_intersect($roles, array_diff($admin_roles, [MYAPI_BUILDING_ADMIN_ROLE]))) {
    return TRUE;
  }

  // Only 'administrador edificio' left.
  $request = node_load((int) $request_nid);

  if (!is_object($request)) {
    return FALSE;
  }

  $decision = myapi_building_admin_access_decision(
    myapi_building_admin_field_target_id($request, 'field_condominium'),
    myapi_building_admin_condominium_ids($account)
  );

  return $decision !== 'deny';
}

/**
 * The service request half of hook_file_download() (SPEC 89).
 *
 * Returns exactly the three values Drupal 7 expects from the hook:
 *
 *   array  — headers. The URI is a request file AND the account may read it.
 *   -1     — a hard deny. The URI is a request file the account may NOT read.
 *   NULL   — not ours. Somebody else decides, and if nobody does, Drupal
 *            answers 403 on its own. This is the answer for claim files and
 *            provider galleries — which myapi_file_download() asks about
 *            FIRST — for payment receipts (private://comprobantes_pago), for
 *            image-style derivatives and for every private file of every other
 *            module.
 *
 * Cost on a foreign URI is one query: myapi_service_request_file_fid_by_uri()
 * misses, or the fid resolves to no request, and it is over. The hook fires for
 * every private file of the site and this is now the third owner asked, so that
 * path has to stay cheap.
 *
 * Image-style derivatives are handled by core, not here: image_style_deliver()
 * checks access on the SOURCE file, which this does resolve, and then writes
 * the derivative. private://styles/... never reaches this function with a row
 * in file_managed, so it answers NULL and core keeps the decision — which is
 * what keeps the back-office thumbnails working.
 *
 * @param mixed  $uri      The URI Drupal passed to the hook.
 * @param object $account  The account to decide for — the current user in
 *                         production.
 *
 * @return array|int|NULL  Headers, -1, or NULL.
 */
function myapi_service_request_file_download_headers($uri, $account) {
  $fid = myapi_service_request_file_fid_by_uri($uri);

  if ($fid === NULL) {
    return NULL;
  }

  $request_nid = myapi_service_request_file_request_nid($fid);

  if ($request_nid === NULL) {
    return NULL;
  }

  if (!myapi_service_request_file_access($request_nid, $account)) {
    return -1;
  }

  $file = file_load($fid);

  if (!is_object($file)) {
    return NULL;
  }

  // 'inline' and not 'attachment', same reason as claim files and provider
  // galleries: the back office renders these images on screen — the node/%/edit
  // thumbnails of field_images — and forcing a download on every one of them
  // would make the form unusable.
  return [
    'Content-Type'        => $file->filemime,
    'Content-Length'      => $file->filesize,
    'Content-Disposition' => 'inline; filename="' . $file->filename . '"',
  ];
}
