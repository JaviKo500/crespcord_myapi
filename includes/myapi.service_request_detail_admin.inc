<?php

/**
 * @file
 * Read-only supervision detail of one service request (SPEC 126).
 *
 * Serves admin/content/service-requests/%node: the whole request on one
 * server-rendered screen — data, transaction timeline, offers received and
 * attachments — for the three roles that already reach the listing of
 * SPEC 125. It is NOT an API endpoint: nothing here returns the JSON envelope,
 * and no resource under resources/ calls into it.
 *
 * The '_admin' suffix means here exactly what it means in
 * includes/myapi.service_transaction_admin.inc and
 * includes/myapi.claim_transaction_admin.inc: this file only ever runs behind
 * a back-office screen. Read that way, it is the back-office twin of
 * includes/myapi.service_request_detail.inc, which is literally what it is —
 * the same request, a different reader, and therefore a different shape.
 *
 * ------------------------------------------------------------------------
 * IT OPENS NO QUERY. NOT ONE.
 * ------------------------------------------------------------------------
 *
 * Every row this screen paints comes from a function that already existed:
 *
 *   Data       myapi_service_request_detail_row()      (SPEC 89)
 *   Timeline   myapi_service_transaction_timeline_rows()  (SPEC 94)
 *   Offers     myapi_service_request_load_offers()     (SPEC 89)
 *   Files      the fields of the node the router already loaded — no query
 *
 * A db_select() in this file would be a fourth answer to "what is a service
 * request", and the two tests at the bottom of
 * tests/unit/ServiceRequestAdminDetailTest.php exist to keep it from
 * appearing.
 *
 * ------------------------------------------------------------------------
 * NOT ONE CONTROL THAT LEADS TO A WRITE, EITHER.
 * ------------------------------------------------------------------------
 *
 * No form, no button, no link to the node's own editing form, no link to the
 * transaction creation or deletion routes. That is the whole point of the
 * screen: supervision, and a promise a reader can check by reading. The path
 * to editing already exists and is one click away — the TITLE column of the
 * listing, which SPEC 125 made conditional for exactly that purpose.
 *
 * It is also why the timeline below builds its own four cells instead of
 * calling the SPEC 94 table builder, which appends two more: an 'Editar' link
 * and a 'Borrar' link. Giving that function a mode flag would turn one
 * screen's builder into two screens' builder, and the price of getting the
 * flag wrong is a write control on the screen that promises none. Four
 * ternaries are the cheaper half of that trade.
 *
 * ------------------------------------------------------------------------
 * WHY THE FILE BLOCK DOES NOT REUSE myapi_service_request_load_images()
 * ------------------------------------------------------------------------
 *
 * Because that function answers items of myapi_service_request_build_file(),
 * whose `url` is the api/v1 file endpoint — and that endpoint demands a Bearer
 * token, which a back-office session does not carry. Every link would answer
 * 401, and the failure would look like a broken link rather than an error.
 *
 * What this screen needs is file_create_url() over the stored uri, which goes
 * through Drupal's private file route and is authorised by the
 * hook_file_download() half of SPEC 89 —
 * myapi_service_request_file_download_headers() over
 * myapi_service_request_file_access() — which already recognises the same
 * three roles and already scopes an 'administrador edificio' by condominium.
 * So the access rule of the attachments is not written here either: it has
 * existed since SPEC 89.
 *
 * The uri is also something the images loader does not return, so reusing it
 * would cost a file_load_multiple() to recover what the loaded node already
 * holds in memory.
 *
 * ------------------------------------------------------------------------
 * THE BROKEN REQUEST IS A DIAGNOSIS, NOT A 404
 * ------------------------------------------------------------------------
 *
 * myapi_service_request_detail_row() starts from
 * myapi_service_request_base_query(), which INNER JOINs field_requester,
 * field_category and taxonomy_term_data — deliberately, so a broken request
 * never reaches the app. The back-office listing does the opposite and LEFTs
 * everything, so that the same broken request IS listed, with em dashes.
 *
 * Both rules are right and they are opposite, so there exists a request the
 * operator sees in the listing whose detail query answers no row. Left alone,
 * the listing would link to a 404 on precisely the rows that exist to be
 * repaired. What this screen does instead is say so out loud: a warning that
 * names what is missing and what it costs (the app cannot see the request),
 * the summary degraded to what the node knows about itself, and the other
 * three blocks rendered in full — none of them depends on the category or on
 * the requester.
 */

module_load_include('inc', 'myapi', 'includes/myapi.services_common');

/* ---------------------------------------------------------------------------
 * Block 1 — the summary.
 *
 * Label/value pairs, already escaped, ready for '#theme' => 'table'. Every
 * optional value degrades to an em dash on its own and NO ROW EVER
 * DISAPPEARS: this is the screen where a half-filled request gets found, and
 * a missing row would hide the very thing the operator came to see.
 *
 * The three label helpers are the listing's — myapi_service_requests_status_
 * label(), _text_label() and _date_label() — reused rather than rewritten, so
 * the same value reads the same way in the table and in the detail.
 * ------------------------------------------------------------------------ */

/**
 * Human label for the offer awarded to a request.
 *
 * READS THE RAW target_id AND THE RESOLVED NODE, the same three-way rule
 * myapi_service_requests_provider_label() applies to the provider and for the
 * same reason: "no offer was ever awarded" and "the awarded offer was
 * unpublished or deleted" are different facts, and one em dash for both would
 * hide the second behind the first.
 *
 * A request in status 'direct' has a provider and no offer at all (SPEC 87),
 * so the em dash is the NORMAL answer here and not a symptom.
 *
 * The status rides along in parentheses because it is what tells an operator
 * whether the award is still standing; a status the catalogue does not know
 * prints raw, same criterion as the request status.
 *
 * @param object $row
 *   Row from myapi_service_request_detail_row().
 *
 * @return string
 *   Already escaped.
 */
function myapi_service_request_admin_offer_label($row) {
  if (!isset($row->assigned_offer_raw) || $row->assigned_offer_raw === NULL) {
    return '—';
  }

  if (!isset($row->assigned_offer_id) || $row->assigned_offer_id === NULL) {
    return t('Oferta eliminada (#@nid)', array('@nid' => $row->assigned_offer_raw));
  }

  $status = isset($row->assigned_offer_status) ? $row->assigned_offer_status : NULL;

  if ($status === NULL || $status === '') {
    return '#' . (int) $row->assigned_offer_id;
  }

  $statuses = myapi_services_offer_statuses();
  $label = isset($statuses[$status]) ? $statuses[$status] : $status;

  return check_plain('#' . (int) $row->assigned_offer_id . ' (' . $label . ')');
}

/**
 * The thirteen label/value pairs of the summary block.
 *
 * PURE: no database and no node. Everything arrives resolved — the row from
 * the reused detail query, the requester's name from the ONE user_load() the
 * page pays — which is what lets a test exercise every shape of the block
 * with no site booted.
 *
 * The requester name is NOT read from the row: the detail query projects the
 * uid and never the name, because the JSON response does not need it. Adding
 * the join there to save a user_load() here would change a query shared by two
 * endpoints in order to spare one record a lookup.
 *
 * The category prints its code in parentheses when it has one, and the name
 * alone when it does not — never an empty pair of brackets.
 *
 * @param object $row
 *   Row from myapi_service_request_detail_row().
 * @param string|null $requester_name
 *   users.name of the requester, or NULL when the account no longer exists.
 *
 * @return array
 *   One [label, value] pair per row, in reading order. Values already escaped.
 */
function myapi_service_request_admin_summary_rows($row, $requester_name) {
  // The listing's own three-branch requester label, reached with the two
  // columns it reads. Rebuilding the three branches here would be a second
  // wording of "the account was deleted" that nobody keeps in step.
  $requester_row = (object) array(
    'requester_uid'  => isset($row->requester_uid) ? $row->requester_uid : NULL,
    'requester_name' => $requester_name,
  );

  $category = myapi_service_requests_text_label(isset($row->category_name) ? $row->category_name : NULL);
  if ($category !== '—' && !empty($row->category_code)) {
    $category .= ' (' . check_plain($row->category_code) . ')';
  }

  $description = (!isset($row->description) || $row->description === NULL || $row->description === '')
    ? '—'
    : nl2br(check_plain($row->description));

  return array(
    array(t('ID'), (string) (int) $row->nid),
    array(t('Título'), myapi_service_requests_text_label(isset($row->title) ? $row->title : NULL)),
    array(t('Estado'), myapi_service_requests_status_label(isset($row->status) ? $row->status : NULL)),
    array(t('Condominio'), myapi_service_requests_text_label(isset($row->condominium_name) ? $row->condominium_name : NULL)),
    array(t('Vivienda'), myapi_service_requests_text_label(isset($row->unit_name) ? $row->unit_name : NULL)),
    array(t('Solicitante'), myapi_service_requests_requester_label($requester_row)),
    array(t('Categoría'), $category),
    array(t('Fecha de creación'), myapi_service_requests_date_label(isset($row->created) ? $row->created : NULL)),
    array(t('Fecha deseada'), myapi_service_requests_date_label(isset($row->desired_start) ? $row->desired_start : NULL)),
    array(t('Fecha de cierre'), myapi_service_requests_date_label(isset($row->closed_at) ? $row->closed_at : NULL)),
    array(t('Proveedor adjudicado'), myapi_service_requests_provider_label($row)),
    array(t('Oferta adjudicada'), myapi_service_request_admin_offer_label($row)),
    array(t('Descripción'), $description),
  );
}

/**
 * The summary of a request the detail query cannot read.
 *
 * Three rows and no more, because three is what the node object knows about
 * itself without any join: which request this is, what it is called and when
 * it was created. Everything else lives in field tables the broken query was
 * the one reading.
 *
 * It is deliberately NOT a best-effort reconstruction out of $node->field_*.
 * That would be a second, weaker implementation of the detail query living
 * next to the real one, and it would drift the day a field is added. The
 * screen's job in this case is to say what is wrong; the repair happens in the
 * node's own form.
 *
 * PURE.
 *
 * @param object $node
 *   The 'service_request' node the router loaded.
 *
 * @return array
 *   [label, value] pairs, already escaped.
 */
function myapi_service_request_admin_degraded_summary_rows($node) {
  return array(
    array(t('ID'), (string) (int) $node->nid),
    array(t('Título'), myapi_service_requests_text_label(isset($node->title) ? $node->title : NULL)),
    array(t('Fecha de creación'), myapi_service_requests_date_label(isset($node->created) ? $node->created : NULL)),
  );
}

/**
 * The warning shown when the detail query answers no row.
 *
 * It says three things, in this order, because that is the order the operator
 * needs them: WHAT is missing (the category or the requester), WHAT IT COSTS
 * (the app does not see the request, and neither does its owner), and WHERE it
 * is repaired.
 *
 * It renders with Drupal's own 'messages warning' classes instead of going
 * through drupal_set_message(), so it stays attached to this page rather than
 * surfacing on whatever the operator opens next.
 *
 * PURE: it builds a string.
 *
 * @param int $nid
 *   The request the screen is showing.
 *
 * @return string
 *   Markup.
 */
function myapi_service_request_admin_broken_notice($nid) {
  $text = t('La solicitud #@nid existe y está publicada, pero le falta la categoría o el solicitante, así que la consulta del detalle no devuelve datos y la aplicación tampoco la ve. Corrija la categoría o el solicitante desde el formulario del nodo para que vuelva a estar completa.', array('@nid' => (int) $nid));

  return '<div class="messages warning">' . $text . '</div>';
}

/* ---------------------------------------------------------------------------
 * Block 2 — the transaction timeline.
 * ------------------------------------------------------------------------ */

/**
 * The four read-only cells of each timeline row.
 *
 * FOUR AND NOT SIX. The SPEC 94 builder makes the same four and appends an
 * 'Editar' and a 'Borrar' link; this screen carries no write control, so it
 * builds its own. See the @file block for why that is a copy of four ternaries
 * and not a mode flag on the other function.
 *
 * The status date is a SUBSTRING of the stored value and never
 * format_date(strtotime(...)): field_status_date was created with
 * tz_handling = 'none' (SPEC 55), so what is stored is a naive local time and
 * converting it would shift the hour somebody typed by hand. The cut to
 * sixteen characters drops the seconds, which this module always pins to :00.
 *
 * The author cell IS reused — myapi_service_transaction_author_label(),
 * SPEC 94 — because it is the same two branches with the same wording.
 *
 * PURE.
 *
 * @param array $rows
 *   Rows from myapi_service_transaction_timeline_rows().
 *
 * @return array
 *   One row of four cells per transaction, most recent first (the query's own
 *   order). Already escaped.
 */
function myapi_service_request_admin_timeline_table_rows(array $rows) {
  $statuses = myapi_services_request_statuses();
  $table_rows = array();

  foreach ($rows as $row) {
    $status = $row->status;
    $label = ($status !== NULL && isset($statuses[$status])) ? $statuses[$status] : $status;

    $table_rows[] = array(
      ($label === NULL || $label === '') ? '—' : check_plain($label),
      ($row->status_date === NULL || $row->status_date === '') ? '—' : check_plain(substr($row->status_date, 0, 16)),
      ($row->comment === NULL || $row->comment === '') ? '—' : check_plain($row->comment),
      myapi_service_transaction_author_label($row),
    );
  }

  return $table_rows;
}

/* ---------------------------------------------------------------------------
 * Block 3 — the offers received.
 * ------------------------------------------------------------------------ */

/**
 * The eight cells of each offer, plus the mark on the awarded one.
 *
 * EVERY PUBLISHED OFFER OF THE REQUEST, whatever its status, because the page
 * calls myapi_service_request_load_offers() with an EMPTY provider list — the
 * parameter exists to trim a bidding provider to their own offers, and a
 * supervisor is not a competitor. Seeing the round whole is the job.
 *
 * The awarded one is marked with a class on the row, matched by nid against
 * the summary's assigned_offer_id. A mark, not a control: nothing on this
 * screen awards or rejects anything.
 *
 * TWO DEGRADED SHAPES ARE EXPECTED HERE and neither drops a row:
 *
 *   - An offer stored before SPEC 100 has a row in none of the ten quote
 *     tables, so amount, amount type and validity answer em dashes. Dropping
 *     it would make this list disagree with offers_count.
 *   - An offer whose provider node was unpublished arrives with provider_name
 *     NULL and stays in the list, for the same reason. The cell says
 *     'Proveedor eliminado'. The query projects no raw target_id for the
 *     provider, so an offer with no provider reference at all reads the same
 *     way — acceptable, because both are the same red flag for the operator
 *     and both need the same repair.
 *
 * PURE.
 *
 * @param array $offers
 *   Rows from myapi_service_request_load_offers().
 * @param int|null $assigned_offer_id
 *   The `assigned_offer_id` of the summary row, or NULL.
 *
 * @return array
 *   Rows in the ['data' => [...], 'class' => [...]] shape '#theme' => 'table'
 *   takes. Cells already escaped.
 */
function myapi_service_request_admin_offer_table_rows(array $offers, $assigned_offer_id) {
  $statuses = myapi_services_offer_statuses();
  $amount_types = myapi_services_offer_amount_types();
  $table_rows = array();

  foreach ($offers as $offer) {
    $status = isset($offer->status) ? $offer->status : NULL;
    $status_label = ($status !== NULL && isset($statuses[$status])) ? $statuses[$status] : $status;

    $amount_type = isset($offer->amount_type) ? $offer->amount_type : NULL;
    $amount_type_label = ($amount_type !== NULL && isset($amount_types[$amount_type])) ? $amount_types[$amount_type] : $amount_type;

    $classes = array();
    if ($assigned_offer_id !== NULL && (int) $assigned_offer_id === (int) $offer->nid) {
      $classes[] = 'myapi-offer-selected';
    }

    $table_rows[] = array(
      'data' => array(
        (string) (int) $offer->nid,
        (!isset($offer->provider_name) || $offer->provider_name === NULL || $offer->provider_name === '')
          ? t('Proveedor eliminado')
          : check_plain($offer->provider_name),
        ($status_label === NULL || $status_label === '') ? '—' : check_plain($status_label),
        myapi_service_requests_text_label(isset($offer->amount) ? $offer->amount : NULL),
        ($amount_type_label === NULL || $amount_type_label === '') ? '—' : check_plain($amount_type_label),
        myapi_service_requests_date_label(isset($offer->valid_until) ? $offer->valid_until : NULL),
        myapi_service_requests_text_label(isset($offer->message) ? $offer->message : NULL),
        myapi_service_requests_date_label(isset($offer->created) ? $offer->created : NULL),
      ),
      'class' => $classes,
    );
  }

  return $table_rows;
}

/* ---------------------------------------------------------------------------
 * Block 4 — the attachments.
 * ------------------------------------------------------------------------ */

/**
 * The files of a request, read off the node the router already loaded.
 *
 * ZERO QUERIES: node_load() ran field_attach_load(), so both collections are
 * already in memory with their fid, filename, uri and filemime. See the @file
 * block for why this is not myapi_service_request_load_images().
 *
 * The attachment comes first and the images follow in their stored delta
 * order, which is the order they were uploaded in and the order the app paints
 * them.
 *
 * The langcode/delta walk is the one myapi_building_admin_field_target_ids()
 * already uses, and for the same reason: a field is keyed by language before
 * it is keyed by delta, and this module must not assume LANGUAGE_NONE on a
 * site that may one day be translated.
 *
 * An item with no fid is skipped — that is the empty widget row Drupal leaves
 * behind, and it would otherwise become a table row with a link to nothing.
 *
 * PURE: no database, no Drupal call.
 *
 * @param object $node
 *   The 'service_request' node.
 *
 * @return array
 *   A list of ['fid', 'filename', 'uri', 'filemime', 'source'], where source
 *   is 'attachment' or 'image'.
 */
function myapi_service_request_admin_node_files($node) {
  $files = array();

  foreach (array('field_attachment' => 'attachment', 'field_images' => 'image') as $field_name => $source) {
    if (!is_object($node) || empty($node->{$field_name}) || !is_array($node->{$field_name})) {
      continue;
    }

    foreach ($node->{$field_name} as $items) {
      if (!is_array($items)) {
        continue;
      }

      foreach ($items as $item) {
        if (!is_array($item) || empty($item['fid'])) {
          continue;
        }

        $files[] = array(
          'fid'      => (int) $item['fid'],
          'filename' => isset($item['filename']) ? $item['filename'] : '',
          'uri'      => isset($item['uri']) ? $item['uri'] : '',
          'filemime' => isset($item['filemime']) ? $item['filemime'] : '',
          'source'   => $source,
        );
      }
    }
  }

  return $files;
}

/**
 * The three cells of each file row.
 *
 * The anchor is built by hand rather than with l(), because what it points at
 * is the absolute URL file_create_url() answers and not an internal Drupal
 * path — l() would have to be told it is external to leave it alone.
 *
 * A file whose filename was lost still offers a way to open it: the fid, which
 * is what the URL is built from anyway.
 *
 * @param array $files
 *   The list from myapi_service_request_admin_node_files().
 *
 * @return array
 *   One row of three cells per file. Already escaped.
 */
function myapi_service_request_admin_file_table_rows(array $files) {
  $sources = array(
    'attachment' => t('Adjunto'),
    'image'      => t('Imagen'),
  );

  $table_rows = array();

  foreach ($files as $file) {
    $name = ($file['filename'] === NULL || $file['filename'] === '')
      ? '#' . (int) $file['fid']
      : $file['filename'];

    $table_rows[] = array(
      '<a href="' . check_plain(file_create_url($file['uri'])) . '">' . check_plain($name) . '</a>',
      myapi_service_requests_text_label($file['filemime']),
      isset($sources[$file['source']]) ? $sources[$file['source']] : '—',
    );
  }

  return $table_rows;
}

/* ---------------------------------------------------------------------------
 * Page.
 * ------------------------------------------------------------------------ */

/**
 * Page callback of admin/content/service-requests/%node.
 *
 * Read-only from end to end. It runs three reused queries plus one user_load()
 * — the node itself the router already loaded — and renders four tables.
 * Nothing here writes, and nothing here links anywhere a write could happen.
 *
 * The three blocks below the summary are rendered whether or not the detail
 * query answered a row: none of them depends on the category or on the
 * requester, which are the two INNER JOINs that can make it answer nothing.
 *
 * @param object $node
 *   The 'service_request' node, loaded by the %node wildcard and already
 *   cleared by myapi_service_request_admin_detail_access().
 *
 * @return array
 *   A render array.
 */
function myapi_service_request_admin_detail_page($node) {
  module_load_include('inc', 'myapi', 'includes/myapi.service_request_query');
  module_load_include('inc', 'myapi', 'includes/myapi.service_request_detail');
  module_load_include('inc', 'myapi', 'includes/myapi.service_transaction_admin');
  module_load_include('inc', 'myapi', 'includes/myapi.service_requests_admin');

  $nid = (int) $node->nid;
  $row = myapi_service_request_detail_row($nid);

  $build = array();

  $build['back'] = array(
    '#markup' => '<p>' . l(t('Volver al listado'), 'admin/content/service-requests') . '</p>',
  );

  if (is_object($row)) {
    // The ONE user_load() of the screen, and only when there is a uid to
    // resolve: the detail query projects the requester's uid, never the name.
    $requester_name = NULL;
    if (isset($row->requester_uid) && $row->requester_uid !== NULL) {
      $account = user_load($row->requester_uid);
      $requester_name = (is_object($account) && isset($account->name)) ? $account->name : NULL;
    }

    $summary_rows = myapi_service_request_admin_summary_rows($row, $requester_name);
    $assigned_offer_id = (isset($row->assigned_offer_id) && $row->assigned_offer_id !== NULL)
      ? (int) $row->assigned_offer_id
      : NULL;
  }
  else {
    $build['broken'] = array(
      '#markup' => myapi_service_request_admin_broken_notice($nid),
    );
    $summary_rows = myapi_service_request_admin_degraded_summary_rows($node);
    $assigned_offer_id = NULL;
  }

  $build['summary'] = array(
    '#theme'  => 'table',
    '#prefix' => '<h2>' . t('Datos de la solicitud') . '</h2>',
    '#header' => array(t('Campo'), t('Valor')),
    '#rows'   => $summary_rows,
  );

  $build['timeline'] = array(
    '#theme'  => 'table',
    '#prefix' => '<h2>' . t('Línea de tiempo de transacciones') . '</h2>',
    '#header' => array(t('Estado'), t('Fecha del estado'), t('Comentario'), t('Autor')),
    '#rows'   => myapi_service_request_admin_timeline_table_rows(myapi_service_transaction_timeline_rows($nid)),
    '#empty'  => t('Todavía no hay transacciones para esta solicitud.'),
  );

  // The EMPTY provider list is what makes this the whole round and not one
  // bidder's own offers — see myapi_service_request_admin_offer_table_rows().
  $build['offers'] = array(
    '#theme'  => 'table',
    '#prefix' => '<h2>' . t('Ofertas recibidas') . '</h2>',
    '#header' => array(
      t('ID'),
      t('Proveedor'),
      t('Estado'),
      t('Importe'),
      t('Tipo de importe'),
      t('Válida hasta'),
      t('Mensaje'),
      t('Fecha'),
    ),
    '#rows'   => myapi_service_request_admin_offer_table_rows(myapi_service_request_load_offers($nid, array()), $assigned_offer_id),
    '#empty'  => t('Esta solicitud no ha recibido ofertas.'),
  );

  $build['files'] = array(
    '#theme'  => 'table',
    '#prefix' => '<h2>' . t('Adjuntos') . '</h2>',
    '#header' => array(t('Archivo'), t('Tipo'), t('Origen')),
    '#rows'   => myapi_service_request_admin_file_table_rows(myapi_service_request_admin_node_files($node)),
    '#empty'  => t('Esta solicitud no tiene archivos adjuntos.'),
  );

  return $build;
}
