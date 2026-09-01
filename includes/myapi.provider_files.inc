<?php

/**
 * @file
 * Ownership and access rules for the gallery files of a provider (SPEC 82).
 *
 * The twin of includes/myapi.claims_files.inc, and deliberately so: SPEC 65
 * solved this exact problem for claims, and this file copies its division of
 * work rather than inventing a second one.
 *
 * Since SPEC 82 the only file field of 'provider' — field_gallery — lives in
 * private://, so nothing serves it without an explicit decision. That decision
 * has TWO consumers, and this file is what they share:
 *
 *   - myapi_file_download() in myapi.module, hook_file_download(): the
 *     back-office operator opening node/%/edit or the provider listing. Drupal
 *     hands it a URI and expects headers, -1 or NULL.
 *   - myapi_provider_gallery_download() in resources/provider.resource.inc,
 *     GET /api/v1/providers/%/gallery/%: the Flutter app with a Bearer token.
 *     It already knows the provider it is asking about; what it needs from here
 *     is whether the fid really belongs to that provider's gallery.
 *
 * The two ask different questions of the same fact — which provider owns this
 * file — so the resolution lives here once, and neither consumer reimplements
 * it (Regla 3 de CLAUDE.md).
 *
 * Why a new include and not includes/myapi.services_common.inc: that file's own
 * @file declares it a catalogue of pure helpers with no Field API and no
 * database, which is what makes it testable from tests/unit. Resolving the
 * owner of a fid is a query. Same reason claims did not put this in
 * includes/myapi.claims_common.inc.
 *
 * MAINTENANCE RULE — if a future spec adds another file field to 'provider',
 * two things must happen or the file is born public, or private and unreachable
 * by both consumers: create the field with
 * 'settings' => ['uri_scheme' => 'private'], and add its name to the list
 * myapi_provider_file_provider_nid() walks below. Same note in
 * docs/services-install.md.
 */

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
function myapi_provider_file_fid_by_uri($uri) {
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
 * Resolves the provider that owns a file, or NULL when no provider does.
 *
 * A fid reaches a provider through one place only:
 *
 *   field_gallery of a 'provider' → that nid
 *
 * Anything else — a claim image, a payment receipt in
 * private://comprobantes_pago, an area photo, a file of another module —
 * resolves to NULL, and NULL is what keeps this module's hands off files that
 * are not its own.
 *
 * The bundle is checked in the query and not assumed from the field: a future
 * spec could attach field_gallery to another content type, and that file would
 * not be a provider's.
 *
 * A file referenced by two providers — which no form in this module produces —
 * resolves to the lowest nid, and the membership check then answers 404 for the
 * other one: it fails closed.
 *
 * @param mixed $fid  file_managed.fid, as it arrives from a route argument or
 *                    from a database column (string or int).
 *
 * @return int|NULL  The nid of the owning provider, or NULL.
 */
function myapi_provider_file_provider_nid($fid) {
  $fid = (int) $fid;

  if ($fid <= 0) {
    return NULL;
  }

  module_load_include('inc', 'myapi', 'includes/myapi.services_common');

  // The only file field of the provider data model. See the maintenance rule
  // above before adding a second one.
  foreach (['field_gallery'] as $field_name) {
    $query = db_select('field_data_' . $field_name, 'f');
    $query->condition('f.entity_type', 'node');
    $query->condition('f.deleted', 0);
    $query->condition('f.' . $field_name . '_fid', $fid);

    $query->innerJoin('node', 'n', 'n.nid = f.entity_id');
    $query->condition('n.type', MYAPI_SERVICES_PROVIDER_TYPE);

    $query->addField('n', 'nid', 'nid');
    // Deterministic when the same file is referenced more than once, which is
    // the only case where more than one row can come back.
    $query->orderBy('n.nid', 'ASC');
    $query->range(0, 1);

    $nid = $query->execute()->fetchField();

    if ($nid !== FALSE) {
      return (int) $nid;
    }
  }

  return NULL;
}

/**
 * The provider half of hook_file_download() (SPEC 82).
 *
 * Returns exactly the three values Drupal 7 expects from the hook:
 *
 *   array  — headers. The URI is a gallery file AND the account may read it.
 *   -1     — a hard deny. The URI is a gallery file the account may NOT read.
 *   NULL   — not ours. Somebody else decides, and if nobody does, Drupal
 *            answers 403 on its own. This is the answer for claim files — which
 *            myapi_file_download() asks about FIRST — for payment receipts
 *            (private://comprobantes_pago), for image-style derivatives and for
 *            every private file of every other module.
 *
 * The access decision delegates to node_access('view', $node) and writes no
 * role catalogue of its own. myapi_node_access() (SPEC 78) already decides who
 * sees a 'provider' node: 'administrator' and 'backend' by global permission,
 * and the 'proveedor' role scoped to its own ficha. A second catalogue here
 * would be two truths to keep in sync, and the first time they diverged the
 * symptom would be an operator who sees the ficha but not its images.
 *
 * Cost on a foreign URI is one query: myapi_provider_file_fid_by_uri() misses,
 * or the fid resolves to no provider, and it is over. The hook fires for every
 * private file of the site, so that path has to stay cheap.
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
function myapi_provider_file_download_headers($uri, $account) {
  $fid = myapi_provider_file_fid_by_uri($uri);

  if ($fid === NULL) {
    return NULL;
  }

  $provider_nid = myapi_provider_file_provider_nid($fid);

  if ($provider_nid === NULL) {
    return NULL;
  }

  $provider = node_load($provider_nid);

  if (!is_object($provider)) {
    return NULL;
  }

  if (!node_access('view', $provider, $account)) {
    return -1;
  }

  $file = file_load($fid);

  if (!is_object($file)) {
    return NULL;
  }

  // The same header block the API endpoints send, from the same function: a
  // photo travels 'inline' — the back office renders these on screen, and
  // forcing a download on every node/%/edit thumbnail would make the forms
  // unusable — while a type that could carry script never does. One decision,
  // one place, both audiences.
  module_load_include('inc', 'myapi', 'includes/myapi.file_download');

  return myapi_file_download_headers($file);
}
