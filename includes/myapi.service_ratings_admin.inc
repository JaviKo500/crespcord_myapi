<?php

/**
 * @file
 * Back-office service-rating listing page (SPEC 131).
 *
 * Serves admin/content/service-ratings: a read-only, server-rendered,
 * paginated table of the ratings residents leave providers, for
 * 'administrator' and 'backend' only (myapi_marketplace_admin_access()). It is
 * NOT an API endpoint, and nothing under resources/ calls into it.
 *
 * Same mould as includes/myapi.service_requests_admin.inc (SPEC 125): GET
 * filters, one paginated query tagged 'node_access' living next to its page,
 * LEFT joins only, and an ID link resolved by node_access('update').
 *
 * Two things specific to this page:
 *
 *  - THE REQUEST IS REACHED THROUGH THE OFFER. A rating references an offer
 *    (field_rating_offer), and the offer references the request. There is no
 *    rating → request reference, so a rating of a 'direct' request — which
 *    has no offer at all (SPEC 87) — has no path to its request and reads
 *    'Proveedor directo'. Adding that reference is a schema change and out of
 *    SPEC 131 (risk 3).
 *  - THE COMMENT IS THE ONE FREE TEXT A RESIDENT WRITES INTO THIS PAGE. It is
 *    escaped, cut to 140 characters with truncate_utf8() — characters, not
 *    bytes — and carried whole in the title attribute.
 *
 * field_unit is shown although almost every rating has it empty today (risk
 * 2): SPEC 77 created the instance and no flow writes it yet.
 */

module_load_include('inc', 'myapi', 'includes/myapi.services_common');

/**
 * How many characters of a comment the cell shows, ellipsis included.
 */
if (!defined('MYAPI_SERVICE_RATINGS_ADMIN_COMMENT_LENGTH')) {
  define('MYAPI_SERVICE_RATINGS_ADMIN_COMMENT_LENGTH', 140);
}

/* ---------------------------------------------------------------------------
 * Filters.
 * ------------------------------------------------------------------------ */

/**
 * Reads and validates the provider/stars/date_from/date_to GET parameters.
 *
 * The provider is validated as a positive integer only; whether it is a
 * provider is decided against the select's options by
 * myapi_service_offers_admin_effective_option(). The stars must be a key of
 * myapi_services_star_values(). Garbage means no filter.
 *
 * @return array
 *   Keys: provider, stars (int), date_from, date_to ('Y-m-d'). NULL means no
 *   filter.
 */
function myapi_service_ratings_admin_list_filters() {
  module_load_include('inc', 'myapi', 'resources/reservation.resource');
  module_load_include('inc', 'myapi', 'includes/myapi.reservation_calendar');

  $provider = isset($_GET['provider']) ? myapi_calendar_positive_int($_GET['provider']) : NULL;

  $stars = isset($_GET['stars']) ? myapi_calendar_positive_int($_GET['stars']) : NULL;
  $star_values = myapi_services_star_values();
  if ($stars !== NULL && !isset($star_values[$stars])) {
    $stars = NULL;
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
    'provider'  => $provider,
    'stars'     => $stars,
    'date_from' => $date_from,
    'date_to'   => $date_to,
  );
}

/* ---------------------------------------------------------------------------
 * The query.
 * ------------------------------------------------------------------------ */

/**
 * The listing query: one row per published rating (SPEC 131).
 *
 * The offer → request hop is two LEFT joins: a rating with no offer keeps its
 * row, with NULL in every request column. The provider and the request are
 * projected resolved and raw, so a label can tell "none" from "gone"; the
 * offer is projected raw only, which is all 'Proveedor directo' needs.
 *
 * No join can multiply rows: every field joined has cardinality 1.
 *
 * @param int|null $provider_id
 *   'provider' nid, compared against the raw field_rating_provider.
 * @param int|null $stars
 *   A key of myapi_services_star_values().
 * @param int|null $created_from
 *   Unix timestamp, n.created >=.
 * @param int|null $created_to
 *   Unix timestamp, n.created <=.
 *
 * @return SelectQuery
 *   Extended with PagerDefault and limited to 20 rows; the caller executes it.
 */
function myapi_service_ratings_admin_list_rows($provider_id = NULL, $stars = NULL, $created_from = NULL, $created_to = NULL) {
  $query = db_select('node', 'n')
    ->fields('n', array('nid', 'created'))
    ->condition('n.type', MYAPI_SERVICES_RATING_TYPE)
    ->condition('n.status', 1);
  $query->addTag('node_access');

  $query->leftJoin('field_data_field_rating_provider', 'frp', "frp.entity_id = n.nid AND frp.entity_type = 'node' AND frp.deleted = 0");
  $query->addField('frp', 'field_rating_provider_target_id', 'provider_raw');
  $query->leftJoin('node', 'np', 'np.nid = frp.field_rating_provider_target_id AND np.type = :provider_type', array(':provider_type' => MYAPI_SERVICES_PROVIDER_TYPE));
  $query->addField('np', 'title', 'provider_title');

  $query->leftJoin('field_data_field_stars', 'fst', "fst.entity_id = n.nid AND fst.entity_type = 'node' AND fst.deleted = 0");
  $query->addField('fst', 'field_stars_value', 'stars');

  $query->leftJoin('field_data_field_rating_comment', 'frc', "frc.entity_id = n.nid AND frc.entity_type = 'node' AND frc.deleted = 0");
  $query->addField('frc', 'field_rating_comment_value', 'comment');

  // Rating → offer → request. Optional since SPEC 87: a 'direct' request has
  // no offer to rate through.
  $query->leftJoin('field_data_field_rating_offer', 'fro', "fro.entity_id = n.nid AND fro.entity_type = 'node' AND fro.deleted = 0");
  $query->addField('fro', 'field_rating_offer_target_id', 'offer_raw');
  $query->leftJoin('field_data_field_request', 'freq', "freq.entity_id = fro.field_rating_offer_target_id AND freq.entity_type = 'node' AND freq.deleted = 0");
  $query->addField('freq', 'field_request_target_id', 'request_raw');
  $query->leftJoin('node', 'nr', 'nr.nid = freq.field_request_target_id AND nr.type = :request_type', array(':request_type' => MYAPI_SERVICES_REQUEST_TYPE));
  $query->addField('nr', 'nid', 'request_id');
  $query->addField('nr', 'title', 'request_title');

  $query->leftJoin('field_data_field_unit', 'fun', "fun.entity_id = n.nid AND fun.entity_type = 'node' AND fun.deleted = 0");
  $query->leftJoin('node', 'nu', "nu.nid = fun.field_unit_target_id AND nu.type = 'vivienda'");
  $query->addField('nu', 'title', 'unit_title');

  if ($provider_id !== NULL) {
    $query->condition('frp.field_rating_provider_target_id', $provider_id);
  }
  if ($stars !== NULL) {
    $query->condition('fst.field_stars_value', $stars);
  }
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
 * Row labels. All of them answer ALREADY ESCAPED text.
 * ------------------------------------------------------------------------ */

/**
 * The stars cell: '★★★★☆ (4)'. A value outside the catalogue prints raw.
 *
 * @param int|string|null $value
 *
 * @return string
 */
function myapi_service_ratings_admin_stars_label($value) {
  if ($value === NULL || $value === '') {
    return '—';
  }

  $star_values = myapi_services_star_values();
  $stars = (int) $value;
  if ((string) $stars !== (string) $value || !isset($star_values[$stars])) {
    return check_plain((string) $value);
  }

  $max = max(array_keys($star_values));

  return str_repeat('★', $stars) . str_repeat('☆', $max - $stars) . ' (' . $stars . ')';
}

/**
 * The comment cell: cut to 140 characters, the whole text in the title.
 *
 * Cut BEFORE escaping, so an entity is never split, and with truncate_utf8(),
 * which counts characters, so a multibyte one is never split either. The
 * ellipsis is inside the 140.
 *
 * @param string|null $value
 *
 * @return string
 */
function myapi_service_ratings_admin_comment_label($value) {
  $text = $value === NULL ? '' : trim($value);
  if ($text === '') {
    return '—';
  }

  $short = truncate_utf8($text, MYAPI_SERVICE_RATINGS_ADMIN_COMMENT_LENGTH, FALSE, TRUE);

  return '<span title="' . check_plain($text) . '">' . check_plain($short) . '</span>';
}

/**
 * The Solicitud cell, reached through the offer.
 *
 *   - no offer: 'Proveedor directo' (SPEC 87);
 *   - an offer with no request — the offer is gone, or lost its reference:
 *     the em dash;
 *   - a request that is gone: 'Solicitud eliminada (#id)';
 *   - otherwise the title, linked to the supervision detail of SPEC 126.
 *
 * @param object $row
 *
 * @return string
 */
function myapi_service_ratings_admin_request_label($row) {
  if ($row->offer_raw === NULL) {
    return t('Proveedor directo');
  }
  if ($row->request_raw === NULL) {
    return '—';
  }
  if ($row->request_id === NULL) {
    return t('Solicitud eliminada (#@nid)', array('@nid' => $row->request_raw));
  }

  $nid = (int) $row->request_id;
  $title = ($row->request_title === NULL || $row->request_title === '') ? (string) $nid : $row->request_title;

  return l($title, 'admin/content/service-requests/' . $nid);
}

/**
 * The Proveedor cell: no provider, a provider that is gone, or its name.
 *
 * @param object $row
 *
 * @return string
 */
function myapi_service_ratings_admin_provider_label($row) {
  if ($row->provider_raw === NULL) {
    return '—';
  }
  if ($row->provider_title === NULL) {
    return t('Proveedor eliminado (#@nid)', array('@nid' => $row->provider_raw));
  }

  return check_plain($row->provider_title);
}

/**
 * Builds the table body: the seven cells of SPEC 131, in header order.
 *
 * @param array $rows
 *   Rows fetched from myapi_service_ratings_admin_list_rows().
 * @param array $editable
 *   nid => bool. Absent means NOT editable.
 *
 * @return array
 *   Ready for '#theme' => 'table'.
 */
function myapi_service_ratings_admin_table_rows(array $rows, array $editable) {
  $table_rows = array();

  foreach ($rows as $row) {
    $nid = (int) $row->nid;

    $table_rows[] = array(
      l((string) $nid, 'node/' . $nid . (!empty($editable[$nid]) ? '/edit' : '')),
      check_plain(format_date((int) $row->created, 'custom', 'd/m/Y H:i')),
      myapi_service_ratings_admin_provider_label($row),
      myapi_service_ratings_admin_stars_label($row->stars),
      myapi_service_ratings_admin_comment_label($row->comment),
      myapi_service_ratings_admin_request_label($row),
      ($row->unit_title === NULL || $row->unit_title === '') ? '—' : check_plain($row->unit_title),
    );
  }

  return $table_rows;
}

/**
 * Resolves, for the rows of ONE page, whether the reader may edit each node:
 * node_access('update') over ONE node_load_multiple(), short-circuited with no
 * load for 'bypass node access'.
 *
 * @param array $rows
 *
 * @return array
 *   nid => bool.
 */
function myapi_service_ratings_admin_editable_map(array $rows) {
  if (!$rows) {
    return array();
  }

  $nids = array();
  foreach ($rows as $row) {
    $nids[] = (int) $row->nid;
  }

  if (user_access('bypass node access')) {
    return array_fill_keys($nids, TRUE);
  }

  $map = array();
  foreach (node_load_multiple($nids) as $nid => $node) {
    $map[(int) $nid] = (bool) node_access('update', $node);
  }

  return $map;
}

/* ---------------------------------------------------------------------------
 * Filter form and page.
 * ------------------------------------------------------------------------ */

/**
 * Builds the filter form of the rating listing. GET, no token, the calendar's
 * generic after-build, the hidden q for clean_url = 0.
 *
 * @param array $form
 * @param array $form_state
 * @param array $filters
 * @param array $provider_options
 *   nid => title.
 */
function myapi_service_ratings_admin_filter_form($form, &$form_state, $filters, $provider_options) {
  $form['#method'] = 'get';
  $form['#attributes']['class'][] = 'myapi-service-ratings-filters';
  $form['#token'] = FALSE;
  $form['#after_build'][] = 'myapi_reservation_calendar_filter_form_after_build';
  $form['#action'] = url('admin/content/service-ratings');

  if (!variable_get('clean_url', 0)) {
    $form['q'] = array(
      '#type'  => 'hidden',
      '#value' => 'admin/content/service-ratings',
    );
  }

  $form['provider'] = array(
    '#type'          => 'select',
    '#title'         => t('Proveedor'),
    '#options'       => array('' => t('- Todos -')) + $provider_options,
    '#default_value' => $filters['provider'] === NULL ? '' : $filters['provider'],
  );

  $form['stars'] = array(
    '#type'          => 'select',
    '#title'         => t('Estrellas'),
    '#options'       => array('' => t('- Todas -')) + myapi_services_star_values(),
    '#default_value' => $filters['stars'] === NULL ? '' : $filters['stars'],
  );

  $form['date_from'] = array(
    '#type'   => 'item',
    '#title'  => t('Fecha desde'),
    '#markup' => '<input type="date" name="date_from" value="' . check_plain((string) $filters['date_from']) . '" />',
  );
  $form['date_to'] = array(
    '#type'   => 'item',
    '#title'  => t('Fecha hasta'),
    '#markup' => '<input type="date" name="date_to" value="' . check_plain((string) $filters['date_to']) . '" />',
  );

  $form['submit'] = array(
    '#type'  => 'submit',
    '#value' => t('Filtrar'),
  );

  return $form;
}

/**
 * Page callback of admin/content/service-ratings.
 */
function myapi_service_ratings_admin_page() {
  module_load_include('inc', 'myapi', 'includes/myapi.reservation_calendar');
  // myapi_service_requests_created_range(): the same whole-day bounds.
  module_load_include('inc', 'myapi', 'includes/myapi.service_requests_admin');
  // The provider select and its validation are the offer listing's.
  module_load_include('inc', 'myapi', 'includes/myapi.service_offers_admin');

  drupal_add_css(drupal_get_path('module', 'myapi') . '/css/myapi.claims.css');

  $provider_options = myapi_service_offers_admin_provider_options();

  $filters = myapi_service_ratings_admin_list_filters();
  $filters['provider'] = myapi_service_offers_admin_effective_option($filters['provider'], $provider_options);

  $range = myapi_service_requests_created_range($filters['date_from'], $filters['date_to']);

  $rows = myapi_service_ratings_admin_list_rows(
    $filters['provider'],
    $filters['stars'],
    $range['from'],
    $range['to']
  )->execute()->fetchAll();

  $build = array();

  $build['filters'] = drupal_get_form('myapi_service_ratings_admin_filter_form', $filters, $provider_options);

  $build['table'] = array(
    '#theme'  => 'table',
    '#header' => array(
      t('ID'),
      t('Fecha'),
      t('Proveedor'),
      t('Estrellas'),
      t('Comentario'),
      t('Solicitud'),
      t('Vivienda'),
    ),
    '#rows'   => myapi_service_ratings_admin_table_rows($rows, myapi_service_ratings_admin_editable_map($rows)),
    '#empty'  => t('No hay resultados para los filtros seleccionados.'),
  );

  $build['pager'] = array(
    '#markup' => theme('pager'),
  );

  return $build;
}
