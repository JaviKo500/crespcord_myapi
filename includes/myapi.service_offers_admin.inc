<?php

/**
 * @file
 * Back-office service-offer listing page (SPEC 131).
 *
 * Serves admin/content/service-offers: a read-only, server-rendered, paginated
 * table of the offers providers send, for 'administrator' and 'backend' only
 * (myapi_marketplace_admin_access()). It is NOT an API endpoint, and nothing
 * under resources/ calls into it.
 *
 * Same mould as includes/myapi.service_requests_admin.inc (SPEC 125): GET
 * filters, one paginated query tagged 'node_access' living next to its page,
 * LEFT joins only, and an ID link resolved by node_access('update').
 *
 * Two things specific to this page:
 *
 *  - AN OFFER HAS NO CONDOMINIUM OF ITS OWN. The column and the filter read
 *    the condominium of the offer's REQUEST: field_condominium is joined on
 *    the request's nid, not on the offer's.
 *  - THE SOLICITUD COLUMN OPENS THE SUPERVISION DETAIL of SPEC 126
 *    (admin/content/service-requests/%nid), the same screen for every reader.
 *    Only the ID depends on whether the reader may edit the offer.
 *
 * Catalogues are read, never redeclared: myapi_services_offer_statuses() and
 * myapi_services_offer_amount_types() (includes/myapi.services_common.inc).
 */

module_load_include('inc', 'myapi', 'includes/myapi.services_common');

/* ---------------------------------------------------------------------------
 * Options.
 * ------------------------------------------------------------------------ */

/**
 * The status select options: the offer catalogue of SPEC 77, untouched.
 *
 * @return array
 *   Stored value => human label.
 */
function myapi_service_offers_admin_status_options() {
  return myapi_services_offer_statuses();
}

/**
 * The provider select options: every provider, published or not.
 *
 * Unpublished ones included: an offer sent by a provider that was suspended
 * later is still an offer, and the operator must be able to find it. Also
 * used by the service-ratings listing, for the same select.
 *
 * @return array
 *   nid => title, ordered by title.
 */
function myapi_service_offers_admin_provider_options() {
  $query = db_select('node', 'n')
    ->fields('n', array('nid', 'title'))
    ->condition('n.type', MYAPI_SERVICES_PROVIDER_TYPE);
  $query->orderBy('n.title');

  return $query->execute()->fetchAllKeyed();
}

/* ---------------------------------------------------------------------------
 * Filters.
 * ------------------------------------------------------------------------ */

/**
 * Reads and validates the GET parameters.
 *
 * The condominium and the provider are validated as positive integers only;
 * whether they are among the select's options is decided by
 * myapi_service_offers_admin_effective_option(). Garbage means no filter.
 *
 * @return array
 *   Keys: condominium, provider (int), status, date_from, date_to ('Y-m-d').
 *   NULL means no filter.
 */
function myapi_service_offers_admin_list_filters() {
  module_load_include('inc', 'myapi', 'resources/reservation.resource');
  module_load_include('inc', 'myapi', 'includes/myapi.reservation_calendar');

  $condominium = isset($_GET['condominium']) ? myapi_calendar_positive_int($_GET['condominium']) : NULL;
  $provider = isset($_GET['provider']) ? myapi_calendar_positive_int($_GET['provider']) : NULL;

  $status = NULL;
  if (isset($_GET['status']) && is_scalar($_GET['status'])) {
    $statuses = myapi_service_offers_admin_status_options();
    if (isset($statuses[(string) $_GET['status']])) {
      $status = (string) $_GET['status'];
    }
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
    'condominium' => $condominium,
    'provider'    => $provider,
    'status'      => $status,
    'date_from'   => $date_from,
    'date_to'     => $date_to,
  );
}

/**
 * Keeps a requested id only when it is one of the select's options.
 *
 * Same shape as myapi_service_requests_effective_category(): a hand-typed id
 * of something that is not offered filters nothing.
 *
 * @param int|null $requested
 * @param array $options
 *   id => label.
 *
 * @return int|null
 */
function myapi_service_offers_admin_effective_option($requested, array $options) {
  if ($requested === NULL) {
    return NULL;
  }

  return isset($options[$requested]) ? (int) $requested : NULL;
}

/* ---------------------------------------------------------------------------
 * The query.
 * ------------------------------------------------------------------------ */

/**
 * The listing query: one row per published offer (SPEC 131).
 *
 * The request and the provider are projected TWICE, resolved and raw — the
 * raw target_id is what lets a label tell "no reference" from "a reference to
 * something that is gone", same as the awarded provider of SPEC 125.
 *
 * The provider join does not require it to be published: a suspended provider
 * still has a name, and its offers are still its offers.
 *
 * No join can multiply rows: every field joined has cardinality 1.
 *
 * @param int|null $condominium_id
 *   'condominio' nid, compared against the REQUEST's field_condominium.
 * @param int|null $provider_id
 *   'provider' nid, compared against the raw field_provider.
 * @param string|null $status
 *   One key of myapi_services_offer_statuses().
 * @param int|null $created_from
 *   Unix timestamp, n.created >=.
 * @param int|null $created_to
 *   Unix timestamp, n.created <=.
 *
 * @return SelectQuery
 *   Extended with PagerDefault and limited to 20 rows; the caller executes it.
 */
function myapi_service_offers_admin_list_rows($condominium_id = NULL, $provider_id = NULL, $status = NULL, $created_from = NULL, $created_to = NULL) {
  $query = db_select('node', 'n')
    ->fields('n', array('nid', 'created'))
    ->condition('n.type', MYAPI_SERVICES_OFFER_TYPE)
    ->condition('n.status', 1);
  $query->addTag('node_access');

  $query->leftJoin('field_data_field_request', 'freq', "freq.entity_id = n.nid AND freq.entity_type = 'node' AND freq.deleted = 0");
  $query->addField('freq', 'field_request_target_id', 'request_raw');
  $query->leftJoin('node', 'nr', 'nr.nid = freq.field_request_target_id AND nr.type = :request_type', array(':request_type' => MYAPI_SERVICES_REQUEST_TYPE));
  $query->addField('nr', 'nid', 'request_id');
  $query->addField('nr', 'title', 'request_title');

  // The condominium OF THE REQUEST — an offer has none of its own.
  $query->leftJoin('field_data_field_condominium', 'fco', "fco.entity_id = freq.field_request_target_id AND fco.entity_type = 'node' AND fco.deleted = 0");
  $query->leftJoin('node', 'nc', "nc.nid = fco.field_condominium_target_id AND nc.type = 'condominio' AND nc.status = 1");
  $query->addField('nc', 'title', 'condominium_title');

  $query->leftJoin('field_data_field_provider', 'fpr', "fpr.entity_id = n.nid AND fpr.entity_type = 'node' AND fpr.deleted = 0");
  $query->addField('fpr', 'field_provider_target_id', 'provider_raw');
  $query->leftJoin('node', 'np', 'np.nid = fpr.field_provider_target_id AND np.type = :provider_type', array(':provider_type' => MYAPI_SERVICES_PROVIDER_TYPE));
  $query->addField('np', 'title', 'provider_title');

  $query->leftJoin('field_data_field_offer_amount', 'fam', "fam.entity_id = n.nid AND fam.entity_type = 'node' AND fam.deleted = 0");
  $query->addField('fam', 'field_offer_amount_value', 'amount');
  $query->leftJoin('field_data_field_offer_amount_type', 'fat', "fat.entity_id = n.nid AND fat.entity_type = 'node' AND fat.deleted = 0");
  $query->addField('fat', 'field_offer_amount_type_value', 'amount_type');

  // 'offer_status' and not 'status': the alias would collide with n.status.
  $query->leftJoin('field_data_field_offer_status', 'fos', "fos.entity_id = n.nid AND fos.entity_type = 'node' AND fos.deleted = 0");
  $query->addField('fos', 'field_offer_status_value', 'offer_status');

  $query->leftJoin('field_data_field_offer_valid_until', 'fvu', "fvu.entity_id = n.nid AND fvu.entity_type = 'node' AND fvu.deleted = 0");
  $query->addField('fvu', 'field_offer_valid_until_value', 'valid_until');
  $query->leftJoin('field_data_field_last_message_at', 'flm', "flm.entity_id = n.nid AND flm.entity_type = 'node' AND flm.deleted = 0");
  $query->addField('flm', 'field_last_message_at_value', 'last_message_at');

  if ($condominium_id !== NULL) {
    $query->condition('fco.field_condominium_target_id', $condominium_id);
  }
  if ($provider_id !== NULL) {
    $query->condition('fpr.field_provider_target_id', $provider_id);
  }
  if ($status !== NULL) {
    $query->condition('fos.field_offer_status_value', $status);
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
 * The amount cell: '1.500,00 (Precio cerrado)'.
 *
 * Two decimals, comma decimal separator and dot for thousands, no currency —
 * the module has none (see myapi_service_transaction_accept_comment()).
 * 'on_site_quote' shows its label and NEVER an amount, even if a stray value
 * sits in the column (SPEC 100). An unknown type prints raw.
 *
 * @param string|null $amount
 * @param string|null $amount_type
 *
 * @return string
 */
function myapi_service_offers_admin_amount_label($amount, $amount_type) {
  $types = myapi_services_offer_amount_types();

  if ($amount_type === 'on_site_quote') {
    return check_plain($types['on_site_quote']);
  }
  if ($amount === NULL || $amount === '' || !is_numeric($amount)) {
    return '—';
  }

  $text = number_format((float) $amount, 2, ',', '.');
  if ($amount_type !== NULL && $amount_type !== '') {
    $text .= ' (' . (isset($types[$amount_type]) ? $types[$amount_type] : $amount_type) . ')';
  }

  return check_plain($text);
}

/**
 * The status cell. An unknown key prints raw.
 *
 * @param string|null $value
 *
 * @return string
 */
function myapi_service_offers_admin_status_label($value) {
  if ($value === NULL) {
    return '—';
  }

  $options = myapi_service_offers_admin_status_options();

  return check_plain(isset($options[$value]) ? $options[$value] : $value);
}

/**
 * A timestamp cell in the given format, or the given fallback.
 *
 * @param int|string|null $value
 * @param string $format
 * @param string $empty
 *   What a missing value reads as: '—', or 'Sin chat' for the last message.
 *
 * @return string
 */
function myapi_service_offers_admin_date_label($value, $format, $empty) {
  if ($value === NULL || $value === '') {
    return $empty;
  }

  return check_plain(format_date((int) $value, 'custom', $format));
}

/**
 * The Solicitud cell: linked to the supervision detail of SPEC 126.
 *
 * No request, the em dash; a request that is gone, 'Solicitud eliminada
 * (#id)'; an untitled one, its nid as the link text.
 *
 * @param object $row
 *
 * @return string
 */
function myapi_service_offers_admin_request_label($row) {
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
function myapi_service_offers_admin_provider_label($row) {
  if ($row->provider_raw === NULL) {
    return '—';
  }
  if ($row->provider_title === NULL) {
    return t('Proveedor eliminado (#@nid)', array('@nid' => $row->provider_raw));
  }

  return check_plain($row->provider_title);
}

/**
 * Builds the table body: the nine cells of SPEC 131, in header order.
 *
 * @param array $rows
 *   Rows fetched from myapi_service_offers_admin_list_rows().
 * @param array $editable
 *   nid => bool. Absent means NOT editable.
 *
 * @return array
 *   Ready for '#theme' => 'table'.
 */
function myapi_service_offers_admin_table_rows(array $rows, array $editable) {
  $table_rows = array();

  foreach ($rows as $row) {
    $nid = (int) $row->nid;

    $table_rows[] = array(
      l((string) $nid, 'node/' . $nid . (!empty($editable[$nid]) ? '/edit' : '')),
      myapi_service_offers_admin_request_label($row),
      ($row->condominium_title === NULL || $row->condominium_title === '') ? '—' : check_plain($row->condominium_title),
      myapi_service_offers_admin_provider_label($row),
      myapi_service_offers_admin_amount_label($row->amount, $row->amount_type),
      myapi_service_offers_admin_status_label($row->offer_status),
      myapi_service_offers_admin_date_label($row->created, 'd/m/Y H:i', '—'),
      myapi_service_offers_admin_date_label($row->valid_until, 'd/m/Y', '—'),
      myapi_service_offers_admin_date_label($row->last_message_at, 'd/m/Y H:i', t('Sin chat')),
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
function myapi_service_offers_admin_editable_map(array $rows) {
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
 * Builds the filter form of the offer listing. GET, no token, the calendar's
 * generic after-build, the hidden q for clean_url = 0.
 *
 * @param array $form
 * @param array $form_state
 * @param array $filters
 * @param array $condominium_options
 *   nid => title.
 * @param array $provider_options
 *   nid => title.
 */
function myapi_service_offers_admin_filter_form($form, &$form_state, $filters, $condominium_options, $provider_options) {
  $form['#method'] = 'get';
  $form['#attributes']['class'][] = 'myapi-service-offers-filters';
  $form['#token'] = FALSE;
  $form['#after_build'][] = 'myapi_reservation_calendar_filter_form_after_build';
  $form['#action'] = url('admin/content/service-offers');

  if (!variable_get('clean_url', 0)) {
    $form['q'] = array(
      '#type'  => 'hidden',
      '#value' => 'admin/content/service-offers',
    );
  }

  $form['condominium'] = array(
    '#type'          => 'select',
    '#title'         => t('Condominio'),
    '#options'       => array('' => t('- Todos -')) + $condominium_options,
    '#default_value' => $filters['condominium'] === NULL ? '' : $filters['condominium'],
  );

  $form['provider'] = array(
    '#type'          => 'select',
    '#title'         => t('Proveedor'),
    '#options'       => array('' => t('- Todos -')) + $provider_options,
    '#default_value' => $filters['provider'] === NULL ? '' : $filters['provider'],
  );

  $form['status'] = array(
    '#type'          => 'select',
    '#title'         => t('Estado'),
    '#options'       => array('' => t('- Todos -')) + myapi_service_offers_admin_status_options(),
    '#default_value' => $filters['status'] === NULL ? '' : $filters['status'],
  );

  $form['date_from'] = array(
    '#type'   => 'item',
    '#title'  => t('Enviada desde'),
    '#markup' => '<input type="date" name="date_from" value="' . check_plain((string) $filters['date_from']) . '" />',
  );
  $form['date_to'] = array(
    '#type'   => 'item',
    '#title'  => t('Enviada hasta'),
    '#markup' => '<input type="date" name="date_to" value="' . check_plain((string) $filters['date_to']) . '" />',
  );

  $form['submit'] = array(
    '#type'  => 'submit',
    '#value' => t('Filtrar'),
  );

  return $form;
}

/**
 * Page callback of admin/content/service-offers.
 */
function myapi_service_offers_admin_page() {
  module_load_include('inc', 'myapi', 'includes/myapi.reservation_calendar');
  // myapi_service_requests_created_range(): the same whole-day bounds.
  module_load_include('inc', 'myapi', 'includes/myapi.service_requests_admin');

  drupal_add_css(drupal_get_path('module', 'myapi') . '/css/myapi.claims.css');

  // Only 'administrator' and 'backend' reach this page, so the condominium
  // select is the whole site's.
  $condominium_options = myapi_calendar_condominium_options();
  $provider_options = myapi_service_offers_admin_provider_options();

  $filters = myapi_service_offers_admin_list_filters();
  $filters['condominium'] = myapi_service_offers_admin_effective_option($filters['condominium'], $condominium_options);
  $filters['provider'] = myapi_service_offers_admin_effective_option($filters['provider'], $provider_options);

  $range = myapi_service_requests_created_range($filters['date_from'], $filters['date_to']);

  $rows = myapi_service_offers_admin_list_rows(
    $filters['condominium'],
    $filters['provider'],
    $filters['status'],
    $range['from'],
    $range['to']
  )->execute()->fetchAll();

  $build = array();

  $build['filters'] = drupal_get_form('myapi_service_offers_admin_filter_form', $filters, $condominium_options, $provider_options);

  $build['table'] = array(
    '#theme'  => 'table',
    '#header' => array(
      t('ID'),
      t('Solicitud'),
      t('Condominio'),
      t('Proveedor'),
      t('Monto'),
      t('Estado'),
      t('Enviada'),
      t('Válida hasta'),
      t('Último mensaje'),
    ),
    '#rows'   => myapi_service_offers_admin_table_rows($rows, myapi_service_offers_admin_editable_map($rows)),
    '#empty'  => t('No hay resultados para los filtros seleccionados.'),
  );

  $build['pager'] = array(
    '#markup' => theme('pager'),
  );

  return $build;
}
