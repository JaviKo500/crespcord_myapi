<?php

/**
 * @file
 * Back-office provider listing page (SPEC 131).
 *
 * Serves admin/content/providers: a read-only, server-rendered, paginated
 * directory of 'provider' nodes with GET filters, for 'administrator' and
 * 'backend' only (myapi_marketplace_admin_access()). It is NOT an API
 * endpoint, and nothing under resources/ calls into it.
 *
 * Same mould as includes/myapi.service_requests_admin.inc (SPEC 125): GET
 * filters, one paginated query tagged 'node_access' living next to its page,
 * LEFT joins only, and a link per row resolved by node_access('update').
 *
 * ------------------------------------------------------------------------
 * WHAT IS DIFFERENT FROM THE OTHER LISTINGS
 * ------------------------------------------------------------------------
 *
 *  - n.status IS NOT FILTERED (decision 4 of SPEC 131). The operator suspends
 *    a provider by unpublishing it (docs/provider.md), and this is the screen
 *    where they find it again to reactivate it. It shows as 'Suspendido'.
 *  - THE MULTIVALUE FIELDS ARE NEVER JOINED to the paged query (decision 5).
 *    field_categories and field_provider_users have unlimited cardinality: a
 *    join would multiply rows and the pager would count them. They are read
 *    in two queries bounded to the nids of the page and grouped in PHP.
 *    GROUP_CONCAT was discarded: MySQL-only and capped by
 *    group_concat_max_len.
 *  - THE CATEGORY FILTER IS A CORRELATED EXISTS, for the same reason, and the
 *    same shape myapi_provider_category_exists() gives the marketplace
 *    endpoint (SPEC 83). That function lives in resources/ and Rule 5 of
 *    CLAUDE.md keeps it out of reach, so the sub-select is written here.
 *
 * ------------------------------------------------------------------------
 * THE SITUATION
 * ------------------------------------------------------------------------
 *
 * Derived from SPEC 83's rule of the "active provider" — published AND
 * field_license_expiry >= REQUEST_TIME — and written TWICE on purpose, once
 * per consumer: myapi_providers_admin_situation() labels a row in PHP, and
 * myapi_providers_admin_situation_conditions() turns a ?situation= into SQL.
 * Both take the current time as a parameter, and ProvidersAdminPageTest runs
 * the two over the same borders so the filter and the cell cannot disagree.
 */

module_load_include('inc', 'myapi', 'includes/myapi.services_common');

/**
 * How close an expiry has to be to read 'Vence pronto': 30 days, inclusive.
 */
if (!defined('MYAPI_PROVIDERS_ADMIN_EXPIRING_WINDOW')) {
  define('MYAPI_PROVIDERS_ADMIN_EXPIRING_WINDOW', 30 * 86400);
}

/* ---------------------------------------------------------------------------
 * The situation.
 * ------------------------------------------------------------------------ */

/**
 * The five situations, in the order the select offers them.
 *
 * Declared here because they are not a catalogue of any field: they are a
 * reading of two of them (n.status and field_license_expiry) that only this
 * page makes.
 *
 * @return array
 *   Key => human label.
 */
function myapi_providers_admin_situation_options() {
  return array(
    'active'     => t('Activo'),
    'expiring'   => t('Vence pronto'),
    'expired'    => t('Habilitación vencida'),
    'suspended'  => t('Suspendido'),
    'no_license' => t('Sin habilitación'),
  );
}

/**
 * The situation of one provider, by priority.
 *
 *   1. suspended  — unpublished, whatever its licence says.
 *   2. no_license — no expiry at all.
 *   3. expired    — expiry < now. Expiring EXACTLY now is still valid: SPEC
 *                   83 compares with >=.
 *   4. expiring   — expiry within the next 30 days, both ends inclusive.
 *   5. active     — the rest.
 *
 * Pure: the current time is a parameter, so a test can pin the borders.
 *
 * @param int|string $published
 *   n.status.
 * @param int|string|null $expiry
 *   field_license_expiry_value, a Unix timestamp, or NULL.
 * @param int $now
 *   REQUEST_TIME, in production.
 *
 * @return string
 *   One key of myapi_providers_admin_situation_options().
 */
function myapi_providers_admin_situation($published, $expiry, $now) {
  if ((int) $published !== 1) {
    return 'suspended';
  }
  if ($expiry === NULL || $expiry === '') {
    return 'no_license';
  }

  $expiry = (int) $expiry;
  if ($expiry < $now) {
    return 'expired';
  }
  if ($expiry <= $now + MYAPI_PROVIDERS_ADMIN_EXPIRING_WINDOW) {
    return 'expiring';
  }

  return 'active';
}

/**
 * The SQL equivalent of one situation, as a list of conditions to AND.
 *
 * Each entry is [field, value, operator]; an 'IS NULL' carries no value.
 * Mirrors myapi_providers_admin_situation() border by border.
 *
 * @param string $situation
 *   One key of myapi_providers_admin_situation_options().
 * @param int $now
 *   REQUEST_TIME, in production.
 *
 * @return array
 *   The conditions; empty for an unknown key.
 */
function myapi_providers_admin_situation_conditions($situation, $now) {
  $published = array('n.status', 1, '=');
  $expiry = 'fle.field_license_expiry_value';
  $window_end = $now + MYAPI_PROVIDERS_ADMIN_EXPIRING_WINDOW;

  switch ($situation) {
    case 'suspended':
      return array(array('n.status', 0, '='));

    case 'no_license':
      return array($published, array($expiry, NULL, 'IS NULL'));

    case 'expired':
      return array($published, array($expiry, $now, '<'));

    case 'expiring':
      return array($published, array($expiry, $now, '>='), array($expiry, $window_end, '<='));

    case 'active':
      return array($published, array($expiry, $window_end, '>'));
  }

  return array();
}

/* ---------------------------------------------------------------------------
 * Filters.
 * ------------------------------------------------------------------------ */

/**
 * Reads and validates the category/situation GET parameters.
 *
 * The category is validated as a positive integer only; whether it is a real
 * term is decided against the select's options by
 * myapi_service_requests_effective_category(). Garbage means no filter.
 *
 * @return array
 *   Keys: category (int), situation. NULL means no filter.
 */
function myapi_providers_admin_list_filters() {
  module_load_include('inc', 'myapi', 'includes/myapi.reservation_calendar');

  $category = NULL;
  if (isset($_GET['category'])) {
    $category = myapi_calendar_positive_int($_GET['category']);
  }

  $situation = NULL;
  if (isset($_GET['situation']) && is_scalar($_GET['situation'])) {
    $options = myapi_providers_admin_situation_options();
    if (isset($options[(string) $_GET['situation']])) {
      $situation = (string) $_GET['situation'];
    }
  }

  return array(
    'category'  => $category,
    'situation' => $situation,
  );
}

/* ---------------------------------------------------------------------------
 * The query.
 * ------------------------------------------------------------------------ */

/**
 * The listing query: one row per provider, published or not (SPEC 131).
 *
 * ->addTag('node_access') is kept for the same reason as on every back-office
 * listing: the page delegates row visibility to Drupal. For 'administrator'
 * and 'backend' it narrows nothing.
 *
 * Only cardinality-1 fields are joined, all LEFT. The published flag is
 * projected as 'published', so the row is not confused with a status field.
 *
 * @param int|null $category_id
 *   Term id of 'service_category'.
 * @param string|null $situation
 *   One key of myapi_providers_admin_situation_options().
 * @param int $now
 *   REQUEST_TIME, in production.
 *
 * @return SelectQuery
 *   Extended with PagerDefault and limited to 20 rows; the caller executes it.
 */
function myapi_providers_admin_list_rows($category_id, $situation, $now) {
  $query = db_select('node', 'n')
    ->fields('n', array('nid', 'title'))
    ->condition('n.type', MYAPI_SERVICES_PROVIDER_TYPE);
  $query->addTag('node_access');

  $query->addField('n', 'status', 'published');
  $query->addField('n', 'created', 'created');

  $query->leftJoin('field_data_field_phone', 'fph', "fph.entity_id = n.nid AND fph.entity_type = 'node' AND fph.deleted = 0");
  $query->addField('fph', 'field_phone_value', 'phone');

  $query->leftJoin('field_data_field_license_expiry', 'fle', "fle.entity_id = n.nid AND fle.entity_type = 'node' AND fle.deleted = 0");
  $query->addField('fle', 'field_license_expiry_value', 'license_expiry');

  $query->leftJoin('field_data_field_rating_avg', 'fra', "fra.entity_id = n.nid AND fra.entity_type = 'node' AND fra.deleted = 0");
  $query->addField('fra', 'field_rating_avg_value', 'rating_avg');

  $query->leftJoin('field_data_field_rating_count', 'frc', "frc.entity_id = n.nid AND frc.entity_type = 'node' AND frc.deleted = 0");
  $query->addField('frc', 'field_rating_count_value', 'rating_count');

  if ($category_id !== NULL) {
    $sub = db_select('field_data_field_categories', 'fc');
    $sub->addExpression('1');
    $sub->where('fc.entity_id = n.nid')
      ->condition('fc.entity_type', 'node')
      ->condition('fc.deleted', 0)
      ->condition('fc.field_categories_tid', $category_id);
    $query->exists($sub);
  }

  if ($situation !== NULL) {
    foreach (myapi_providers_admin_situation_conditions($situation, $now) as $condition) {
      list($field, $value, $operator) = $condition;
      if ($operator === 'IS NULL') {
        $query->isNull($field);
      }
      else {
        $query->condition($field, $value, $operator);
      }
    }
  }

  // A directory: by name, nid as the deterministic tie-breaker.
  $query->orderBy('n.title', 'ASC');
  $query->orderBy('n.nid', 'ASC');

  return $query->extend('PagerDefault')->limit(20);
}

/**
 * The category names of the providers of one page, grouped per provider.
 *
 * A second query bounded to the page's nids — see the @file block for why the
 * multivalue fields never reach the paged query. Delta order, the order the
 * operator set in the form. An orphan tid (term deleted) is skipped.
 *
 * @param array $nids
 *   Provider nids of the current page.
 *
 * @return array
 *   nid => list of term names. A provider with none is absent.
 */
function myapi_providers_admin_category_names(array $nids) {
  if (!$nids) {
    return array();
  }

  $query = db_select('field_data_field_categories', 'fc')
    ->fields('fc', array('entity_id'))
    ->condition('fc.entity_type', 'node')
    ->condition('fc.deleted', 0)
    ->condition('fc.entity_id', $nids, 'IN');
  $query->leftJoin('taxonomy_term_data', 'td', 'td.tid = fc.field_categories_tid');
  $query->addField('td', 'name', 'term_name');
  $query->orderBy('fc.entity_id');
  $query->orderBy('fc.delta');

  $names = array();
  foreach ($query->execute() as $row) {
    if ($row->term_name === NULL) {
      continue;
    }
    $names[(int) $row->entity_id][] = $row->term_name;
  }

  return $names;
}

/**
 * The user names of the providers of one page, grouped per provider.
 *
 * The references in one bounded query, and the names through
 * myapi_user_display_names() — "nombre apellidos" or the account name, the
 * rule every other screen of the module prints a person with. A reference to
 * an account that no longer exists says so rather than vanishing.
 *
 * @param array $nids
 *   Provider nids of the current page.
 *
 * @return array
 *   nid => list of names. A provider with none is absent.
 */
function myapi_providers_admin_user_names(array $nids) {
  if (!$nids) {
    return array();
  }

  module_load_include('inc', 'myapi', 'includes/myapi.user');

  $query = db_select('field_data_field_provider_users', 'fpu')
    ->fields('fpu', array('entity_id', 'field_provider_users_target_id'))
    ->condition('fpu.entity_type', 'node')
    ->condition('fpu.deleted', 0)
    ->condition('fpu.entity_id', $nids, 'IN');
  $query->orderBy('fpu.entity_id');
  $query->orderBy('fpu.delta');
  $references = $query->execute()->fetchAll();

  if (!$references) {
    return array();
  }

  $uids = array();
  foreach ($references as $reference) {
    $uids[] = (int) $reference->field_provider_users_target_id;
  }
  $display = myapi_user_display_names(array_values(array_unique($uids)));

  $names = array();
  foreach ($references as $reference) {
    $uid = (int) $reference->field_provider_users_target_id;
    $names[(int) $reference->entity_id][] = isset($display[$uid])
      ? $display[$uid]
      : t('Usuario eliminado (#@uid)', array('@uid' => $uid));
  }

  return $names;
}

/* ---------------------------------------------------------------------------
 * Row labels. All of them answer ALREADY ESCAPED text.
 * ------------------------------------------------------------------------ */

/**
 * A free-text cell, or the em dash.
 *
 * @param string|null $value
 *
 * @return string
 */
function myapi_providers_admin_text_label($value) {
  if ($value === NULL || $value === '') {
    return '—';
  }

  return check_plain($value);
}

/**
 * A list of names, comma separated, or the em dash.
 *
 * @param array $values
 *
 * @return string
 */
function myapi_providers_admin_list_label(array $values) {
  if (!$values) {
    return '—';
  }

  return check_plain(implode(', ', $values));
}

/**
 * A timestamp as 'd/m/Y', or the em dash.
 *
 * @param int|string|null $value
 *
 * @return string
 */
function myapi_providers_admin_date_label($value) {
  if ($value === NULL || $value === '') {
    return '—';
  }

  return check_plain(format_date((int) $value, 'custom', 'd/m/Y'));
}

/**
 * The situation cell: the label inside a span with a class per situation,
 * styled by css/myapi.claims.css.
 *
 * @param string $situation
 *   One key of myapi_providers_admin_situation_options().
 *
 * @return string
 */
function myapi_providers_admin_situation_label($situation) {
  $options = myapi_providers_admin_situation_options();

  return '<span class="myapi-provider-situation myapi-provider-situation-' . check_plain($situation) . '">'
    . check_plain($options[$situation])
    . '</span>';
}

/**
 * The rating cell: '4,6 ★ (12)', one decimal with a comma.
 *
 * 'Sin calificaciones' when there is no count, a zero count or no average —
 * the counters are denormalised (SPEC 77) and a half-written pair is not a
 * rating.
 *
 * @param string|null $avg
 * @param string|null $count
 *
 * @return string
 */
function myapi_providers_admin_rating_label($avg, $count) {
  if ($avg === NULL || $count === NULL || (int) $count < 1) {
    return t('Sin calificaciones');
  }

  return check_plain(number_format((float) $avg, 1, ',', '') . ' ★ (' . (int) $count . ')');
}

/**
 * The link of the ID cell: node/%nid/edit, or node/%nid.
 *
 * @param object $row
 * @param bool $can_edit
 *
 * @return string
 */
function myapi_providers_admin_id_link($row, $can_edit) {
  $nid = (int) $row->nid;

  return l((string) $nid, 'node/' . $nid . ($can_edit ? '/edit' : ''));
}

/**
 * Builds the table body: the nine cells of SPEC 131, in header order.
 *
 * @param array $rows
 *   Rows fetched from myapi_providers_admin_list_rows().
 * @param array $editable
 *   nid => bool. Absent means NOT editable.
 * @param array $categories
 *   nid => term names, from myapi_providers_admin_category_names().
 * @param array $users
 *   nid => names, from myapi_providers_admin_user_names().
 * @param int $now
 *   REQUEST_TIME, in production.
 *
 * @return array
 *   Ready for '#theme' => 'table'.
 */
function myapi_providers_admin_table_rows(array $rows, array $editable, array $categories, array $users, $now) {
  $table_rows = array();

  foreach ($rows as $row) {
    $nid = (int) $row->nid;
    $title = ($row->title === NULL || $row->title === '') ? (string) $nid : $row->title;

    $table_rows[] = array(
      myapi_providers_admin_id_link($row, !empty($editable[$nid])),
      check_plain($title),
      myapi_providers_admin_list_label(isset($categories[$nid]) ? $categories[$nid] : array()),
      myapi_providers_admin_text_label($row->phone),
      myapi_providers_admin_list_label(isset($users[$nid]) ? $users[$nid] : array()),
      myapi_providers_admin_date_label($row->license_expiry),
      myapi_providers_admin_situation_label(myapi_providers_admin_situation($row->published, $row->license_expiry, $now)),
      myapi_providers_admin_rating_label($row->rating_avg, $row->rating_count),
      myapi_providers_admin_date_label($row->created),
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
function myapi_providers_admin_editable_map(array $rows) {
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
 * Builds the filter form of the provider listing. GET, no token, the
 * calendar's generic after-build, the hidden q for clean_url = 0.
 *
 * @param array $form
 * @param array $form_state
 * @param array $filters
 *   Validated filters, used as defaults.
 * @param array $category_options
 *   tid => name.
 */
function myapi_providers_admin_filter_form($form, &$form_state, $filters, $category_options) {
  $form['#method'] = 'get';
  $form['#attributes']['class'][] = 'myapi-providers-filters';
  $form['#token'] = FALSE;
  $form['#after_build'][] = 'myapi_reservation_calendar_filter_form_after_build';
  $form['#action'] = url('admin/content/providers');

  if (!variable_get('clean_url', 0)) {
    $form['q'] = array(
      '#type'  => 'hidden',
      '#value' => 'admin/content/providers',
    );
  }

  $form['category'] = array(
    '#type'          => 'select',
    '#title'         => t('Categoría'),
    '#options'       => array('' => t('- Todas -')) + $category_options,
    '#default_value' => $filters['category'] === NULL ? '' : $filters['category'],
  );

  $form['situation'] = array(
    '#type'          => 'select',
    '#title'         => t('Situación'),
    '#options'       => array('' => t('- Todas -')) + myapi_providers_admin_situation_options(),
    '#default_value' => $filters['situation'] === NULL ? '' : $filters['situation'],
  );

  $form['submit'] = array(
    '#type'  => 'submit',
    '#value' => t('Filtrar'),
  );

  return $form;
}

/**
 * Page callback of admin/content/providers.
 *
 * Read-only: one paginated query, two bounded queries for the multivalue
 * fields, one table.
 */
function myapi_providers_admin_page() {
  module_load_include('inc', 'myapi', 'includes/myapi.reservation_calendar');
  // The category select and its validation are the service-request
  // listing's: same vocabulary, same tid => name map.
  module_load_include('inc', 'myapi', 'includes/myapi.service_requests_admin');

  drupal_add_css(drupal_get_path('module', 'myapi') . '/css/myapi.claims.css');

  $category_options = myapi_service_requests_category_options();

  $filters = myapi_providers_admin_list_filters();
  $filters['category'] = myapi_service_requests_effective_category($filters['category'], $category_options);

  $rows = myapi_providers_admin_list_rows($filters['category'], $filters['situation'], REQUEST_TIME)
    ->execute()
    ->fetchAll();

  $nids = array();
  foreach ($rows as $row) {
    $nids[] = (int) $row->nid;
  }

  $build = array();

  $build['filters'] = drupal_get_form('myapi_providers_admin_filter_form', $filters, $category_options);

  $build['table'] = array(
    '#theme'  => 'table',
    '#header' => array(
      t('ID'),
      t('Nombre comercial'),
      t('Categorías'),
      t('Teléfono'),
      t('Usuarios asociados'),
      t('Habilitación vence'),
      t('Situación'),
      t('Calificación'),
      t('Alta'),
    ),
    '#rows'   => myapi_providers_admin_table_rows(
      $rows,
      myapi_providers_admin_editable_map($rows),
      myapi_providers_admin_category_names($nids),
      myapi_providers_admin_user_names($nids),
      REQUEST_TIME
    ),
    '#empty'  => t('No hay resultados para los filtros seleccionados.'),
  );

  $build['pager'] = array(
    '#markup' => theme('pager'),
  );

  return $build;
}
