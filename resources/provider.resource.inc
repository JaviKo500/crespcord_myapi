<?php

/**
 * @file
 * Provider resource for the myapi module (SPEC 82).
 *
 * THIS FILE IS BORN WITH THE GALLERY AND NOTHING ELSE. There is no
 * GET /api/v1/providers and no GET /api/v1/providers/%: not the name, not the
 * phone, not the categories, not the rating, not the hourly rate or the tags of
 * SPEC 81. The provider listing is its own spec, and until it arrives this file
 * exposes two routes only:
 *
 *   - GET /api/v1/providers/%/gallery    — the list of gallery images.
 *   - GET /api/v1/providers/%/gallery/%  — the BYTES of one of them.
 *
 * That is a deliberate scope decision and not an unfinished file: whoever
 * arrives next should add the provider listing when its spec says so, not
 * improvise one here to make this look complete. Same warning SPEC 77 left on
 * the chat fields.
 *
 * Why a provider resource and not a 'provider_gallery' one: Regla 2 de
 * CLAUDE.md is one resource, one file, and the resource is the provider. A
 * separate gallery file would be a resource that is not a resource, and it
 * would have to be merged the day the listing lands.
 *
 * Since SPEC 82 field_gallery lives in private://, so nothing serves those
 * images without a decision: the 'url' of every item carries the absolute URL
 * of the download route below — never a sites/default/files or /system/files
 * one — and the app must send the Authorization header when loading an image
 * too. The back office reads the same files through hook_file_download()
 * (myapi_file_download() in myapi.module), and both consumers share
 * includes/myapi.provider_files.inc. See docs/provider-gallery.md.
 *
 * Like the other read resources of this module, the list reads the field_data_*
 * tables directly instead of going through node_load(): the response needs
 * three columns, and loading the node would fire the entity load hooks and
 * bring twelve fields along for them. The usual trade-off, documented in
 * docs/provider-gallery.md: the query assumes the current field storage.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.token');
module_load_include('inc', 'myapi', 'includes/myapi.auth');
// For MYAPI_SERVICES_PROVIDER_TYPE: the machine name is never written as a
// literal in this file.
module_load_include('inc', 'myapi', 'includes/myapi.services_common');
// For myapi_provider_file_provider_nid(), the ownership resolution
// hook_file_download() also calls.
module_load_include('inc', 'myapi', 'includes/myapi.provider_files');

/**
 * Dispatcher for /api/v1/providers/%/gallery (SPEC 82).
 *
 * Only GET is allowed; the gallery is loaded by the operator from the back
 * office, and there is no upload, delete or reorder path in this spec.
 *
 * The method is checked BEFORE any authentication, same criterion as every
 * other dispatcher of this module: a POST answers 405 without a token, because
 * the method is wrong whoever is asking.
 *
 * @param mixed $id  The provider nid from the route.
 */
function myapi_provider_gallery_dispatch($id = NULL) {
  if (myapi_request_method() === 'GET') {
    myapi_provider_gallery_list($id);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Dispatcher for /api/v1/providers/%/gallery/% (SPEC 82).
 *
 * Its own dispatcher and not a second case inside the one above: routing "list
 * or file" by the presence of $fid would turn two responsibilities into a chain
 * of isset(). Two routes, two dispatchers, one job each — the same split
 * myapi_claim_file_dispatch() made in SPEC 65.
 *
 * @param mixed $id   The provider nid from the route.
 * @param mixed $fid  The file id from the route.
 */
function myapi_provider_gallery_file_dispatch($id = NULL, $fid = NULL) {
  if (myapi_request_method() === 'GET') {
    myapi_provider_gallery_download($id, $fid);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * GET /api/v1/providers/%/gallery — the images of one provider (SPEC 82).
 *
 * Requires a valid Bearer token and nothing else: the marketplace is the same
 * for the whole site, exactly like /api/v1/service-categories, so no role,
 * condominium or unit is read. What IS required is that the node exists, is a
 * 'provider' and is PUBLISHED — an unpublished node is invisible to the app in
 * every other endpoint of this module.
 *
 * The expiry of field_license_expiry deliberately plays NO part here. A lapsed
 * provider disappears from the marketplace listings, but its bytes stay
 * readable: blocking them would break a carousel already open mid-session, and
 * the resident would see broken images with no explanation. The "active"
 * rule lives where it means something — the listings.
 *
 * A published provider with no images answers 200 with an empty list, never
 * 404: the provider exists and its gallery is simply empty, which is a
 * different fact from "there is no such provider".
 *
 * The order is the order of the Field API deltas, which is what the operator
 * drags in the form. No weight field and no date ordering.
 *
 * @param mixed $id  The provider nid from the route. Anything that is not a
 *                   positive integer answers 404, never a PHP error.
 */
function myapi_provider_gallery_list($id) {
  // 1. Token. Answers 401 on its own and never returns on failure.
  myapi_auth_require_access_token();

  // 2. The provider: existing, of the right type and published. A non-numeric
  // argument never reaches the database.
  if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id <= 0) {
    myapi_error('provider_not_found', 404);
  }
  $id = (int) $id;

  $exists = db_select('node', 'n')
    ->fields('n', ['nid'])
    ->condition('n.nid', $id)
    ->condition('n.type', MYAPI_SERVICES_PROVIDER_TYPE)
    ->condition('n.status', 1)
    ->range(0, 1)
    ->execute()
    ->fetchField();

  if ($exists === FALSE) {
    myapi_error('provider_not_found', 404);
  }

  // 3. The images, filenames included, in one query. INNER and not LEFT on
  // file_managed: a field row pointing at a file that no longer exists has no
  // filename to offer, and an item with a null one would be worse for the
  // client than no item at all.
  $query = db_select('field_data_field_gallery', 'fg');
  $query->condition('fg.entity_type', 'node');
  $query->condition('fg.deleted', 0);
  $query->condition('fg.entity_id', $id);
  $query->innerJoin('file_managed', 'fm', 'fm.fid = fg.field_gallery_fid');
  $query->addField('fm', 'fid', 'fid');
  $query->addField('fm', 'filename', 'filename');
  // The delta order is the carousel order the operator left in the form.
  $query->orderBy('fg.delta', 'ASC');

  $items = [];
  foreach ($query->execute() as $row) {
    $items[] = myapi_provider_build_image($row->fid, $row->filename, $id);
  }

  myapi_respond(['images' => $items], 200);
}

/**
 * GET /api/v1/providers/%/gallery/% — the bytes of one image (SPEC 82).
 *
 * The app's way into the private gallery. The back office's way is
 * hook_file_download() (myapi_file_download() in myapi.module), which asks a
 * node_access() question of the same shared include.
 *
 * LIKE GET /api/v1/claims/%/files/%, THIS DOES NOT ANSWER THE JSON ENVELOPE ON
 * SUCCESS. A binary file has no envelope to travel in, and base64 in a JSON
 * body would inflate it by a third and break every standard image loader.
 * Regla 4 de CLAUDE.md is broken here on purpose and only for the 200: the
 * errors below still go through myapi_error() unchanged. Written down in
 * docs/provider-gallery.md so it does not read as an oversight.
 *
 * The order of the four checks is the rule, not an implementation detail:
 * token → provider → membership → file on disk. Answering about the file
 * before the provider would let anyone probe fids under a nid they cannot see.
 *
 * A fid that exists but belongs to ANOTHER provider answers 404 file_not_found
 * and not 403, copied from SPEC 65: the reader already proved they see the
 * provider in the route, so nothing leaks by distinguishing the two errors —
 * but they are not told that fid exists somewhere else either. A fid of a
 * CLAIM, asked for through this route, lands in the same 404: the two families
 * of files do not cross.
 *
 * @param mixed $id   The provider nid from the route. Anything that is not a
 *                    positive integer answers 404, never a PHP error.
 * @param mixed $fid  The file id from the route, same treatment.
 */
function myapi_provider_gallery_download($id, $fid) {
  // 1. Token. Answers 401 on its own and never returns on failure.
  myapi_auth_require_access_token();

  // 2. The provider, with the same three conditions as the list above: the two
  // routes cannot disagree about which provider is visible.
  if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id <= 0) {
    myapi_error('provider_not_found', 404);
  }
  $id = (int) $id;

  $exists = db_select('node', 'n')
    ->fields('n', ['nid'])
    ->condition('n.nid', $id)
    ->condition('n.type', MYAPI_SERVICES_PROVIDER_TYPE)
    ->condition('n.status', 1)
    ->range(0, 1)
    ->execute()
    ->fetchField();

  if ($exists === FALSE) {
    myapi_error('provider_not_found', 404);
  }

  // 3. Membership, resolved by the include the back-office hook also calls: the
  // fid must hang from the field_gallery of THIS provider. A fid of another
  // provider, of a claim, or one that belongs to no provider at all answers 404
  // here rather than being served.
  if (!is_scalar($fid) || !ctype_digit((string) $fid) || (int) $fid <= 0) {
    myapi_error('file_not_found', 404);
  }
  $fid = (int) $fid;

  if (myapi_provider_file_provider_nid($fid) !== $id) {
    myapi_error('file_not_found', 404);
  }

  $file = file_load($fid);

  // 4. A file_managed row pointing at a file that is not on disk answers 404
  // too, not a 200 of zero bytes nor a PHP warning from fopen().
  if (!is_object($file) || !file_exists($file->uri)) {
    myapi_error('file_not_found', 404);
  }

  // The bytes. file_transfer() sends the headers, streams the file in chunks
  // and ends the request; nothing after this line runs.
  //
  // 'inline' because the app renders these images on screen and 'attachment'
  // would force a download in any web viewer; the original filename travels in
  // the header either way. 'private, no-store' because the whole point of this
  // endpoint is that the file is not cacheable by anything between the app and
  // Drupal.
  file_transfer($file->uri, [
    'Content-Type'        => $file->filemime,
    'Content-Length'      => $file->filesize,
    'Content-Disposition' => 'inline; filename="' . $file->filename . '"',
    'Cache-Control'       => 'private, no-store',
  ]);
}

/**
 * Shapes one gallery image into the response.
 *
 * The single place an image object is built, so the three keys and their types
 * are the same wherever an image appears. The columns arrive already joined
 * from file_managed; nothing here loads anything.
 *
 * The three keys are EXACTLY the ones myapi_claim_build_file() returns — id,
 * url, filename — so the app reuses the model and the widget it already has for
 * claim files. No alt, width, height or filesize: they are easy to add later
 * and nobody has asked for them.
 *
 * The url is NEVER the file's own location: field_gallery lives in private://,
 * and file_create_url() over it would produce /system/files/..., which answers
 * 403 to the app because it carries no Drupal session. What travels is the
 * absolute URL of the download route above.
 *
 * @param mixed $fid            file_managed.fid.
 * @param mixed $filename       file_managed.filename.
 * @param int   $provider_nid   The nid of the provider the image belongs to.
 *
 * @return array  ['id' => int, 'url' => string, 'filename' => string].
 */
function myapi_provider_build_image($fid, $filename, $provider_nid) {
  return [
    'id'       => (int) $fid,
    'url'      => url('api/v1/providers/' . (int) $provider_nid . '/gallery/' . (int) $fid, ['absolute' => TRUE]),
    'filename' => $filename,
  ];
}
