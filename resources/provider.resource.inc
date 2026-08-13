<?php

/**
 * @file
 * Provider resource for the myapi module (SPEC 82, SPEC 83).
 *
 * Three routes, all read-only and all authenticated:
 *
 *   - GET /api/v1/providers               — the marketplace listing (SPEC 83).
 *   - GET /api/v1/providers/%/gallery     — the list of gallery images (82).
 *   - GET /api/v1/providers/%/gallery/%   — the BYTES of one of them (82).
 *
 * SPEC 82 created this file with the gallery alone and announced the listing
 * here; SPEC 83 is that listing, and it lands in this file rather than a
 * provider_list one because Regla 2 de CLAUDE.md is one resource, one file, and
 * the resource is the provider.
 *
 * THERE IS STILL NO GET /api/v1/providers/%. The detail — the phone, the
 * address, field_services_desc, the tags of SPEC 81 and the users behind a
 * provider — is its own spec, and none of it travels through the listing. That
 * is a deliberate scope decision and not an unfinished file: whoever arrives
 * next should add the detail when its spec says so, not improvise one here to
 * make this look complete. Same warning SPEC 77 left on the chat fields.
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
 * THE FOUR FIELD JOINS ARE LEFT AND NEVER INNER. All four fields are optional,
 * so an INNER JOIN would not return fewer columns — it would make providers
 * DISAPPEAR from the marketplace: no rate, no listing. It raises no error and
 * breaks no test about the shape of the response; nobody notices until a
 * provider calls asking why they are not there. That is the whole reason this
 * comment exists.
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
 *   `short_description` and `hourly_rate` — the aliases
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
    // check_plain() on both, so the id and the code of a category are byte for
    // byte the ones GET /api/v1/service-categories answers for the same term.
    $map[(int) $row->nid][] = [
      'id'   => (int) $row->tid,
      'code' => check_plain(isset($row->code) && $row->code !== NULL ? $row->code : ''),
      'name' => check_plain($row->name),
    ];
  }

  return $map;
}

/**
 * Shapes one provider row into the item of the listing (SPEC 83).
 *
 * ALWAYS THE SAME SEVEN KEYS, IN THIS ORDER — id, title, categories,
 * rating_avg, rating_count, short_description, hourly_rate — because that order
 * is the one drupal_json_encode() prints and the one docs/provider.md shows. No
 * key is ever added or dropped by the data: a provider with no rating, no rate,
 * no description and no category answers all seven just the same.
 *
 * Nothing of the detail travels: no phone, no address, no field_services_desc,
 * no tags and no image. That is not an omission, it is the scope line between
 * the listing and the future GET /api/v1/providers/% (SPEC 83, "Fuera de
 * alcance"), and the gallery is a route of its own precisely because those
 * files are private.
 *
 * Four typing decisions live here, and each one has a symptom behind it:
 *
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
 * - `title` and `short_description` go through check_plain() and NOT through
 *   myapi_text_to_plain(): field_short_description is a `text` field with no
 *   text_processing (SPEC 81), so there is no rich editor behind it and no
 *   markup to flatten — escaping is the right answer. The other helper exists
 *   for the taxonomy descriptions, which do carry format; the distinction is
 *   written in includes/myapi.text.inc.
 *
 * @param object $row
 *   One row of myapi_provider_fetch(), carrying the aliases `nid`, `title`,
 *   `rating_avg`, `rating_count`, `short_description` and `hourly_rate`. The
 *   four field columns arrive from LEFT JOINs, so any of them may be NULL.
 * @param array $categories
 *   The categories of THIS provider, already built as {id, code, name} items
 *   and in delta order. Passed in and not looked up: resolving them here would
 *   mean one query per provider, and the whole page is meant to cost two.
 *   Defaults to an empty list, which is also the honest answer for a provider
 *   that has none.
 *
 * @return array
 *   The seven keys above, in that order.
 */
function myapi_provider_build_item($row, array $categories = []) {
  $rating_avg = isset($row->rating_avg) && $row->rating_avg !== ''
    ? (float) $row->rating_avg
    : NULL;

  $hourly_rate = isset($row->hourly_rate) && $row->hourly_rate !== ''
    ? (float) $row->hourly_rate
    : NULL;

  $short_description = isset($row->short_description) ? $row->short_description : '';

  return [
    'id'                => (int) $row->nid,
    'title'             => check_plain($row->title),
    'categories'        => $categories,
    'rating_avg'        => $rating_avg,
    // (int) of NULL is 0, which is exactly the answer wanted: the cast is the
    // rule, not a coincidence to be tidied away later.
    'rating_count'      => (int) (isset($row->rating_count) ? $row->rating_count : 0),
    'short_description' => check_plain($short_description),
    'hourly_rate'       => $hourly_rate,
  ];
}
