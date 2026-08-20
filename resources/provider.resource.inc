<?php

/**
 * @file
 * Provider resource for the myapi module (SPEC 82, SPEC 83, SPEC 84).
 *
 * Four routes, all read-only and all authenticated:
 *
 *   - GET /api/v1/providers               — the marketplace listing (SPEC 83).
 *   - GET /api/v1/providers/%             — one provider's detail (SPEC 84).
 *   - GET /api/v1/providers/%/gallery     — the list of gallery images (82).
 *   - GET /api/v1/providers/%/gallery/%   — the BYTES of one of them (82).
 *
 * SPEC 82 created this file with the gallery alone and announced the listing
 * here; SPEC 83 is that listing, and it lands in this file rather than a
 * provider_list one because Regla 2 de CLAUDE.md is one resource, one file, and
 * the resource is the provider. SPEC 83 in turn announced the detail — the
 * phone, the address, field_services_desc, the tags of SPEC 81, the full
 * gallery and the ratings — which is what SPEC 84 is.
 *
 * Nothing writes: a provider is created, edited and suspended by the operator
 * from the back office (SPEC 77/78).
 *
 * Since SPEC 82 field_gallery lives in private://, so nothing serves those
 * images without a decision: the 'url' of every item carries the absolute URL
 * of the download route below — never a sites/default/files or /system/files
 * one — and the app must send the Authorization header when loading an image
 * too. The back office reads the same files through hook_file_download()
 * (myapi_file_download() in myapi.module), and both consumers share
 * includes/myapi.provider_files.inc. See docs/provider-gallery.md.
 *
 * Like the other read resources of this module, every query here reads the
 * field_data_* tables directly instead of going through node_load(): the
 * listing answers seven keys per provider, and node_load() of twenty of them
 * would fire the entity load hooks and drag twelve fields along for them. The
 * usual trade-off, documented in docs/provider-gallery.md and docs/provider.md:
 * the queries assume the current field storage.
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
// For myapi_provider_apply_active_conditions(): the SQL half of "an active
// provider", shared with the category counts of SPEC 79 (SPEC 83).
module_load_include('inc', 'myapi', 'includes/myapi.provider_query');
// For myapi_text_to_plain(): flattens field_address and field_services_desc
// (SPEC 84), both text_long with a rich editor behind them.
module_load_include('inc', 'myapi', 'includes/myapi.text');
// For myapi_user_fetch_profile_fields(): resolves the abbreviated author name
// of a rating (SPEC 84), same helper myapi.claim_notification.inc uses for
// requester_name.
module_load_include('inc', 'myapi', 'includes/myapi.user');

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

  // 3. The images, filenames included.
  $items = [];
  foreach (myapi_provider_gallery_images($id) as $row) {
    $items[] = myapi_provider_build_image($row->fid, $row->filename, $id);
  }

  myapi_respond(['images' => $items], 200);
}

/**
 * The gallery rows of one provider (SPEC 82, extracted by SPEC 84).
 *
 * Extracted out of myapi_provider_gallery_list() so the gallery listing and
 * the provider detail (SPEC 84) share the exact same query and can never
 * disagree about which images a provider has — the same criterion SPEC 83
 * used when the "active" rule gained a second consumer.
 *
 * INNER and not LEFT on file_managed: a field row pointing at a file that no
 * longer exists has no filename to offer, and an item with a null one would
 * be worse for the client than no item at all. The delta order is the
 * carousel order the operator left in the form.
 *
 * Does not check whether the provider exists or is published — callers do
 * that themselves before reaching here, same as before this extraction.
 *
 * @param int $id  The provider nid.
 *
 * @return \Traversable  Rows with 'fid' and 'filename', ready for
 *                        myapi_provider_build_image().
 */
function myapi_provider_gallery_images($id) {
  $query = db_select('field_data_field_gallery', 'fg');
  $query->condition('fg.entity_type', 'node');
  $query->condition('fg.deleted', 0);
  $query->condition('fg.entity_id', $id);
  $query->innerJoin('file_managed', 'fm', 'fm.fid = fg.field_gallery_fid');
  $query->addField('fm', 'fid', 'fid');
  $query->addField('fm', 'filename', 'filename');
  $query->orderBy('fg.delta', 'ASC');

  return $query->execute();
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

/* ---------------------------------------------------------------------------
 * The marketplace listing — GET /api/v1/providers (SPEC 83).
 *
 * The listing the @file above announced when SPEC 82 created this file with the
 * gallery alone. Nothing below touches the four gallery functions, and the two
 * halves share only the file they live in.
 * ------------------------------------------------------------------------ */

/**
 * Dispatcher for /api/v1/providers (SPEC 83).
 *
 * Only GET is allowed. The marketplace is read-only over the API: a provider is
 * created, edited and suspended by the operator from the back office, so there
 * is no POST, PUT or DELETE, and there is no item route either.
 *
 * The method is checked BEFORE any authentication, like the two gallery
 * dispatchers above and every other dispatcher of this module: a POST answers
 * 405 without a token, because the method is wrong whoever is asking.
 */
function myapi_provider_dispatch() {
  if (myapi_request_method() === 'GET') {
    myapi_provider_list();
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * GET /api/v1/providers — the marketplace listing (SPEC 83).
 *
 * Requires a valid Bearer token and NOTHING else: no role, no condominium, no
 * unit. A provider is not related to any condominium in today's data model, so
 * every authenticated user sees the same marketplace — the same rule
 * /api/v1/service-categories already follows. There is no 403 here and no 404:
 * the collection always exists.
 *
 * Which providers: the ACTIVE ones, that is published and with an unexpired
 * licence, decided by myapi_provider_apply_active_conditions() so that this
 * listing and the providers_count of /api/v1/service-categories can never
 * disagree.
 *
 * THE ONE ASYMMETRY OF THE PARAMETERS, and it is deliberate: `category_id` is
 * the only one that can answer 422. A malformed id is a badly built request,
 * while `?order_by=precio` is merely a value this endpoint does not know — and
 * page, limit, order_by and sort all fall back to their defaults in silence,
 * the rule already written in bulletins and payments. The same split
 * resources/bulletin.resource.inc makes with condominium_id.
 *
 * And once category_id IS a positive integer there are no more judgements: a
 * tid that does not exist, that belongs to another vocabulary, or that no
 * provider carries, all answer the same 200 with an empty list. The endpoint
 * filters, it does not validate the catalogue; checking the term would be an
 * extra query to answer 422 where an empty list says the same thing and breaks
 * nothing in the app.
 *
 * The cost is FOUR queries whatever the page size: the token, the count, the
 * page and the categories of that page.
 */
function myapi_provider_list() {
  // 1. Authentication: a valid Bearer access token, or 401. The returned row is
  // not used — the marketplace is not per-user.
  myapi_auth_require_access_token();

  // 2. The only parameter that can be wrong rather than unknown. Absent means
  // no filter; anything present that is not a positive integer is a 422, empty
  // string and array included. is_scalar() first, so ?category_id[]=1 never
  // reaches a string cast.
  $category_id = NULL;
  if (isset($_GET['category_id'])) {
    $raw = $_GET['category_id'];
    if (!is_scalar($raw) || !ctype_digit((string) $raw) || (int) $raw <= 0) {
      myapi_error('invalid_field', 422, ['@field' => 'category_id']);
    }
    $category_id = (int) $raw;
  }

  // 3. Pagination and ordering: invalid or absent values fall back to their
  // defaults silently (no 422), same as bulletins and payments. limit=-1 is NOT
  // "everything" here — the marketplace is site-wide and has no ceiling, unlike
  // the payments or receipts of a single unit.
  $page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) && (int) $_GET['page'] > 0
    ? (int) $_GET['page']
    : 1;

  $limit = isset($_GET['limit']) && ctype_digit((string) $_GET['limit']) && (int) $_GET['limit'] > 0
    ? max(1, min(50, (int) $_GET['limit']))
    : 20;

  $order_by = isset($_GET['order_by']) && in_array($_GET['order_by'], ['rating_avg', 'hourly_rate'], TRUE)
    ? $_GET['order_by']
    : 'rating_avg';

  $sort = isset($_GET['sort']) && in_array($_GET['sort'], ['asc', 'desc'], TRUE)
    ? $_GET['sort']
    : 'desc';

  // 4. The size of the ALREADY FILTERED set, which is what pagination.total
  // means. total_pages is 0 and not 1 when there is nothing to page through.
  $total = myapi_provider_count($category_id);
  $total_pages = $total > 0 ? (int) ceil($total / $limit) : 0;

  // 5. The page. A page beyond the last one is an empty list with a 200, never
  // a 404.
  $rows = myapi_provider_fetch($category_id, $page, $limit, $order_by, $sort);

  // 6. The categories of the whole page in ONE query, keyed by nid — never one
  // query per provider.
  $nids = [];
  foreach ($rows as $row) {
    $nids[] = (int) $row->nid;
  }
  $categories = myapi_provider_categories_by_nid($nids);

  $providers = array_map(function ($row) use ($categories) {
    $nid = (int) $row->nid;

    return myapi_provider_build_item($row, isset($categories[$nid]) ? $categories[$nid] : []);
  }, $rows);

  // 7. Respond 200 (read-only endpoint, no message).
  myapi_respond([
    'providers'  => $providers,
    'pagination' => [
      'total'       => (int) $total,
      'page'        => $page,
      'limit'       => $limit,
      'total_pages' => $total_pages,
    ],
  ], 200);
}

/**
 * The category filter, as a correlated EXISTS (SPEC 83).
 *
 * Built once and used by both list queries, so the count and the page can never
 * filter differently — the same reason the active rule became an include.
 *
 * AN EXISTS AND NOT A JOIN, and this is the decision the whole listing hangs
 * on: field_categories has unlimited cardinality, so joining it produces one
 * row per category of the provider. A provider working in three categories
 * would appear three times on the page and count three times in `total`. The
 * usual patch — DISTINCT — drags the ORDER BY into the select list and
 * complicates the range. An EXISTS multiplies nothing: it asks whether at least
 * one matching row exists and stops there.
 *
 * Same pattern as the 'Personalizado' branch of
 * myapi_bulletin_visibility_condition() (SPEC 29).
 *
 * The sub-select correlates to the OUTER node through the raw fragment
 * `fc.entity_id = n.nid`, so the caller must have aliased `node` as 'n'.
 *
 * @param int $category_id
 *   A taxonomy term id, already validated as a positive integer. Whether the
 *   term exists is NOT asked here: a tid that no term carries simply matches no
 *   row, which is the 200-with-an-empty-list the spec wants.
 *
 * @return SelectQuery
 *   The sub-select, ready for $query->exists().
 */
function myapi_provider_category_exists($category_id) {
  $sub = db_select('field_data_field_categories', 'fc');
  $sub->addExpression('1');
  $sub->where('fc.entity_id = n.nid')
    ->condition('fc.entity_type', 'node')
    ->condition('fc.deleted', 0)
    ->condition('fc.field_categories_tid', $category_id);

  return $sub;
}

/**
 * Counts the active providers of the listing, unpaginated (SPEC 83).
 *
 * Same base and same filter as myapi_provider_fetch(): the bundle, the active
 * rule and the optional category. THE ACTIVE RULE IS APPLIED BY BOTH OR BY
 * NEITHER — if only one of the two carried it, `total` would say one thing and
 * the rows another, which is the acceptance criterion "el total cuenta
 * exactamente los mismos proveedores que devuelven las páginas".
 *
 * What it does NOT carry is the four LEFT JOINs of the fetch: they add columns,
 * never rows, so counting without them answers the same number over the same
 * set and reads one join table instead of five.
 *
 * @param int|NULL $category_id
 *   The tid to narrow by, or NULL for the whole marketplace.
 *
 * @return int
 *   The size of the filtered set.
 */
function myapi_provider_count($category_id = NULL) {
  $query = db_select('node', 'n');
  $query->condition('n.type', MYAPI_SERVICES_PROVIDER_TYPE);
  myapi_provider_apply_active_conditions($query, 'n');

  if ($category_id !== NULL) {
    $query->exists(myapi_provider_category_exists($category_id));
  }

  return (int) $query->countQuery()->execute()->fetchField();
}

/**
 * Fetches one page of active providers (SPEC 83).
 *
 * Same base, same active rule and same category filter as
 * myapi_provider_count(), plus the four columns the item needs and the
 * ordering.
 *
 * EVERY FIELD JOIN IS LEFT AND NEVER INNER — the four of SPEC 83 and the two
 * the logo of SPEC 85 adds. All those fields are optional, so an INNER JOIN
 * would not return fewer columns — it would make providers DISAPPEAR from the
 * marketplace: no rate, no listing. It raises no error and breaks no test about
 * the shape of the response; nobody notices until a provider calls asking why
 * they are not there. That is the whole reason this comment exists.
 *
 * THE ORDERING IS THREE CRITERIA, and each one answers something:
 *
 *  1. `(<column> IS NULL) ASC` — whoever does not publish the value goes LAST.
 *     THIS CRITERION DOES NOT DEPEND ON $sort, on purpose: a listing sorted by
 *     price from low to high that OPENS with the providers who publish no price
 *     reads as a server error, not as an order. Ascending on the boolean means
 *     0 (has a value) before 1 (is null), in both directions.
 *  2. The requested column, in the requested direction.
 *  3. `n.nid DESC` — the tie-breaker, and it is not decoration. Without it two
 *     providers scoring 4.50 can swap places between page 1 and page 2, so the
 *     app shows one of them twice and never shows the other: the hardest
 *     pagination bug there is to reproduce. nid DESC is stable, needs no join,
 *     and means something — at equal rating, the newest first.
 *
 * @param int|NULL $category_id
 *   The tid to narrow by, or NULL for the whole marketplace.
 * @param int      $page
 *   1-based page number, already clamped.
 * @param int      $limit
 *   Rows per page, already clamped to [1, 50].
 * @param string   $order_by
 *   'rating_avg' or 'hourly_rate'. Anything else falls back to 'rating_avg':
 *   the whitelist is restated here and not merely trusted, because this value
 *   reaches an SQL expression and a whitelist is what keeps it a column name.
 * @param string   $sort
 *   'asc' or 'desc'; anything else is 'desc'.
 *
 * @return array
 *   Rows carrying `nid`, `title`, `rating_avg`, `rating_count`,
 *   `short_description`, `hourly_rate` and `logo_uri` — the aliases
 *   myapi_provider_build_item() reads.
 */
function myapi_provider_fetch($category_id, $page, $limit, $order_by, $sort) {
  $query = db_select('node', 'n');
  $query->fields('n', ['nid', 'title']);
  $query->condition('n.type', MYAPI_SERVICES_PROVIDER_TYPE);
  myapi_provider_apply_active_conditions($query, 'n');

  // LEFT, never INNER — see the docblock. A provider with no rating, no rate or
  // no description stays in the listing with NULLs.
  $query->leftJoin('field_data_field_rating_avg', 'ra', "ra.entity_id = n.nid AND ra.entity_type = 'node' AND ra.deleted = 0");
  $query->addField('ra', 'field_rating_avg_value', 'rating_avg');

  $query->leftJoin('field_data_field_rating_count', 'rc', "rc.entity_id = n.nid AND rc.entity_type = 'node' AND rc.deleted = 0");
  $query->addField('rc', 'field_rating_count_value', 'rating_count');

  $query->leftJoin('field_data_field_short_description', 'sd', "sd.entity_id = n.nid AND sd.entity_type = 'node' AND sd.deleted = 0");
  $query->addField('sd', 'field_short_description_value', 'short_description');

  $query->leftJoin('field_data_field_hourly_rate', 'hr', "hr.entity_id = n.nid AND hr.entity_type = 'node' AND hr.deleted = 0");
  $query->addField('hr', 'field_hourly_rate_value', 'hourly_rate');

  // The logo of SPEC 85, in two chained joins. BOTH ARE LEFT, and the second as
  // much as the first: an INNER on field_data_field_logo would drop every
  // provider with no logo, and an INNER on file_managed would drop the one whose
  // file was deleted from disk outside Drupal. The two failures are the same
  // kind — a missing image cannot make a company disappear from the marketplace.
  //
  // No extra query and no query per provider: the uri travels in the row the
  // listing was already fetching. Neither join can multiply rows, because
  // field_logo has cardinality 1 and file_managed.fid is a primary key.
  $query->leftJoin('field_data_field_logo', 'lg', "lg.entity_id = n.nid AND lg.entity_type = 'node' AND lg.deleted = 0");
  $query->leftJoin('file_managed', 'fm', 'fm.fid = lg.field_logo_fid');
  $query->addField('fm', 'uri', 'logo_uri');

  if ($category_id !== NULL) {
    $query->exists(myapi_provider_category_exists($category_id));
  }

  // The two orderable columns, and only these two. Written as a map so the
  // value that reaches the ORDER BY is a literal of this file and never a
  // fragment of the query string.
  $columns = [
    'rating_avg'  => 'ra.field_rating_avg_value',
    'hourly_rate' => 'hr.field_hourly_rate_value',
  ];
  $column = isset($columns[$order_by]) ? $columns[$order_by] : $columns['rating_avg'];

  // 1. Nulls last in BOTH directions — deliberately not tied to $sort.
  $nulls_last = $query->addExpression('(' . $column . ' IS NULL)', 'order_is_null');
  $query->orderBy($nulls_last, 'ASC');
  // 2. The order asked for.
  $query->orderBy($column, $sort === 'asc' ? 'ASC' : 'DESC');
  // 3. The stable tie-breaker, always the same direction.
  $query->orderBy('n.nid', 'DESC');

  $query->range(($page - 1) * $limit, $limit);

  return $query->execute()->fetchAll();
}

/**
 * The categories of a whole page of providers, in ONE query (SPEC 83).
 *
 * TWO QUERIES PER REQUEST, NOT ONE PER PROVIDER. With the nids of the page
 * already in memory, a single grouped read answers the categories of all twenty
 * providers; resolving them inside the main query is impossible for the reason
 * the filter is an EXISTS (it would multiply rows), and one query per provider
 * would be twenty-one reads per page. Same shape myapi_bulletin_list() uses
 * with file_load_multiple().
 *
 * A tid that no longer exists as a term — the field row left behind by a term
 * deleted in the back office — is DROPPED by the inner join on
 * taxonomy_term_data: the category vanishes from that provider's list and the
 * provider is answered just the same. Failing closed and in silence, exactly
 * what myapi_service_category_build_item() does with half an icon. Excluding
 * the provider instead would make a term deletion silently remove companies
 * from the marketplace.
 *
 * The code is left-joined and not inner-joined: a term with no
 * field_category_code travels as `code: ""`, which is what
 * /api/v1/service-categories already answers for the same term. Hiding the
 * category would make it disappear from the provider's card with no trace.
 *
 * @param array $nids
 *   The provider nids of the page. An empty list answers an empty map with no
 *   query at all.
 *
 * @return array
 *   Map of (int) nid => list of {id, code, name} items, in Field API delta
 *   order. EVERY REQUESTED NID IS A KEY, with an empty list when the provider
 *   has no category, so the caller never has to decide what a missing entry
 *   means.
 */
function myapi_provider_categories_by_nid(array $nids) {
  if (empty($nids)) {
    return [];
  }

  // Every requested nid starts empty; the query then fills the ones that have
  // categories. Same criterion as myapi_service_category_provider_counts().
  $map = [];
  foreach ($nids as $nid) {
    $map[(int) $nid] = [];
  }

  $query = db_select('field_data_field_categories', 'fc');
  $query->condition('fc.entity_type', 'node');
  $query->condition('fc.deleted', 0);
  $query->condition('fc.entity_id', array_keys($map), 'IN');
  // INNER: an orphan tid is dropped here, quietly and on purpose.
  $query->innerJoin('taxonomy_term_data', 't', 't.tid = fc.field_categories_tid');
  $query->leftJoin('field_data_field_category_code', 'cc', "cc.entity_id = t.tid AND cc.entity_type = 'taxonomy_term' AND cc.deleted = 0");
  $query->addField('fc', 'entity_id', 'nid');
  $query->addField('t', 'tid', 'tid');
  $query->addField('t', 'name', 'name');
  $query->addField('cc', 'field_category_code_value', 'code');
  // Delta order, the order the operator left in the form — never alphabetical.
  // Same criterion as the gallery of SPEC 82.
  $query->orderBy('fc.delta', 'ASC');

  foreach ($query->execute() as $row) {
    // myapi_text_to_plain() on both, the single criterion every string of this
    // resource follows: the consumer is a Flutter Text widget and there is no
    // HTML context to escape for. See myapi_provider_build_item().
    $map[(int) $row->nid][] = [
      'id'   => (int) $row->tid,
      'code' => myapi_text_to_plain(isset($row->code) ? $row->code : ''),
      'name' => myapi_text_to_plain($row->name),
    ];
  }

  return $map;
}

/**
 * Shapes one provider row into the item of the listing (SPEC 83).
 *
 * ALWAYS THE SAME EIGHT KEYS, IN THIS ORDER — id, logo, title, categories,
 * rating_avg, rating_count, short_description, hourly_rate — because that order
 * is the one drupal_json_encode() prints and the one docs/provider.md shows. No
 * key is ever added or dropped by the data: a provider with no logo, no rating,
 * no rate, no description and no category answers all eight just the same.
 *
 * THE DETAIL INHERITS EVERY ONE OF THEM. GET /api/v1/providers/% builds its
 * first keys by calling this very builder (SPEC 84), so `logo` appeared on both
 * endpoints with a single change and the two routes CANNOT diverge — no card of
 * the listing can show a logo the record then fails to show. That is why the key
 * is added here and not twice, once per endpoint.
 *
 * Nothing else of the detail travels: no phone, no address, no
 * field_services_desc and no tags. That is not an omission, it is the scope line
 * between the listing and GET /api/v1/providers/% (SPEC 83, "Fuera de alcance").
 * The GALLERY does not travel either, and for a different reason: those files
 * are private and have a route of their own. The logo does travel because it is
 * public (SPEC 85) and it is what the card of the listing is for.
 *
 * Five typing decisions live here, and each one has a symptom behind it:
 *
 * - `logo` is an ABSOLUTE, DIRECT URL to the file or NULL, never "" and never a
 *   broken URL. It is NOT an api/v1/... path, unlike the gallery: field_logo is
 *   public storage, so the web server serves it and a bare Flutter
 *   Image.network paints it with no Authorization header at all. Same shape
 *   `icon_url` already has in GET /api/v1/service-categories.
 * - `rating_avg` is float or NULL, never 0.0. Zero is OUTSIDE the 1–5 scale of
 *   myapi_services_star_values(), so it would be an impossible value travelling
 *   as if it were real, and the app would paint five empty stars where it
 *   should paint "not rated yet".
 * - `rating_count` is an int and 0 when there is nothing to count, never NULL.
 *   The two keys are not the same question: "how many ratings" always has a
 *   numeric answer and "what does it score" does not. It is also the key the
 *   app must read to tell "not rated" from "rated badly".
 * - `hourly_rate` is float or NULL, never the string "25.50" and never 0.0: an
 *   unpublished rate is not a free service. Same treatment `amount` gets in
 *   expenses, receipts and payments. The prefix '$ ' of SPEC 81 belongs to the
 *   back-office form and never travels.
 * - `title` and `short_description` go through myapi_text_to_plain(), like
 *   EVERY string this resource answers — the categories, the tags, the address,
 *   the long description and the author of a rating included. They used to be
 *   check_plain()'d, and that was the wrong helper in the wrong place: escaping
 *   belongs to a rendering context, and a JSON body read by a Flutter Text
 *   widget is not one. The symptom was a provider named `Luz & Cía` travelling
 *   as `Luz &amp; Cía` and being painted with the entity visible, since Flutter
 *   decodes nothing. One criterion for the whole resource also removes the
 *   older split where `address` and `description` arrived as plain text and
 *   `title` did not, in the same payload.
 *
 *   Nothing is lost on the safety side: myapi_text_to_plain() strips tags
 *   before decoding entities, so no markup survives it either — see the
 *   reasoning written in includes/myapi.text.inc. It costs a strip_tags() on
 *   fields that carry no markup (field_short_description is a `text` field with
 *   no text_processing, SPEC 81) and normalises their whitespace as a side
 *   effect; both are worth one rule instead of two.
 *
 * @param object $row
 *   One row of myapi_provider_fetch(), carrying the aliases `nid`, `title`,
 *   `rating_avg`, `rating_count`, `short_description`, `hourly_rate` and
 *   `logo_uri`. Every field column arrives from a LEFT JOIN, so any of them may
 *   be NULL.
 * @param array $categories
 *   The categories of THIS provider, already built as {id, code, name} items
 *   and in delta order. Passed in and not looked up: resolving them here would
 *   mean one query per provider, and the whole page is meant to cost two.
 *   Defaults to an empty list, which is also the honest answer for a provider
 *   that has none.
 *
 * @return array
 *   The eight keys above, in that order.
 */
function myapi_provider_build_item($row, array $categories = []) {
  $rating_avg = isset($row->rating_avg) && $row->rating_avg !== ''
    ? (float) $row->rating_avg
    : NULL;

  $hourly_rate = isset($row->hourly_rate) && $row->hourly_rate !== ''
    ? (float) $row->hourly_rate
    : NULL;

  $short_description = isset($row->short_description) ? $row->short_description : '';

  // Same emptiness guard as rating_avg and hourly_rate above — isset() AND
  // !== '' — so a provider with no logo, or one whose field row points at a
  // file_managed that no longer exists, answers NULL and never "" nor a broken
  // URL. The app has one empty case to handle, not two.
  $logo = isset($row->logo_uri) && $row->logo_uri !== ''
    ? file_create_url($row->logo_uri)
    : NULL;

  return [
    'id'                => (int) $row->nid,
    'logo'              => $logo,
    'title'             => myapi_text_to_plain($row->title),
    'categories'        => $categories,
    'rating_avg'        => $rating_avg,
    // (int) of NULL is 0, which is exactly the answer wanted: the cast is the
    // rule, not a coincidence to be tidied away later.
    'rating_count'      => (int) (isset($row->rating_count) ? $row->rating_count : 0),
    'short_description' => myapi_text_to_plain($short_description),
    'hourly_rate'       => $hourly_rate,
  ];
}

/* ---------------------------------------------------------------------------
 * The provider's OWN listing — GET /api/v1/providers/mine (SPEC 97).
 *
 * The other side of the marketplace above: not "which providers may I hire?"
 * but "which providers am I?". Same rows, same builder, two differences that
 * are the whole point — the audience is the account linked through
 * field_provider_users instead of everybody, and an INACTIVE provider is
 * listed rather than hidden, because its own operator is precisely who needs
 * to be told that the licence expired.
 *
 * Nothing above this comment is modified. myapi_provider_build_item() and
 * myapi_provider_fetch() are reused and not touched, which is what keeps the
 * eight shared keys from ever diverging between the two listings.
 * ------------------------------------------------------------------------ */

/**
 * The rows of the providers ONE account is linked to (SPEC 97).
 *
 * Same base, same joins and — this is the contract — THE SAME OUTPUT ALIASES
 * as myapi_provider_fetch(): nid, title, rating_avg, rating_count,
 * short_description, hourly_rate and logo_uri. myapi_provider_build_item()
 * reads them all with isset(), so a renamed alias here does not raise an
 * error: it silently answers NULL in that key while the public listing stays
 * correct. That is the failure mode ProviderMineEndpointTest exists to catch.
 *
 * IT DOES NOT CALL myapi_provider_apply_active_conditions(), AND THAT IS
 * DELIBERATE — not an oversight to be tidied up later. That helper adds an
 * INNER JOIN on field_data_field_license_expiry plus `status = 1`, which would
 * drop exactly the providers this endpoint exists to show. The rule is not
 * skipped, it is moved: myapi_provider_mine_build_item() computes the very
 * same condition over the row as the `is_active` flag. Hence the LEFT join
 * below, and hence its own alias — the helper documents that 'l' is reserved,
 * and a future caller adding it back would collide on top of breaking the
 * listing.
 *
 * n.type is filtered even though the nids come from field_provider_users,
 * which today only exists on the 'provider' bundle: the query must not depend
 * on where its nids were resolved.
 *
 * NO range(): an account operates one or two providers, so there is no
 * pagination to page through (SPEC 97, decision 7). ORDER BY n.nid DESC is the
 * deterministic order, the same tie-breaker the public listing already uses.
 *
 * @param array $nids
 *   The provider nids the account is linked to. An empty list answers an empty
 *   array WITH NO QUERY AT ALL — `IN ()` is not valid SQL and an account with
 *   the role but no provider is a normal 200, not an error.
 *
 * @return array
 *   Rows carrying the seven aliases above plus `status` and `license_expiry`,
 *   the two myapi_provider_mine_build_item() adds on top. May be shorter than
 *   $nids: a nid whose node was deleted simply matches nothing.
 */
function myapi_provider_mine_fetch(array $nids) {
  if (empty($nids)) {
    return [];
  }

  $query = db_select('node', 'n');
  $query->fields('n', ['nid', 'title', 'status']);
  $query->condition('n.type', MYAPI_SERVICES_PROVIDER_TYPE);
  $query->condition('n.nid', $nids, 'IN');

  // The five field joins of myapi_provider_fetch(), copied with their aliases
  // and all of them LEFT for the same reason: an optional field that is empty
  // cannot make the provider disappear from its owner's own list.
  $query->leftJoin('field_data_field_rating_avg', 'ra', "ra.entity_id = n.nid AND ra.entity_type = 'node' AND ra.deleted = 0");
  $query->addField('ra', 'field_rating_avg_value', 'rating_avg');

  $query->leftJoin('field_data_field_rating_count', 'rc', "rc.entity_id = n.nid AND rc.entity_type = 'node' AND rc.deleted = 0");
  $query->addField('rc', 'field_rating_count_value', 'rating_count');

  $query->leftJoin('field_data_field_short_description', 'sd', "sd.entity_id = n.nid AND sd.entity_type = 'node' AND sd.deleted = 0");
  $query->addField('sd', 'field_short_description_value', 'short_description');

  $query->leftJoin('field_data_field_hourly_rate', 'hr', "hr.entity_id = n.nid AND hr.entity_type = 'node' AND hr.deleted = 0");
  $query->addField('hr', 'field_hourly_rate_value', 'hourly_rate');

  // The logo of SPEC 85, in the same two chained LEFT joins.
  $query->leftJoin('field_data_field_logo', 'lg', "lg.entity_id = n.nid AND lg.entity_type = 'node' AND lg.deleted = 0");
  $query->leftJoin('file_managed', 'fm', 'fm.fid = lg.field_logo_fid');
  $query->addField('fm', 'uri', 'logo_uri');

  // The licence, LEFT and under an alias of its own ('lx', never 'l'). This is
  // the join myapi_provider_apply_active_conditions() makes INNER; here it must
  // let through both the provider with an expired licence and the one with no
  // licence row at all, so that is_active can say so instead of the provider
  // vanishing.
  $query->leftJoin('field_data_field_license_expiry', 'lx', "lx.entity_id = n.nid AND lx.entity_type = 'node' AND lx.deleted = 0");
  $query->addField('lx', 'field_license_expiry_value', 'license_expiry');

  $query->orderBy('n.nid', 'DESC');

  return $query->execute()->fetchAll();
}

/**
 * One item of the own listing: the eight shared keys plus two (SPEC 97).
 *
 * myapi_provider_build_item() is called UNCHANGED and its result is extended,
 * never rebuilt. That is the whole design of this endpoint: the eight keys the
 * app already knows from GET /api/v1/providers cannot drift here, because they
 * are literally the same code. `hourly_rate` travels along even though the own
 * listing had no use for it — nine keys were asked for and ten are answered —
 * and that is the price paid on purpose (SPEC 97, decision 6).
 *
 * The two new keys go LAST, so the existing order is untouched.
 *
 * IS_ACTIVE IS A DUPLICATE OF A BUSINESS RULE, and it is worth naming it:
 * myapi_provider_apply_active_conditions() (includes/myapi.provider_query.inc)
 * writes the same condition — published AND field_license_expiry >=
 * REQUEST_TIME, with the same inclusive boundary — as an SQL filter. Here it is
 * a flag over an already fetched row, which is a shape that helper cannot
 * produce. THE CONTRACT IS THE EQUIVALENCE: `is_active: TRUE` means "this
 * provider appears today in GET /api/v1/providers", and a spec that changes
 * what active means has to change BOTH places. ProviderMineEndpointTest pins
 * the boundary.
 *
 * Both keys are pure bools, never NULL and never 0/1: a provider with no
 * licence row — the LEFT join above answers NULL — is `is_active: FALSE`, the
 * same as an expired one, because a third value would mean exactly what FALSE
 * already means.
 *
 * @param object $row
 *   One row of myapi_provider_mine_fetch(), that is a row of
 *   myapi_provider_fetch() plus `status` and `license_expiry`.
 * @param array $categories
 *   The categories of THIS provider, already built. Same contract as
 *   myapi_provider_build_item().
 *
 * @return array
 *   Ten keys: the eight of the marketplace item, then `status` and
 *   `is_active`.
 */
function myapi_provider_mine_build_item($row, array $categories = []) {
  $item = myapi_provider_build_item($row, $categories);

  $item['status'] = isset($row->status) && (int) $row->status === 1;
  $item['is_active'] = $item['status']
    && isset($row->license_expiry)
    && $row->license_expiry !== ''
    && (int) $row->license_expiry >= REQUEST_TIME;

  return $item;
}

/* ---------------------------------------------------------------------------
 * The provider detail — GET /api/v1/providers/% (SPEC 84).
 *
 * Announced by the @file above since SPEC 82/83: the phone, the address, the
 * long description, the tags, the full gallery and the ratings of one
 * provider. Nothing below touches the gallery or listing functions above it,
 * except myapi_provider_gallery_images() (SPEC 82, extracted in step 3), which
 * this half reuses so the gallery listing and the detail can never disagree
 * about which images a provider has.
 * ------------------------------------------------------------------------ */

/**
 * The provider row of the detail (SPEC 84).
 *
 * Same base and same aliases as myapi_provider_fetch() — nid, title,
 * rating_avg, rating_count, short_description, hourly_rate and logo_uri — plus
 * two more LEFT JOINs for address and description. UNLIKE the listing, this does NOT
 * call myapi_provider_apply_active_conditions(): the detail only requires the
 * node to be PUBLISHED, not "active" (published AND licensed). A provider
 * with an expired licence keeps answering 200 here, exactly like its gallery
 * (SPEC 82) already does — the licence rule lives where it means something,
 * the listing.
 *
 * EVERY field join is LEFT, for the same reason as the listing: every one of
 * these fields is optional, and an INNER JOIN would make the detail disappear
 * (404) for a provider who simply has not filled in a rate, a description or a
 * logo, instead of answering the documented empty value.
 *
 * @param int $id  The provider nid, already validated as a positive integer.
 *
 * @return object|FALSE  One row, or FALSE when no published 'provider' node
 *                        with this nid exists.
 */
function myapi_provider_detail_fetch($id) {
  $query = db_select('node', 'n');
  $query->fields('n', ['nid', 'title']);
  $query->condition('n.nid', $id);
  $query->condition('n.type', MYAPI_SERVICES_PROVIDER_TYPE);
  $query->condition('n.status', 1);

  $query->leftJoin('field_data_field_rating_avg', 'ra', "ra.entity_id = n.nid AND ra.entity_type = 'node' AND ra.deleted = 0");
  $query->addField('ra', 'field_rating_avg_value', 'rating_avg');

  $query->leftJoin('field_data_field_rating_count', 'rc', "rc.entity_id = n.nid AND rc.entity_type = 'node' AND rc.deleted = 0");
  $query->addField('rc', 'field_rating_count_value', 'rating_count');

  $query->leftJoin('field_data_field_short_description', 'sd', "sd.entity_id = n.nid AND sd.entity_type = 'node' AND sd.deleted = 0");
  $query->addField('sd', 'field_short_description_value', 'short_description');

  $query->leftJoin('field_data_field_hourly_rate', 'hr', "hr.entity_id = n.nid AND hr.entity_type = 'node' AND hr.deleted = 0");
  $query->addField('hr', 'field_hourly_rate_value', 'hourly_rate');

  $query->leftJoin('field_data_field_address', 'ad', "ad.entity_id = n.nid AND ad.entity_type = 'node' AND ad.deleted = 0");
  $query->addField('ad', 'field_address_value', 'address');

  $query->leftJoin('field_data_field_services_desc', 'de', "de.entity_id = n.nid AND de.entity_type = 'node' AND de.deleted = 0");
  $query->addField('de', 'field_services_desc_value', 'description');

  // The same pair of chained joins as myapi_provider_fetch(), with the same
  // alias, so both endpoints read the logo from the same column name. BOTH ARE
  // LEFT for the same reason: here an INNER would turn a provider with no logo —
  // or with a file deleted from disk — into a 404 on their own detail page.
  $query->leftJoin('field_data_field_logo', 'lg', "lg.entity_id = n.nid AND lg.entity_type = 'node' AND lg.deleted = 0");
  $query->leftJoin('file_managed', 'fm', 'fm.fid = lg.field_logo_fid');
  $query->addField('fm', 'uri', 'logo_uri');

  $query->range(0, 1);

  return $query->execute()->fetchObject();
}

/**
 * The tags of one provider, already resolved to strings (SPEC 84).
 *
 * A single INNER JOIN with taxonomy_term_data: a tid left behind by a term
 * deleted in the back office is dropped in silence, the same criterion an
 * orphan category follows in myapi_provider_categories_by_nid().
 *
 * @param int $id  The provider nid.
 *
 * @return array  The tag names as plain text, in Field API delta order. Empty
 *                when the provider has none.
 */
function myapi_provider_tags_by_id($id) {
  $query = db_select('field_data_field_tags', 'ft');
  $query->condition('ft.entity_type', 'node');
  $query->condition('ft.deleted', 0);
  $query->condition('ft.entity_id', $id);
  // INNER: an orphan tid is dropped here, quietly and on purpose — same
  // criterion as myapi_provider_categories_by_nid().
  $query->innerJoin('taxonomy_term_data', 't', 't.tid = ft.field_tags_tid');
  $query->addField('t', 'name', 'name');
  $query->orderBy('ft.delta', 'ASC');

  $tags = [];
  foreach ($query->execute() as $row) {
    $tags[] = myapi_text_to_plain($row->name);
  }

  return $tags;
}

/**
 * The last $limit ratings of one provider, newest first (SPEC 84).
 *
 * Raw rows, not yet shaped: myapi_provider_build_rating_item() resolves the
 * author name and the unit name per row. No status filter on the node: SPEC
 * 77 decided that moderating a rating means DELETING the node, not
 * unpublishing it, so filtering by status here would be a condition that
 * never excludes anything today while contradicting that decision the day
 * someone unpublishes instead of deleting.
 *
 * field_data_field_unit is LEFT and not INNER — a rating with no unit
 * recorded (every one of them today, until the flow that creates a rating
 * fills it in) still shows, only with a NULL unit_nid.
 *
 * @param int $id      The provider nid.
 * @param int $limit   How many rows to return, most recent first.
 *
 * @return array  Rows carrying `uid`, `created`, `stars`, `comment` and
 *                `unit_nid` (NULL when the rating has no field_unit).
 */
function myapi_provider_ratings_recent($id, $limit = 3) {
  $query = db_select('node', 'n');
  $query->fields('n', ['uid', 'created']);
  $query->condition('n.type', MYAPI_SERVICES_RATING_TYPE);

  $query->innerJoin('field_data_field_rating_provider', 'rp', "rp.entity_id = n.nid AND rp.entity_type = 'node' AND rp.deleted = 0");
  $query->condition('rp.field_rating_provider_target_id', $id);

  $query->leftJoin('field_data_field_stars', 'st', "st.entity_id = n.nid AND st.entity_type = 'node' AND st.deleted = 0");
  $query->addField('st', 'field_stars_value', 'stars');

  $query->leftJoin('field_data_field_rating_comment', 'rc', "rc.entity_id = n.nid AND rc.entity_type = 'node' AND rc.deleted = 0");
  $query->addField('rc', 'field_rating_comment_value', 'comment');

  $query->leftJoin('field_data_field_unit', 'fu', "fu.entity_id = n.nid AND fu.entity_type = 'node' AND fu.deleted = 0");
  $query->addField('fu', 'field_unit_target_id', 'unit_nid');

  $query->orderBy('n.created', 'DESC');
  $query->range(0, $limit);

  return $query->execute()->fetchAll();
}

/**
 * The full rating history of one provider, grouped by stars (SPEC 84).
 *
 * Counts the WHOLE history, not just the $limit rows myapi_provider_ratings_
 * recent() returns: the sum of the five values this returns always equals
 * `rating_count`. The five keys ('1'..'5') are always present, zero-filled,
 * so the app never has to fill a gap myapi_services_star_values() would
 * otherwise leave.
 *
 * field_data_field_stars is INNER here, same as field_data_field_rating_
 * provider: field_stars is required at the instance level, so a rating with
 * no stars value is not an expected row to count.
 *
 * @param int $id  The provider nid.
 *
 * @return array  ['1' => int, '2' => int, '3' => int, '4' => int, '5' => int].
 */
function myapi_provider_rating_summary($id) {
  $summary = ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0];

  $query = db_select('node', 'n');
  $query->condition('n.type', MYAPI_SERVICES_RATING_TYPE);

  $query->innerJoin('field_data_field_rating_provider', 'rp', "rp.entity_id = n.nid AND rp.entity_type = 'node' AND rp.deleted = 0");
  $query->condition('rp.field_rating_provider_target_id', $id);

  $query->innerJoin('field_data_field_stars', 'st', "st.entity_id = n.nid AND st.entity_type = 'node' AND st.deleted = 0");
  $query->addField('st', 'field_stars_value', 'stars');
  $query->addExpression('COUNT(*)', 'total');
  $query->groupBy('st.field_stars_value');

  foreach ($query->execute() as $row) {
    $summary[(string) (int) $row->stars] = (int) $row->total;
  }

  return $summary;
}

/**
 * Shapes one row of myapi_provider_ratings_recent() into a rating item
 * (SPEC 84).
 *
 * `author_name` is ABBREVIATED ("Andrés M."), never the full name
 * myapi_claim_notification.inc builds for `requester_name` — a deliberate
 * divergence: the marketplace is site-wide, so a rating travels outside the
 * author's own condominium to any user with a valid token, unlike a claim,
 * which is only ever read inside the same one.
 *
 * Same three fallback levels as requester_name: profile (first name + the
 * first initial of the last name) → account->name → "Usuario eliminado", the
 * last one for a uid whose account no longer exists.
 *
 * `unit` resolves field_nombre_vivienda of the 'vivienda' node of field_unit —
 * its NAME, not its title, the same value GET /api/v1/units answers as `name`.
 * NULL when the rating carries no field_unit (every rating today, until the
 * flow that fills it in when a rating is created exists) and also NULL when
 * the unit node cannot be loaded or has no name recorded: the title is never
 * used as a fallback.
 *
 * @param object $row
 *   One row of myapi_provider_ratings_recent(), carrying `uid`, `created`,
 *   `stars`, `comment` and `unit_nid`.
 *
 * @return array
 *   ['stars' => int, 'comment' => string, 'author_name' => string,
 *   'unit' => string|NULL, 'created' => string].
 */
function myapi_provider_build_rating_item($row) {
  $author_name = 'Usuario eliminado';
  $uid = isset($row->uid) ? (int) $row->uid : 0;
  if ($uid > 0) {
    $account = user_load($uid);
    if ($account) {
      $profile = myapi_user_fetch_profile_fields($uid);
      if ($profile['first_name'] !== NULL || $profile['last_name'] !== NULL) {
        $first_name = $profile['first_name'] !== NULL ? $profile['first_name'] : '';
        $last_initial = $profile['last_name'] !== NULL ? drupal_substr($profile['last_name'], 0, 1) . '.' : '';
        $author_name = trim($first_name . ' ' . $last_initial);
      }
      else {
        $author_name = $account->name;
      }
    }
  }

  $unit = NULL;
  if (!empty($row->unit_nid)) {
    $unit_node = node_load((int) $row->unit_nid);
    // field_nombre_vivienda, NOT the node title: the title of a 'vivienda' is
    // an internal label, and the name the resident knows their unit by is the
    // field — the same one myapi_unit_fetch_units() projects as `name` for
    // GET /api/v1/units. No fallback to the title when the field is empty, for
    // the same reason: the title would be exactly the wrong string to show.
    if ($unit_node && isset($unit_node->field_nombre_vivienda[LANGUAGE_NONE][0]['value'])) {
      $unit = $unit_node->field_nombre_vivienda[LANGUAGE_NONE][0]['value'];
    }
  }

  return [
    'stars'       => (int) (isset($row->stars) ? $row->stars : 0),
    'comment'     => myapi_text_to_plain(isset($row->comment) ? $row->comment : NULL),
    'author_name' => myapi_text_to_plain($author_name),
    // The ternary stays: myapi_text_to_plain() answers "" for a NULL, and ""
    // is not the answer here — a rating with no unit says so with NULL.
    'unit'        => $unit !== NULL ? myapi_text_to_plain($unit) : NULL,
    'created'     => format_date((int) $row->created, 'custom', 'Y-m-d\TH:i:s'),
  ];
}

/**
 * Shapes the whole provider detail item (SPEC 84).
 *
 * Starts from myapi_provider_build_item() — the same eight keys the listing
 * (SPEC 83, plus the logo of SPEC 85) answers for this provider, byte for byte —
 * and adds the six new ones of the detail, in the exact order
 * docs/provider-detail.md documents: id, logo, title, categories, rating_avg,
 * rating_count, short_description, hourly_rate, address, description, tags,
 * gallery, ratings, rating_summary.
 *
 * `logo` is inherited and not mapped here, which is the point: the listing and
 * the detail answer the same URL for the same provider because there is one
 * builder and not two.
 *
 * `address` and `description` go through myapi_text_to_plain(), which is by now
 * the only string helper this resource uses: they are text_long fields with
 * text_processing on (a rich editor behind them), so there is markup to
 * flatten, and the fields with no markup behind them take the same route
 * anyway. The reason that rule is one and not two is in
 * myapi_provider_build_item().
 *
 * `gallery` and `ratings` are mapped here, with the exact same builders the
 * gallery listing and this file's own myapi_provider_build_rating_item() use,
 * so the detail can never draw a different picture of the same rows.
 *
 * @param object $row
 *   One row of myapi_provider_detail_fetch().
 * @param array $categories
 *   This provider's categories, already built as {id, code, name} items —
 *   the same shape myapi_provider_categories_by_nid() returns.
 * @param array $tags
 *   This provider's tags, already resolved by myapi_provider_tags_by_id().
 * @param \Traversable|array $gallery_rows
 *   Rows of myapi_provider_gallery_images(), carrying `fid` and `filename`.
 * @param array $ratings_rows
 *   Rows of myapi_provider_ratings_recent(), most recent first.
 * @param array $rating_summary
 *   The five-key map myapi_provider_rating_summary() returns.
 *
 * @return array
 *   The fourteen keys of the detail, in order.
 */
function myapi_provider_detail_build_item($row, array $categories, array $tags, $gallery_rows, array $ratings_rows, array $rating_summary) {
  $item = myapi_provider_build_item($row, $categories);

  $gallery = [];
  foreach ($gallery_rows as $image_row) {
    $gallery[] = myapi_provider_build_image($image_row->fid, $image_row->filename, (int) $row->nid);
  }

  $ratings = [];
  foreach ($ratings_rows as $rating_row) {
    $ratings[] = myapi_provider_build_rating_item($rating_row);
  }

  return array_merge($item, [
    'address'        => myapi_text_to_plain(isset($row->address) ? $row->address : NULL),
    'description'    => myapi_text_to_plain(isset($row->description) ? $row->description : NULL),
    'tags'           => $tags,
    'gallery'        => $gallery,
    'ratings'        => $ratings,
    'rating_summary' => $rating_summary,
  ]);
}

/**
 * Dispatcher for /api/v1/providers/% (SPEC 84).
 *
 * Only GET is allowed; the detail is read-only over the API, same as the
 * listing and the gallery.
 *
 * The method is checked BEFORE any authentication, the same criterion every
 * other dispatcher of this module follows: a POST answers 405 without a
 * token, because the method is wrong whoever is asking.
 *
 * @param mixed $id  The provider nid from the route.
 */
function myapi_provider_detail_dispatch($id = NULL) {
  if (myapi_request_method() === 'GET') {
    myapi_provider_detail_show($id);
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * GET /api/v1/providers/% — one provider's detail (SPEC 84).
 *
 * Requires a valid Bearer token and NOTHING else, same as the listing and the
 * gallery: no role, no condominium, no unit — every authenticated user sees
 * the same marketplace.
 *
 * Existence is the SAME rule as the gallery (SPEC 82) and NOT the "active"
 * rule of the listing (SPEC 83): the node must exist, be of type 'provider'
 * and be PUBLISHED. A lapsed licence does not hide the detail — the same
 * asymmetry the gallery already has, extended here to the rest of the ficha.
 *
 * Six queries: the provider row, the categories, the tags, the gallery, the
 * last three ratings and the rating summary — the last five run only once the
 * nid is known to exist, resolved from myapi_provider_detail_fetch() and
 * never trusted from the route argument again.
 */
function myapi_provider_detail_show($id) {
  // 1. Token. Answers 401 on its own and never returns on failure.
  myapi_auth_require_access_token();

  // 2. The id: a non-numeric, empty, negative or zero argument never reaches
  // the database, same criterion as the gallery.
  if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id <= 0) {
    myapi_error('provider_not_found', 404);
  }
  $id = (int) $id;

  // 3. The provider row: published, of the right type. An empty result means
  // the nid does not exist, is not a 'provider' or is unpublished — the three
  // collapse into the same 404, same as every other endpoint of this module.
  $row = myapi_provider_detail_fetch($id);
  if (!$row) {
    myapi_error('provider_not_found', 404);
  }

  // 4. The rest of the ficha, with the nid already confirmed to exist.
  $categories = myapi_provider_categories_by_nid([$id]);
  $tags = myapi_provider_tags_by_id($id);
  $gallery_rows = myapi_provider_gallery_images($id);
  $ratings_rows = myapi_provider_ratings_recent($id);
  $rating_summary = myapi_provider_rating_summary($id);

  $item = myapi_provider_detail_build_item(
    $row,
    isset($categories[$id]) ? $categories[$id] : [],
    $tags,
    $gallery_rows,
    $ratings_rows,
    $rating_summary
  );

  // 5. Respond 200 (read-only endpoint, no message). `data` IS the provider —
  // no 'provider' wrapper, unlike the listing, which wraps because it also
  // carries 'pagination'.
  myapi_respond($item, 200);
}
