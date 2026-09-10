<?php

/**
 * @file
 * Back-office service-request listing page (SPEC 125).
 *
 * Serves admin/content/service-requests: a read-only, server-rendered,
 * paginated table of 'service_request' nodes with GET filters. It is NOT an
 * API endpoint — nothing here returns the JSON envelope, and no resource under
 * resources/ calls into it. The page lives in includes/ for the same reason
 * includes/myapi.claims_admin.inc and includes/myapi.reservation_calendar.inc
 * do: resources/ is for what the Flutter app consumes, and this is back
 * office.
 *
 * The file is loaded by the 'file' key of its hook_menu() entry in
 * myapi.module, so it only reaches PHP on that one route. Its CSS — the filter
 * row of css/myapi.claims.css, shared with the claims listing because it is
 * the same row — is added from the page callback with drupal_add_css() rather
 * than declared in myapi.info, which would load it on every API response too.
 *
 * No AJAX of any kind (SPEC 47/56's precedent): every filter reloads the page
 * over GET, which is what keeps the URL linkable and the pager's own links
 * (theme('pager')) working with no extra wiring — they carry the current query
 * string automatically.
 *
 * ------------------------------------------------------------------------
 * WHY THE LISTING QUERY LIVES HERE AND NOT IN
 * includes/myapi.service_request_query.inc
 * ------------------------------------------------------------------------
 *
 * That file's @file block states, three times and in capitals, that NONE of
 * its queries carries ->addTag('node_access') — because the tag would run
 * myapi_provider_role_alter_node_query() over them and a resident who also
 * holds the 'proveedor' role would stop seeing their own requests. It is an
 * invariant a reader can check at a glance.
 *
 * myapi_service_requests_list_rows() below carries that tag NECESSARILY: it is
 * the only thing restricting an 'administrador edificio' to the requests of
 * their own condominiums. Putting the two in one file would turn a checkable
 * rule into an exception that has to be read function by function, so the
 * back-office query stays here, next to the only page that runs it.
 *
 * ------------------------------------------------------------------------
 * WHAT IS REUSED, AND WHAT IS DELIBERATELY NOT
 * ------------------------------------------------------------------------
 *
 * The condominium scoping of an 'administrador edificio' (SPEC 49) is reused
 * wholesale from includes/myapi.reservation_calendar.inc, exactly as
 * includes/myapi.claims_admin.inc reuses it:
 * myapi_calendar_condominium_scope(), _positive_int(), _effective_condominium(),
 * _condominium_options() and _filter_form_after_build() are already generic —
 * none of the five reads or writes anything calendar-specific. The row-level
 * restriction to the assigned condominiums is, in any case, done by
 * ->addTag('node_access') on the query itself, so this reuse is only about the
 * filter form's selector, never about access.
 *
 * The status catalogue is NOT duplicated: myapi_services_request_statuses()
 * (includes/myapi.services_common.inc, SPEC 77) is the single source of the
 * select's options AND of the table's labels. This page declares no status and
 * no Spanish label of its own, so the screen and the state machine of the
 * endpoints cannot drift.
 *
 * myapi_service_request_parse_created_range() is NOT called, though it does
 * exactly what myapi_service_requests_created_range() below does: it lives in
 * resources/service_request.resource.inc, and Regla 5 de CLAUDE.md keeps an
 * include out of resources/. The '00:00:00' / '23:59:59' criterion is the
 * same, on purpose, and a change to one is a change to both.
 */

module_load_include('inc', 'myapi', 'includes/myapi.services_common');

/* ---------------------------------------------------------------------------
 * Filters.
 *
 * These touch $_GET, so they are not pure in the strict sense, but they take
 * nothing else and answer plain values — which is what makes
 * tests/unit/ServiceRequestsAdminPageTest.php able to drive them by assigning
 * $_GET directly.
 *
 * NOTHING HERE EVER FAILS. An invented status, a malformed date, a category
 * that is not a number: all of them fall back to "no filter", the same
 * criterion the calendar (SPEC 47) and the claims listing (SPEC 56) follow. A
 * stale bookmark has to answer a listing, never an error page.
 * ------------------------------------------------------------------------ */

/**
 * The status select options: the catalogue of SPEC 77, untouched.
 *
 * Not a copy and not a subset — the six keys in lifecycle order with their
 * Spanish labels, exactly as myapi_services_request_statuses() declares them.
 * The indirection exists so this page names the catalogue once, and so the
 * unit test can assert the two are the same array.
 *
 * @return array
 *   Stored value => human label.
 */
function myapi_service_requests_status_options() {
  return myapi_services_request_statuses();
}

/**
 * The category select options, off the 'service_category' vocabulary.
 *
 * Its own helper rather than myapi_service_category_provider_counts(), which
 * answers this same list with a count attached: that one lives in
 * resources/service_category.resource.inc and Regla 5 de CLAUDE.md keeps this
 * file out of resources/. What is needed here is the plain tid => name map a
 * <select> takes, which is one taxonomy_get_tree() call.
 *
 * A site whose vocabulary is missing answers an empty array rather than a
 * notice — the degraded case of an environment that never ran the services
 * install, and the same criterion myapi_claims_field_allowed_values() applies
 * to a missing field.
 *
 * @return array
 *   tid => term name, in the vocabulary's own order.
 */
function myapi_service_requests_category_options() {
  $vocabulary = taxonomy_vocabulary_machine_name_load(MYAPI_SERVICES_CATEGORY_VOCABULARY);

  if (!is_object($vocabulary) || empty($vocabulary->vid)) {
    return array();
  }

  $options = array();
  foreach (taxonomy_get_tree($vocabulary->vid) as $term) {
    $options[(int) $term->tid] = $term->name;
  }

  return $options;
}

/**
 * Reads and validates the status/category/date_from/date_to GET parameters.
 *
 * The condominium parameter is deliberately NOT here, for the reason
 * myapi_claims_list_filters() gives: resolving it needs the user's
 * assigned-condominiums scope (SPEC 49), which the page already reuses from
 * the calendar's helpers.
 *
 * The category is validated as a POSITIVE INTEGER ONLY. Whether that tid is a
 * real term of the vocabulary is decided by
 * myapi_service_requests_effective_category() against the options the page has
 * already loaded for the select — the same split the condominium gets, and
 * what keeps this function from touching the taxonomy at all.
 *
 * @return array
 *   Keys: status, category, date_from, date_to. Each is the validated value
 *   (int for the category, 'Y-m-d' string for the dates) or NULL for
 *   "no filter".
 */
function myapi_service_requests_list_filters() {
  // Reused for the exact same 'Y-m-d' + checkdate() rule the reservation
  // endpoints, the calendar and the claims listing already validate dates
  // with, and for myapi_calendar_positive_int() — the same "a positive integer
  // or nothing" reading the calendar gives its own ?condominium.
  module_load_include('inc', 'myapi', 'resources/reservation.resource');
  module_load_include('inc', 'myapi', 'includes/myapi.reservation_calendar');

  $status = NULL;
  if (isset($_GET['status']) && is_scalar($_GET['status'])) {
    $statuses = myapi_service_requests_status_options();
    if (isset($statuses[(string) $_GET['status']])) {
      $status = (string) $_GET['status'];
    }
  }

  $category = NULL;
  if (isset($_GET['category'])) {
    $category = myapi_calendar_positive_int($_GET['category']);
  }

  $date_from = NULL;
  if (isset($_GET['date_from']) && is_scalar($_GET['date_from'])) {
    $date_from = myapi_reservation_valid_date((string) $_GET['date_from']);
  }

  $date_to = NULL;
  if (isset($_GET['date_to']) && is_scalar($_GET['date_to'])) {
    $date_to = myapi_reservation_valid_date((string) $_GET['date_to']);
  }

  return array(
    'status'    => $status,
    'category'  => $category,
    'date_from' => $date_from,
    'date_to'   => $date_to,
  );
}

/**
 * Keeps the requested category only when it is one of the offered terms.
 *
 * Mirror of myapi_calendar_effective_condominium() and there for the same
 * reason: the value that reaches the query has to be one the reader could have
 * chosen from the select, so a hand-typed tid of a deleted term filters
 * nothing instead of emptying the listing with no explanation.
 *
 * Unlike the condominium, this is NOT an access rule — every operator who
 * reaches this page sees every category — so a foreign tid is simply dropped
 * and the select comes back on '- Todas -'.
 *
 * @param int|null $requested
 *   The tid from myapi_service_requests_list_filters(), already an int or NULL.
 * @param array $options
 *   tid => name, from myapi_service_requests_category_options().
 *
 * @return int|null
 *   The tid to filter by, or NULL for no filter.
 */
function myapi_service_requests_effective_category($requested, array $options) {
  if ($requested === NULL) {
    return NULL;
  }

  return isset($options[$requested]) ? (int) $requested : NULL;
}

/**
 * Turns the two validated 'Y-m-d' bounds into the timestamps n.created is
 * compared with.
 *
 * Separate from myapi_service_requests_list_filters() because the two answers
 * have different consumers: the filter form repaints the <input type="date">
 * elements with the STRINGS, and the query compares INTEGERS against a node
 * column. Folding them together would force one of the two to convert back.
 *
 * Each bound covers its whole day — 00:00:00 for the lower one, 23:59:59 for
 * the upper — so '?date_to=2026-09-04' includes a request created at 16:45
 * that day. Same criterion as myapi_service_request_parse_created_range(); see
 * the @file block for why it is not called.
 *
 * The two are independent: either alone is a half-open range.
 *
 * @param string|null $date_from
 * @param string|null $date_to
 *
 * @return array
 *   ['from' => int|NULL, 'to' => int|NULL].
 */
function myapi_service_requests_created_range($date_from, $date_to) {
  return array(
    'from' => $date_from !== NULL ? strtotime($date_from . ' 00:00:00') : NULL,
    'to'   => $date_to !== NULL ? strtotime($date_to . ' 23:59:59') : NULL,
  );
}

/* ---------------------------------------------------------------------------
 * The query.
 * ------------------------------------------------------------------------ */

/**
 * The listing query: one row per published service request (SPEC 125).
 *
 * ->addTag('node_access') IS THE ACCESS RULE OF THIS PAGE, and the only one.
 * It is what makes myapi_building_admin_alter_node_query() narrow the result
 * to the condominiums assigned to an 'administrador edificio' (SPEC 49/125),
 * and it is why no condominium list is passed in here and no second filter
 * repeats that rule: writing it twice is how the two drift. Losing this single
 * call does not break the page — it silently lists every request of every
 * condominium of the site, requester names included, which is why
 * ServiceRequestsAdminPageTest pins it.
 *
 * For 'administrator' and 'backend' the tag costs nothing: guard 1 of both
 * alters returns immediately for an account holding neither role.
 *
 * EVERY JOIN IS LEFT, WITHOUT EXCEPTION, and that is the deliberate difference
 * with myapi_service_request_base_query(), which INNERs the category and the
 * requester. The two answer different readers: the app of a resident must not
 * list a broken request, and this page is where a broken request gets FOUND
 * and fixed. A request with no category, no requester or a condominium pointing
 * at a deleted node keeps its row here and shows an em dash in that cell.
 *
 * The awarded provider is projected TWICE, resolved and raw:
 *
 *   assigned_provider_id / assigned_provider_name — the joined node, NULL when
 *     the reference points at an unpublished or deleted provider.
 *   assigned_provider_raw — the field's target_id, however broken. This is
 *     what myapi_service_requests_provider_label() reads to tell "not awarded"
 *     from "awarded to something that is gone".
 *
 * field_assigned_offer is deliberately absent: a 'direct' request has a
 * provider and no offer at all (SPEC 87), so reading the award through the
 * offer would blank the column for every direct request.
 *
 * No join can multiply rows: the six fields joined have cardinality 1, and
 * node.nid, users.uid and taxonomy_term_data.tid are primary keys.
 *
 * @param int|null $condominium_id
 *   'condominio' nid, compared against the RAW target_id.
 * @param string|null $status
 *   One key of myapi_services_request_statuses().
 * @param int|null $category_id
 *   Term id of the 'service_category' vocabulary.
 * @param int|null $created_from
 *   Unix timestamp, compared n.created >=. From
 *   myapi_service_requests_created_range().
 * @param int|null $created_to
 *   Unix timestamp, compared n.created <=.
 *
 * @return SelectQuery
 *   Extended with PagerDefault and limited to 20 rows; the caller executes it.
 */
function myapi_service_requests_list_rows($condominium_id = NULL, $status = NULL, $category_id = NULL, $created_from = NULL, $created_to = NULL) {
  $query = db_select('node', 'n')
    ->fields('n', array('nid'))
    ->condition('n.type', MYAPI_SERVICES_REQUEST_TYPE)
    ->condition('n.status', 1);
  $query->addTag('node_access');

  $query->addField('n', 'title', 'title');
  // The creation date is a column of the node itself, so the sixth column of
  // the table costs no join and cannot multiply a row.
  $query->addField('n', 'created', 'created');

  // The condominium. Its name is the node title, unlike the unit's. The bundle
  // and the published flag travel in the join so a reference to something else
  // can never paint its title here.
  $query->leftJoin('field_data_field_condominium', 'fco', "fco.entity_id = n.nid AND fco.entity_type = 'node' AND fco.deleted = 0");
  $query->leftJoin('node', 'nc', "nc.nid = fco.field_condominium_target_id AND nc.type = 'condominio' AND nc.status = 1");
  $query->addField('nc', 'nid', 'condominium_id');
  $query->addField('nc', 'title', 'condominium_title');

  $query->leftJoin('field_data_field_request_status', 'frs', "frs.entity_id = n.nid AND frs.entity_type = 'node' AND frs.deleted = 0");
  $query->addField('frs', 'field_request_status_value', 'status');

  // LEFT on BOTH tables — the API's base query INNERs the two, see the
  // docblock. An orphan tid answers a row with no category name instead of no
  // row.
  $query->leftJoin('field_data_field_category', 'fcat', "fcat.entity_id = n.nid AND fcat.entity_type = 'node' AND fcat.deleted = 0");
  $query->addField('fcat', 'field_category_tid', 'category_id');
  $query->leftJoin('taxonomy_term_data', 'td', 'td.tid = fcat.field_category_tid');
  $query->addField('td', 'name', 'category_name');

  $query->leftJoin('field_data_field_requester', 'fr', "fr.entity_id = n.nid AND fr.entity_type = 'node' AND fr.deleted = 0");
  $query->addField('fr', 'field_requester_target_id', 'requester_uid');
  $query->leftJoin('users', 'u', 'u.uid = fr.field_requester_target_id');
  $query->addField('u', 'name', 'requester_name');

  // The stored 'Y-m-d H:i:s' value, printed with its time like every other
  // date of this back office.
  $query->leftJoin('field_data_field_desired_start', 'fds', "fds.entity_id = n.nid AND fds.entity_type = 'node' AND fds.deleted = 0");
  $query->addField('fds', 'field_desired_start_value', 'desired_start');

  // The awarded provider, resolved and raw — see the docblock.
  $query->leftJoin('field_data_field_assigned_provider', 'fap', "fap.entity_id = n.nid AND fap.entity_type = 'node' AND fap.deleted = 0");
  $query->leftJoin('node', 'np', 'np.nid = fap.field_assigned_provider_target_id AND np.type = :provider_type AND np.status = 1', array(':provider_type' => MYAPI_SERVICES_PROVIDER_TYPE));
  $query->addField('np', 'nid', 'assigned_provider_id');
  $query->addField('np', 'title', 'assigned_provider_name');
  $query->addField('fap', 'field_assigned_provider_target_id', 'assigned_provider_raw');

  // Optional filters, applied on the left-joined columns so a request with no
  // value in one of them is also excluded when filtering by it — the desired
  // reading of "show me the open ones".
  if ($condominium_id !== NULL) {
    $query->condition('fco.field_condominium_target_id', $condominium_id);
  }
  if ($status !== NULL) {
    $query->condition('frs.field_request_status_value', $status);
  }
  if ($category_id !== NULL) {
    $query->condition('fcat.field_category_tid', $category_id);
  }
  // Numbers, never dates: each bound already IS the instant that makes its day
  // inclusive, and the conversion lives in
  // myapi_service_requests_created_range(). The test is !== NULL and not
  // !empty(): 0 is a legal timestamp (the epoch) and !empty() would silently
  // drop a bound of 1970-01-01 instead of filtering by it.
  if ($created_from !== NULL) {
    $query->condition('n.created', $created_from, '>=');
  }
  if ($created_to !== NULL) {
    $query->condition('n.created', $created_to, '<=');
  }

  $query->orderBy('n.nid', 'DESC');

  return $query->extend('PagerDefault')->limit(20);
}

/* ---------------------------------------------------------------------------
 * Row labels.
 *
 * Every one of them answers ALREADY ESCAPED text and falls back to an em dash
 * instead of an empty cell, because a blank cell reads as "nobody filled this
 * in" for values that are simply not applicable yet.
 * ------------------------------------------------------------------------ */

/**
 * Human label for the status of a listing row.
 *
 * A key the catalogue does not know prints RAW rather than disappearing: a
 * request whose field was edited by hand into an unknown value is exactly what
 * the operator needs to see (SPEC 125, risk 8).
 *
 * @param string|null $value
 *
 * @return string
 *   Already escaped.
 */
function myapi_service_requests_status_label($value) {
  if ($value === NULL) {
    return '—';
  }

  $options = myapi_service_requests_status_options();

  return check_plain(isset($options[$value]) ? $options[$value] : $value);
}

/**
 * Human label for a free-text cell: the condominium title, the category name.
 *
 * Escaped because both are printed into an HTML table and both are editable
 * from the site's own UI.
 *
 * @param string|null $value
 *
 * @return string
 *   Already escaped.
 */
function myapi_service_requests_text_label($value) {
  if ($value === NULL || $value === '') {
    return '—';
  }

  return check_plain($value);
}

/**
 * Human label for either of the two date columns.
 *
 * ONE function for both because the columns arrive in different shapes and
 * mean the same thing to a reader: n.created is a Unix timestamp, and
 * field_desired_start is a 'Y-m-d H:i:s' datetime string. A date that cannot be
 * read at all answers the em dash rather than 01/01/1970, which is what an
 * unparsed value would print.
 *
 * 'd/m/Y H:i', the format the claims listing and the transaction timeline
 * already use.
 *
 * @param int|string|null $value
 *
 * @return string
 *   Already escaped.
 */
function myapi_service_requests_date_label($value) {
  if ($value === NULL || $value === '') {
    return '—';
  }

  $timestamp = is_numeric($value) ? (int) $value : strtotime($value);

  if ($timestamp === FALSE || $timestamp === NULL) {
    return '—';
  }

  return check_plain(format_date($timestamp, 'custom', 'd/m/Y H:i'));
}

/**
 * Human label for the requester of a listing row.
 *
 * Same three-way pattern as myapi_claims_requester_label(): no requester set,
 * account deleted, or the name. The three say different things and a single
 * em dash for all of them would hide the middle one.
 *
 * @param object $row
 *   Row from myapi_service_requests_list_rows().
 *
 * @return string
 *   Already escaped.
 */
function myapi_service_requests_requester_label($row) {
  if ($row->requester_uid === NULL) {
    return t('Sin solicitante');
  }
  if ($row->requester_name === NULL) {
    return t('Usuario eliminado (#@uid)', array('@uid' => $row->requester_uid));
  }

  return check_plain($row->requester_name);
}

/**
 * Human label for the awarded provider of a listing row.
 *
 * READS THE RAW target_id AND THE RESOLVED NODE, and that is the whole point
 * of the query projecting both. "Not awarded yet" and "awarded to a provider
 * that was unpublished or deleted" are different facts about a request, and
 * collapsing them into one em dash would hide a broken award behind a request
 * that simply has not been assigned.
 *
 * field_assigned_offer is never consulted: a 'direct' request has a provider
 * and no offer at all (SPEC 87), so the awarded provider is always read from
 * its own field.
 *
 * @param object $row
 *   Row from myapi_service_requests_list_rows().
 *
 * @return string
 *   Already escaped.
 */
function myapi_service_requests_provider_label($row) {
  if ($row->assigned_provider_raw === NULL) {
    return '—';
  }
  if ($row->assigned_provider_name === NULL) {
    return t('Proveedor eliminado (#@nid)', array('@nid' => $row->assigned_provider_raw));
  }

  return check_plain($row->assigned_provider_name);
}

/**
 * The link of the ID cell: the supervision detail of SPEC 126.
 *
 * THE SAME DESTINATION FOR EVERY READER, unlike the title cell right below:
 * the detail is read-only and its three roles are exactly the three roles of
 * this listing, so there is nothing per-reader to decide. An
 * 'administrador edificio' whose condominium does not own the request never
 * sees the row in the first place — the node_access tag on the query already
 * took it out — so the link can never lead to a 403 the listing did not
 * already prevent.
 *
 * THE ID AND NOT A TENTH COLUMN, and not the title either (SPEC 126,
 * decision 4). The title already means "open the node" and its destination
 * depends on the reader's permission; repointing it would cost 'backend' the
 * one-click path to the form where transactions are created. The id is the
 * record's identity, so linking it to the record's own screen costs a cell
 * and no column.
 *
 * @param object $row
 *   Row from myapi_service_requests_list_rows().
 *
 * @return string
 *   The rendered link; l() escapes the text.
 */
function myapi_service_requests_detail_link($row) {
  $nid = (int) $row->nid;

  return l((string) $nid, 'admin/content/service-requests/' . $nid);
}

/**
 * The link of the title cell: node/%nid/edit, or node/%nid for a reader who
 * cannot edit.
 *
 * THE ONE CELL THAT CHANGES PER READER (SPEC 125). 'backend' and
 * 'administrator' go to the edit form, which is where the transaction timeline
 * of SPEC 94 lives. An 'administrador edificio' holds no write permission over
 * this bundle — that is the whole point of it being in the role's READ-ONLY
 * catalogue — so sending it to /edit would answer a 403 on every row.
 *
 * The decision itself is NOT made here: the caller passes it, already
 * resolved, so this stays testable without a booted site.
 *
 * An empty title falls back to the nid: an untitled request must still be
 * reachable, and an empty <a> is an unclickable cell.
 *
 * @param object $row
 *   Row from myapi_service_requests_list_rows().
 * @param bool $can_edit
 *   Whether this reader may edit the node.
 *
 * @return string
 *   The rendered link; l() escapes the text.
 */
function myapi_service_requests_title_link($row, $can_edit) {
  $nid = (int) $row->nid;
  $title = ($row->title === NULL || $row->title === '') ? (string) $nid : $row->title;

  return l($title, 'node/' . $nid . ($can_edit ? '/edit' : ''));
}

/**
 * Builds the table body from the query rows.
 *
 * The nine cells of SPEC 125, in the order of the table header. Every optional
 * one degrades on its own, so a half-filled request keeps its row: this is the
 * screen where a broken request gets found.
 *
 * TWO OF THE NINE ARE LINKS, and they go to different places on purpose. The
 * ID opens the read-only supervision detail of SPEC 126, the same screen for
 * every reader; the title opens the node, and whether that is its editing
 * form depends on the reader. A request the detail query cannot read — no
 * category, no requester — still has a working ID link: that screen diagnoses
 * it instead of answering 404, which is the whole reason the listing may show
 * a row the detail query cannot.
 *
 * @param array $rows
 *   Rows fetched from myapi_service_requests_list_rows().
 * @param array $editable
 *   nid => bool, whether this reader may edit that node. A nid absent from the
 *   map is treated as NOT editable — the link that never 403s is the safe
 *   fallback.
 *
 * @return array
 *   One row per request, ready for '#theme' => 'table'.
 */
function myapi_service_requests_list_table_rows(array $rows, array $editable) {
  $table_rows = array();

  foreach ($rows as $row) {
    $nid = (int) $row->nid;

    $table_rows[] = array(
      myapi_service_requests_detail_link($row),
      myapi_service_requests_title_link($row, !empty($editable[$nid])),
      myapi_service_requests_text_label($row->condominium_title),
      myapi_service_requests_status_label($row->status),
      myapi_service_requests_requester_label($row),
      myapi_service_requests_date_label($row->created),
      myapi_service_requests_date_label($row->desired_start),
      myapi_service_requests_text_label($row->category_name),
      myapi_service_requests_provider_label($row),
    );
  }

  return $table_rows;
}

/* ---------------------------------------------------------------------------
 * Who may edit what.
 * ------------------------------------------------------------------------ */

/**
 * Resolves, for the rows of ONE page, whether the reader may edit each node.
 *
 * The answer feeds the title cell (see myapi_service_requests_title_link()),
 * and it is Drupal's own decision — node_access('update', $node) — rather than
 * a role check of ours: 'edit own' permissions, hook_node_access() and
 * 'bypass node access' all belong to it, and reimplementing any of them here
 * would eventually disagree with the 403 the user actually gets.
 *
 * TWO FAST PATHS THAT LOAD NO NODE AT ALL, because they answer the same for
 * every row of every page:
 *   - 'bypass node access' — an 'administrator'.
 *   - 'edit any service_request content' — the 'backend' operator this page
 *     was built for.
 * Both mean "every row is editable", and short-circuiting them is what keeps
 * the common reader from paying for 20 node loads per page.
 *
 * Anything else — an 'administrador edificio', who holds NO write permission
 * over this bundle by design (SPEC 125) — falls to the slow path, which loads
 * the page's nodes in ONE batch and asks Drupal per row. It is one batch and
 * not 20 node_load() calls because node_load_multiple() runs field_attach_load()
 * once for the whole set.
 *
 * @param array $rows
 *   Rows fetched from myapi_service_requests_list_rows().
 *
 * @return array
 *   nid => bool. A nid absent from the map is read as "not editable" by
 *   myapi_service_requests_list_table_rows().
 */
function myapi_service_requests_editable_map(array $rows) {
  if (!$rows) {
    return array();
  }

  $nids = array();
  foreach ($rows as $row) {
    $nids[] = (int) $row->nid;
  }

  if (user_access('bypass node access') || user_access('edit any ' . MYAPI_SERVICES_REQUEST_TYPE . ' content')) {
    return array_fill_keys($nids, TRUE);
  }

  $map = array();
  foreach (node_load_multiple($nids) as $nid => $node) {
    $map[(int) $nid] = (bool) node_access('update', $node);
  }

  return $map;
}

/* ---------------------------------------------------------------------------
 * Filter form.
 * ------------------------------------------------------------------------ */

/**
 * Builds the filter form of the service-requests listing.
 *
 * #method = 'get', same criterion as myapi_claims_list_filter_form() and
 * myapi_reservation_calendar_filter_form(): every filter ends up in the query
 * string, so the URL is linkable and the pager keeps the filters across pages.
 * Drupal's own hidden fields are dropped by the SAME after-build callback the
 * calendar and the claims listing use — it is entirely generic, it only unsets
 * three keys — so it is reused rather than copied.
 *
 * @param array $form
 * @param array $form_state
 * @param array $filters
 *   Validated filters, used as defaults.
 * @param array $condominium_options
 *   nid => title, already narrowed to the reader's scope.
 * @param array $category_options
 *   tid => name.
 */
function myapi_service_requests_list_filter_form($form, &$form_state, $filters, $condominium_options, $category_options) {
  $form['#method'] = 'get';
  // Hook for css/myapi.claims.css, which lays the filters out on one row. The
  // claims sheet and not one of its own: it is the same row (SPEC 125).
  $form['#attributes']['class'][] = 'myapi-service-requests-filters';
  // No CSRF token: a read-only GET filter has nothing to forge, and the token
  // would show up in the URL.
  $form['#token'] = FALSE;
  $form['#after_build'][] = 'myapi_reservation_calendar_filter_form_after_build';
  $form['#action'] = url('admin/content/service-requests');

  // With clean URLs off the path itself travels in ?q=, and a browser
  // submitting a GET form replaces the whole query string of the action — see
  // myapi_reservation_calendar_filter_form() for the same fix.
  if (!variable_get('clean_url', 0)) {
    $form['q'] = array(
      '#type'  => 'hidden',
      '#value' => 'admin/content/service-requests',
    );
  }

  $form['condominium'] = array(
    '#type'          => 'select',
    '#title'         => t('Condominio'),
    '#options'       => array('' => t('- Todos -')) + $condominium_options,
    '#default_value' => $filters['condominium'] === NULL ? '' : $filters['condominium'],
  );

  $form['status'] = array(
    '#type'          => 'select',
    '#title'         => t('Estado'),
    '#options'       => array('' => t('- Todos -')) + myapi_service_requests_status_options(),
    '#default_value' => $filters['status'] === NULL ? '' : $filters['status'],
  );

  $form['category'] = array(
    '#type'          => 'select',
    '#title'         => t('Categoría'),
    '#options'       => array('' => t('- Todas -')) + $category_options,
    '#default_value' => $filters['category'] === NULL ? '' : $filters['category'],
  );

  // Native HTML5 date inputs, written by hand for the same reason the calendar
  // and the claims listing write theirs: theme_textfield() forces type="text"
  // and would undo an #attributes override. A browser without support renders
  // a plain text box, which still submits 'YYYY-MM-DD' if typed by hand.
  $form['date_from'] = array(
    '#type'   => 'item',
    '#title'  => t('Fecha de creación desde'),
    '#markup' => '<input type="date" name="date_from" value="' . check_plain((string) $filters['date_from']) . '" />',
  );
  $form['date_to'] = array(
    '#type'   => 'item',
    '#title'  => t('Fecha de creación hasta'),
    '#markup' => '<input type="date" name="date_to" value="' . check_plain((string) $filters['date_to']) . '" />',
  );

  $form['submit'] = array(
    '#type'  => 'submit',
    '#value' => t('Filtrar'),
  );

  return $form;
}

/* ---------------------------------------------------------------------------
 * Page.
 * ------------------------------------------------------------------------ */

/**
 * Page callback of admin/content/service-requests.
 *
 * Read-only from end to end: it validates the filters, runs ONE paginated
 * query and renders a table. Nothing here creates, edits or cancels a request
 * — the row links to Drupal's own node pages and this module owns no screen of
 * its own for that. There is deliberately no "create" button either, unlike
 * the claims listing: a service request is born in the app, from a resident,
 * and the back office has no business inventing one.
 */
function myapi_service_requests_list_page() {
  module_load_include('inc', 'myapi', 'includes/myapi.reservation_calendar');

  drupal_add_css(drupal_get_path('module', 'myapi') . '/css/myapi.claims.css');

  // SPEC 49: NULL for a user who sees every condominium ('administrator',
  // 'backend'), the assigned list (possibly empty) for an 'administrador
  // edificio'. Everything below flows from it, same as the calendar and the
  // claims listing.
  $scope = myapi_calendar_condominium_scope();
  $condominium_options = myapi_calendar_condominium_options($scope);

  $requested = isset($_GET['condominium']) ? myapi_calendar_positive_int($_GET['condominium']) : NULL;
  $condominium = myapi_calendar_effective_condominium($requested, $scope);

  $filters = myapi_service_requests_list_filters();
  $category_options = myapi_service_requests_category_options();

  // A list (rather than a single nid) means no single condominium of this
  // user's was named — either none at all or somebody else's — so the select
  // must show nothing chosen and the query gets no condominium filter of its
  // own. The rows are still restricted to the assigned set: that is what
  // ->addTag('node_access') does, not this filter.
  $filters['condominium'] = is_array($condominium) ? NULL : $condominium;
  $filters['category'] = myapi_service_requests_effective_category($filters['category'], $category_options);

  $range = myapi_service_requests_created_range($filters['date_from'], $filters['date_to']);

  $query = myapi_service_requests_list_rows(
    $filters['condominium'],
    $filters['status'],
    $filters['category'],
    $range['from'],
    $range['to']
  );
  $rows = $query->execute()->fetchAll();

  $build = array();

  $build['filters'] = drupal_get_form(
    'myapi_service_requests_list_filter_form',
    $filters,
    $condominium_options,
    $category_options
  );

  $build['table'] = array(
    '#theme'  => 'table',
    '#header' => array(
      t('ID'),
      t('Título'),
      t('Condominio'),
      t('Estado'),
      t('Solicitante'),
      t('Fecha de creación'),
      t('Fecha deseada'),
      t('Categoría'),
      t('Proveedor adjudicado'),
    ),
    '#rows'   => myapi_service_requests_list_table_rows($rows, myapi_service_requests_editable_map($rows)),
    '#empty'  => t('No hay solicitudes de servicio para mostrar.'),
  );

  $build['pager'] = array(
    '#markup' => theme('pager'),
  );

  return $build;
}
