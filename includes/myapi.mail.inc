<?php

/**
 * @file
 * Mail formatting helpers for the myapi module.
 *
 * Kept separate from myapi.module because hook_mail() implementations must
 * live in the .module file, but the actual message formatting is business
 * logic and does not belong there.
 */

/**
 * Absolute URL of the CrespCord logo used in every email header (SPEC 54).
 *
 * Built at runtime from $base_url and the module's own path — the PNG ships
 * versioned inside the module (assets/crespcord-icon.png), so a normal deploy
 * (git pull + drush cc all) already makes it reachable, with no
 * hook_install() or update hook to copy it into sites/default/files.
 *
 * @return string  Absolute URL, e.g. 'https://example.com/sites/all/modules/myapi/assets/crespcord-icon.png'.
 */
function myapi_mail_logo_url() {
  global $base_url;

  return $base_url . '/' . drupal_get_path('module', 'myapi') . '/assets/crespcord-icon.png';
}

/**
 * Formats the password reset email.
 *
 * Called from myapi_mail() (in myapi.module) for the 'password_reset' key.
 *
 * Sends full HTML (CrespCord branding, inline CSS) rather than plain text.
 * Drupal 7's DefaultMailSystem always runs the body through
 * drupal_html_to_text() before sending, which would strip this markup; the
 * 'myapi_password_reset' mail key is mapped to MyapiHtmlMailSystem (see
 * includes/myapi.mailsystem.inc), which skips that conversion.
 *
 * @param array $message  Mail message array, altered by reference.
 * @param array $params   Associative array with 'link', 'minutes', 'name' and 'language'.
 */
function myapi_mail_format_password_reset(&$message, $params) {
  $lang = $params['language'];

  $message['subject'] = myapi_t('password_reset_email_subject', [], $lang);
  $message['body'][]  = myapi_mail_password_reset_html($params);

  // Full HTML replaces the default 'text/plain; format=flowed' header set by
  // drupal_mail(); without this, clients would render the markup as text.
  $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
}

/**
 * Builds the password reset email HTML body.
 *
 * Table-based layout with inline styles throughout (no <style> block, no
 * external assets) for compatibility across email clients that strip
 * stylesheets or ignore CSS classes. Colors match the CrespCord brand palette
 * also used by the password/reset web page.
 *
 * @param array $params  Associative array with 'link', 'minutes', 'name' and 'language'.
 *
 * @return string  Full HTML document, safe to embed as-is (all reflected
 *   values are already sanitized).
 */
function myapi_mail_password_reset_html($params) {
  $lang = $params['language'];

  $subject  = check_plain(myapi_t('password_reset_email_subject', [], $lang));
  $greeting = check_plain(myapi_t('password_reset_email_greeting', ['@name' => $params['name']], $lang));
  $intro    = check_plain(myapi_t('password_reset_email_intro', [], $lang));
  $button   = check_plain(myapi_t('password_reset_page_submit_button', [], $lang));
  $expiry   = check_plain(myapi_t('password_reset_email_expiry', ['@minutes' => $params['minutes']], $lang));
  // password_reset_email_signoff carries a literal <br>; check_plain() would
  // escape it, so it is trusted catalogue copy (never user input) and printed
  // as-is, the same way password_reset_email_signoff is authored in myapi_t().
  $signoff  = myapi_t('password_reset_email_signoff', [], $lang);
  $link     = check_plain($params['link']);

  $font = "font-family:Arial,Helvetica,sans-serif;";

  return '<!DOCTYPE html><html lang="' . check_plain($lang) . '"><head>'
    . '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>' . $subject . '</title>'
    . '</head>'
    . '<body style="margin:0;padding:0;background-color:#3D2A1E;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#3D2A1E;padding:32px 16px;">'
    . '<tr><td align="center">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background-color:#FCFAF7;border-radius:16px;overflow:hidden;">'
    . '<tr><td style="background-color:#4A3326;padding:28px 32px;text-align:center;">'
    . '<img src="' . check_plain(myapi_mail_logo_url()) . '" alt="CrespCord" width="44" height="44" style="width:44px;height:44px;vertical-align:middle;">'
    . '</td></tr>'
    . '<tr><td style="padding:32px;">'
    . '<h1 style="' . $font . 'margin:0 0 16px;font-size:20px;color:#2B1D14;">' . $greeting . '</h1>'
    . '<p style="' . $font . 'margin:0 0 24px;font-size:15px;line-height:1.6;color:#74513E;">' . $intro . '</p>'
    . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 24px;">'
    . '<tr><td style="border-radius:10px;background-color:#4A3326;">'
    . '<a href="' . $link . '" style="' . $font . 'display:inline-block;padding:14px 32px;font-size:15px;font-weight:bold;color:#F7F1EA;text-decoration:none;border-radius:10px;">' . $button . '</a>'
    . '</td></tr>'
    . '</table>'
    . '<p style="' . $font . 'margin:0;font-size:13px;line-height:1.5;color:#907050;">' . $expiry . '</p>'
    . '</td></tr>'
    . '<tr><td style="padding:20px 32px;background-color:#F1EBE3;text-align:center;">'
    . '<p style="' . $font . 'margin:0;font-size:13px;line-height:1.5;color:#6B5D52;">' . $signoff . '</p>'
    . '</td></tr>'
    . '</table>'
    . '</td></tr>'
    . '</table>'
    . '</body></html>';
}

/* ---------------------------------------------------------------------------
 * Reservation emails (SPEC 48).
 *
 * Three mail keys, two formatters: 'reservation_created_user' and
 * 'reservation_cancelled_user' share one function because they differ only in
 * the subject, the context sentence and the closing line, while
 * 'reservation_created_admin' is a different message altogether.
 *
 * Every value in $params arrives ALREADY RESOLVED AND ESCAPED from
 * includes/myapi.reservation_notification.inc — strings, never nids. Two
 * consequences: the builders below print them as-is and must not escape again
 * (that would turn 'Cancha & golf' into 'Cancha &amp;amp; golf'), and the
 * subject line runs them through decode_entities() because a mail subject is
 * plain text, not markup.
 *
 * The texts are fixed in Spanish and do not go through myapi_t(), same
 * criterion as specs 27/28/30: the cancellation fires inside a
 * hook_node_update() where there is no Accept-Language to resolve.
 * ------------------------------------------------------------------------ */

/**
 * Formats the reservation email sent to the resident.
 *
 * Called from myapi_mail() (in myapi.module) for the 'reservation_created_user'
 * and 'reservation_cancelled_user' keys.
 *
 * @param array  $message  Mail message array, altered by reference.
 * @param array  $params   'name', 'area', 'condominium', 'unit', 'date',
 *                         'schedule', 'duration', 'nid' and the optional
 *                         'cancel_reason', already escaped.
 * @param string $key      The mail key being formatted.
 */
function myapi_mail_format_reservation_user(&$message, $params, $key) {
  $cancelled = ($key === 'reservation_cancelled_user');

  $message['subject'] = ($cancelled ? 'Reserva cancelada — ' : 'Reserva confirmada — ')
    . decode_entities($params['area']) . ', ' . decode_entities($params['date']);
  $message['body'][] = myapi_mail_reservation_user_html($params, $cancelled);

  // Full HTML replaces the default 'text/plain; format=flowed' header set by
  // drupal_mail(); the key is mapped to MyapiHtmlMailSystem in myapi.install so
  // the markup survives the format() step.
  $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
}

/**
 * Formats the reservation detail email sent to the 'backend' role.
 *
 * Called from myapi_mail() (in myapi.module) for the two 'backend' keys
 * (SPEC 50): 'reservation_created_admin', where the reservation is always
 * 'Confirmada' and there is no 'Cancelada por' line, and
 * 'reservation_cancelled_admin', which adds 'Cancelada por' and the optional
 * 'Motivo'. Only the subject and those two lines differ, so both share this
 * formatter and the HTML builder below.
 *
 * @param array  $message  Mail message array, altered by reference.
 * @param array  $params   'nid', 'user', 'email', 'unit', 'area',
 *                         'condominium', 'date', 'schedule', 'duration',
 *                         'status', 'created' and 'node_url', already escaped;
 *                         plus 'cancelled_by' and the optional 'cancel_reason'
 *                         on the cancellation key.
 * @param string $key      The mail key being formatted.
 */
function myapi_mail_format_reservation_admin(&$message, $params, $key) {
  $cancelled = ($key === 'reservation_cancelled_admin');

  $message['subject'] = ($cancelled ? 'Reserva cancelada #' : 'Nueva reserva #') . $params['nid'] . ' — '
    . decode_entities($params['area']) . ', ' . decode_entities($params['date']);
  $message['body'][] = myapi_mail_reservation_admin_html($params, $cancelled);

  $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
}

/**
 * Builds the HTML body of the resident's reservation email.
 *
 * Same table-based layout, inline styles and CrespCord palette as the password
 * reset email, for the same reason: email clients that strip stylesheets.
 *
 * @param array $params     Already-escaped values (see the formatter).
 * @param bool  $cancelled  TRUE for the cancellation variant.
 *
 * @return string  Full HTML document.
 */
function myapi_mail_reservation_user_html($params, $cancelled) {
  $title = $cancelled ? 'Reserva cancelada' : 'Reserva confirmada';
  $intro = $cancelled
    ? 'Tu reserva ha sido cancelada por un operador.'
    : 'Tu reserva ha sido confirmada.';

  $lines = [
    'Área'       => $params['area'],
    'Condominio' => $params['condominium'],
    'Vivienda'   => $params['unit'],
    'Fecha'      => $params['date'],
    'Horario'    => $params['schedule'],
    'Duración'   => $params['duration'],
  ];

  // SPEC 50: last line of the data block, only when there is a reason. The
  // isset() also covers the items enqueued before that spec, which carry no
  // such key and must keep rendering exactly as they did.
  if (isset($params['cancel_reason']) && $params['cancel_reason'] !== '') {
    $lines['Motivo'] = $params['cancel_reason'];
  }

  // The closing line only makes sense on a cancellation the resident did not
  // ask for; on a confirmation there is nothing to dispute.
  $footer = 'Reserva #' . $params['nid'];
  if ($cancelled) {
    $footer .= '<br>Si crees que se trata de un error, comunícate con la administración de tu condominio.';
  }

  return myapi_mail_reservation_html($title, 'Hola ' . $params['name'], $intro, $lines, $footer);
}

/**
 * Builds the HTML body of the detail email sent to the 'backend' role.
 *
 * The ten common lines are the ones the calendar detail panel shows (SPEC 47),
 * in the same order and with the same labels, so an operator reading the email
 * and an operator reading admin/content/reservation-calendar see the same
 * reservation described identically.
 *
 * The cancellation variant (SPEC 50) appends 'Cancelada por' and, only when
 * there is one, 'Motivo' — same criterion as the calendar panel, where a line
 * that does not apply is not drawn at all.
 *
 * @param array $params     Already-escaped values (see the formatter).
 * @param bool  $cancelled  TRUE for the cancellation variant.
 *
 * @return string  Full HTML document.
 */
function myapi_mail_reservation_admin_html($params, $cancelled = FALSE) {
  $lines = [
    'Usuario'    => $params['user'],
    'Email'      => $params['email'],
    'Vivienda'   => $params['unit'],
    'Área'       => $params['area'],
    'Condominio' => $params['condominium'],
    'Fecha'      => $params['date'],
    'Horario'    => $params['schedule'],
    'Duración'   => $params['duration'],
    'Estado'     => $params['status'],
    'Creada'     => $params['created'],
  ];

  if ($cancelled) {
    $lines['Cancelada por'] = isset($params['cancelled_by']) ? $params['cancelled_by'] : 'Usuario';
    if (isset($params['cancel_reason']) && $params['cancel_reason'] !== '') {
      $lines['Motivo'] = $params['cancel_reason'];
    }
  }

  return myapi_mail_reservation_html(
    'Reserva #' . $params['nid'],
    $cancelled ? 'Reserva cancelada' : 'Nueva reserva',
    $cancelled
      ? 'Un residente ha cancelado su reserva desde la aplicación.'
      : 'Se ha registrado una nueva reserva desde la aplicación.',
    $lines,
    'Puedes verla en el calendario de reservas del panel de administración.',
    array('label' => 'Ver reserva', 'url' => $params['node_url'])
  );
}

/* ---------------------------------------------------------------------------
 * Claim emails (SPEC 68).
 *
 * Five keys, four formatters — the two transaction ones differ in their subject
 * and their context sentence and in nothing else, so they share one, exactly as
 * the two resident reservation keys already do.
 *
 * All five reuse myapi_mail_reservation_html() below as their shell. Its name
 * says "reservation" because SPEC 48 wrote it, but its parameters (title,
 * heading, intro, label => value lines, footer, optional button) carry nothing
 * of that domain: duplicating 40 lines of inline-styled table markup to rename
 * a function would guarantee the two copies drift apart at the first change to
 * the palette (rule 3 of CLAUDE.md). The name is left alone so SPEC 48 keeps
 * describing exactly what it does.
 *
 * Every value in $params arrives ALREADY RESOLVED AND ESCAPED from the
 * orchestrator that enqueued it: the mail queue runs on cron, long after the
 * claim was saved, and the email must describe what was true at the instant of
 * the trigger. A claim deleted or a condominium renamed in between changes
 * nothing. That is also why nothing here escapes anything — see the block
 * comment of the reservation formatters above.
 * ------------------------------------------------------------------------ */

/**
 * Formats the claim creation email sent to the requester.
 *
 * Called from myapi_mail() (in myapi.module) for the 'claim_created_requester'
 * key.
 *
 * The context sentence is the one SPEC 61 already stores in the claim's initial
 * transaction, in the second person: the email and the first row of the
 * timeline say literally the same thing, so the resident never reads two
 * versions of the same acknowledgement.
 *
 * @param array $message  Mail message array, altered by reference.
 * @param array $params   'nid', 'type_label', 'type_noun', 'subject',
 *                        'subject_short', 'condominium', 'status',
 *                        'reception_date', 'description' and the optional
 *                        'name', already escaped.
 */
function myapi_mail_format_claim_created_user(&$message, $params) {
  $message['subject'] = decode_entities($params['type_label']) . ' recibido — '
    . decode_entities($params['subject_short']);
  $message['body'][] = myapi_mail_claim_html(
    $params,
    'Hemos recibido tu ' . $params['type_noun'] . '. Será revisado por la administración y te notificaremos cualquier novedad.'
  );

  // Full HTML replaces the default 'text/plain; format=flowed' header set by
  // drupal_mail(); the key is mapped to MyapiHtmlMailSystem in myapi.install so
  // the markup survives the format() step.
  $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
}

/**
 * Formats the claim email sent to the neighbours of the condominium.
 *
 * Called from myapi_mail() (in myapi.module) for the
 * 'claim_published_neighbour' key, which serves the TWO ways a claim reaches a
 * neighbour — born public, or turned public later. From their side both facts
 * are the same one, so there is one key, one type and one template.
 *
 * @param array $message  Mail message array, altered by reference.
 * @param array $params   Same keys as myapi_mail_format_claim_created_user().
 */
function myapi_mail_format_claim_published_neighbour(&$message, $params) {
  $message['subject'] = 'Nuevo ' . decode_entities($params['type_noun']) . ' en tu condominio — '
    . decode_entities($params['subject_short']);
  $message['body'][] = myapi_mail_claim_html(
    $params,
    'Se publicó un nuevo ' . $params['type_noun'] . ' en tu condominio.'
  );

  $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
}

/**
 * Formats the transaction email, in its two variants.
 *
 * Called from myapi_mail() (in myapi.module) for 'claim_transaction_requester'
 * and 'claim_transaction_neighbour'. Both carry the very same data block — the
 * claim is one and the transaction is one — and differ only in the subject and
 * in the context sentence, which is why they share this formatter.
 *
 * The operator's comment travels WHOLE here, unlike in the push, where it is
 * cut at 120: there is no 200-character budget to respect in an email.
 *
 * @param array  $message  Mail message array, altered by reference.
 * @param array  $params   'nid', 'type_label', 'type_noun', 'subject',
 *                         'subject_short', 'status', 'date', 'comment' and the
 *                         optional 'name', already escaped.
 * @param string $key      The mail key being formatted.
 */
function myapi_mail_format_claim_transaction_user(&$message, $params, $key) {
  $neighbour = ($key === 'claim_transaction_neighbour');

  $message['subject'] = $neighbour
    ? 'Novedad en un ' . decode_entities($params['type_noun']) . ' de tu condominio — ' . decode_entities($params['subject_short'])
    : 'Novedad en tu ' . decode_entities($params['type_noun']) . ' — ' . decode_entities($params['subject_short']);

  $message['body'][] = myapi_mail_claim_transaction_html($params, $neighbour);

  $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
}

/**
 * Formats the claim detail email sent to the back office.
 *
 * Called from myapi_mail() (in myapi.module) for the 'claim_created_admin' key,
 * which only fires when the claim was created FROM THE APP: an operator who
 * files a claim from node/add/reclamo already knows it exists.
 *
 * The subject carries the nid and the condominium rather than the subject of
 * the claim, so it is the one of the five that is NOT truncated to 80.
 *
 * @param array $message  Mail message array, altered by reference.
 * @param array $params   'nid', 'type_label', 'type_noun', 'subject',
 *                        'visibility', 'status', 'requester', 'email',
 *                        'condominium', 'reception_date', 'description',
 *                        'node_url' and the optional 'attachments', already
 *                        escaped.
 */
function myapi_mail_format_claim_created_admin(&$message, $params) {
  $message['subject'] = 'Nuevo ' . decode_entities($params['type_noun']) . ' #' . $params['nid']
    . ' — ' . decode_entities($params['condominium']);
  $message['body'][] = myapi_mail_claim_admin_html($params);

  $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
}

/**
 * Builds the HTML body of the two creation/publication emails.
 *
 * Same six lines for the requester and for the neighbours: what changes between
 * them is the context sentence, which the caller passes in, and nothing else.
 * Showing a neighbour less than the requester would be pointless — the claim is
 * public, and they can open the very same detail in the app.
 *
 * The subject of the claim travels WHOLE in the data block; only the mail
 * subject line is cut to 80.
 *
 * @param array  $params  Already-escaped values (see the formatters).
 * @param string $intro   Context sentence, already escaped.
 *
 * @return string  Full HTML document.
 */
function myapi_mail_claim_html($params, $intro) {
  $lines = array(
    'Asunto'      => $params['subject'],
    'Tipo'        => $params['type_label'],
    'Condominio'  => $params['condominium'],
    'Estado'      => $params['status'],
    'Recibido el' => $params['reception_date'],
  );

  // nl2br() over the already-escaped value: field_description is a textarea and
  // its line breaks are part of what the resident wrote. An empty description
  // drops the line rather than printing an empty block.
  if (isset($params['description']) && $params['description'] !== '') {
    $lines['Descripción'] = nl2br($params['description']);
  }

  // The neighbour emails carry no 'name': resolving the display name of every
  // owner and occupant of a condominium would be one query per recipient for a
  // greeting. 'Hola' alone reads perfectly well.
  $heading = (isset($params['name']) && $params['name'] !== '')
    ? 'Hola ' . $params['name']
    : 'Hola';

  return myapi_mail_reservation_html(
    $params['type_label'] . ' #' . $params['nid'],
    $heading,
    $intro,
    $lines,
    $params['type_label'] . ' #' . $params['nid']
  );
}

/**
 * Builds the HTML body of the two transaction emails.
 *
 * Four lines and no description of the claim: what happened is the transaction,
 * and the claim's own text is one tap away in the app. The comment is printed
 * whole, with its line breaks.
 *
 * @param array $params     Already-escaped values (see the formatter).
 * @param bool  $neighbour  TRUE for the neighbour variant.
 *
 * @return string  Full HTML document.
 */
function myapi_mail_claim_transaction_html($params, $neighbour) {
  $lines = array(
    'Asunto' => $params['subject'],
    'Estado' => $params['status'],
    'Fecha'  => $params['date'],
  );

  if (isset($params['comment']) && $params['comment'] !== '') {
    $lines['Comentario'] = nl2br($params['comment']);
  }

  $intro = $neighbour
    ? 'Un ' . $params['type_noun'] . ' de tu condominio tiene una novedad.'
    : 'Tu ' . $params['type_noun'] . ' tiene una novedad.';

  // Same greeting rule as the creation emails: the requester's name is already
  // resolved in the row, the neighbours' are not (see myapi_mail_claim_html()).
  $heading = (isset($params['name']) && $params['name'] !== '')
    ? 'Hola ' . $params['name']
    : 'Hola';

  return myapi_mail_reservation_html(
    $params['type_label'] . ' #' . $params['nid'],
    $heading,
    $intro,
    $lines,
    $params['type_label'] . ' #' . $params['nid']
  );
}

/**
 * Builds the HTML body of the detail email sent to the back office.
 *
 * There is deliberately NO 'Vivienda' line: the 'reclamo' bundle has no
 * field_unit, and deriving one from the requester's units is ambiguous the
 * moment they own two in the same condominium — guessing would be worse than
 * omitting.
 *
 * 'Adjuntos' is a COUNT and never a link: the files live in private:// and
 * their download requires a token (SPEC 65), so a link from an email client
 * would answer 404. The line disappears when the claim carries no file.
 *
 * @param array $params  Already-escaped values (see the formatter).
 *
 * @return string  Full HTML document.
 */
function myapi_mail_claim_admin_html($params) {
  $lines = array(
    $params['type_label'] => '#' . $params['nid'],
    'Asunto'              => $params['subject'],
    'Tipo'                => $params['type_label'],
    'Visibilidad'         => $params['visibility'],
    'Estado'              => $params['status'],
    'Solicitante'         => $params['requester'],
    'Email'               => $params['email'],
    'Condominio'          => $params['condominium'],
    'Recibido el'         => $params['reception_date'],
  );

  if (isset($params['attachments']) && $params['attachments'] !== '') {
    $lines['Adjuntos'] = $params['attachments'];
  }

  if (isset($params['description']) && $params['description'] !== '') {
    $lines['Descripción'] = nl2br($params['description']);
  }

  return myapi_mail_reservation_html(
    $params['type_label'] . ' #' . $params['nid'],
    'Nuevo ' . $params['type_noun'],
    'Se registró un nuevo ' . $params['type_noun'] . ' desde la aplicación.',
    $lines,
    'Puedes abrirlo desde el panel de administración.',
    array('label' => 'Abrir en el back office', 'url' => $params['node_url'])
  );
}

/**
 * Formats the email the back office gets when a requester closes their own
 * claim (SPEC 70).
 *
 * Called from myapi_mail() (in myapi.module) for the 'claim_closed_admin' key,
 * which only fires for PUT /api/v1/claims/%/close: it is the one status change
 * the back office does not make itself, so it is the one it has to be told
 * about.
 *
 * The subject carries the nid and the condominium rather than the subject of
 * the claim, exactly like 'claim_created_admin', and is therefore not truncated
 * to 80 either — both are the two emails an operator triages by claim, not by
 * headline.
 *
 * @param array $message  Mail message array, altered by reference.
 * @param array $params   'nid', 'type_label', 'type_noun', 'subject',
 *                        'subject_short', 'status', 'date', 'comment',
 *                        'condominium', 'requester' and 'node_url', already
 *                        escaped.
 */
function myapi_mail_format_claim_closed_admin(&$message, $params) {
  $message['subject'] = 'El solicitante cerró el ' . decode_entities($params['type_noun'])
    . ' #' . $params['nid'] . ' — ' . decode_entities($params['condominium']);
  $message['body'][] = myapi_mail_claim_closed_admin_html($params);

  $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
}

/**
 * Builds the HTML body of the closure email sent to the back office.
 *
 * The reason is the point of this email and is the one line that can be long,
 * so it goes last and travels WHOLE, nl2br()'d like every other free-text line
 * of this file — there is no 200-character budget to respect in an email.
 *
 * There is no 'Descripción' line: the operator opening the claim from the
 * button sees it in full, and repeating it here would bury the reason, which is
 * the only thing this email adds to what they already knew.
 *
 * @param array $params  Already-escaped values (see the formatter).
 *
 * @return string  Full HTML document.
 */
function myapi_mail_claim_closed_admin_html($params) {
  $lines = array(
    $params['type_label'] => '#' . $params['nid'],
    'Asunto'              => $params['subject'],
    'Condominio'          => $params['condominium'],
    'Solicitante'         => $params['requester'],
    'Estado'              => $params['status'],
    'Cerrado el'          => $params['date'],
  );

  if (isset($params['comment']) && $params['comment'] !== '') {
    $lines['Motivo'] = nl2br($params['comment']);
  }

  return myapi_mail_reservation_html(
    $params['type_label'] . ' #' . $params['nid'],
    $params['type_label'] . ' cerrado',
    'El solicitante cerró su ' . $params['type_noun'] . ' desde la aplicación.',
    $lines,
    'Puedes revisarlo desde el panel de administración.',
    array('label' => 'Abrir en el back office', 'url' => $params['node_url'])
  );
}

/* ---------------------------------------------------------------------------
 * Payment email (SPEC 80).
 *
 * One key, 'payment_created_admin': the detail of a payment a resident just
 * registered from the app, sent to every active user holding the 'backend'
 * role so they can verify it. Same shell as the reservation and claim emails,
 * for the same reason (rule 3 of CLAUDE.md).
 *
 * Every value in $params arrives ALREADY RESOLVED AND ESCAPED from
 * myapi_payment_backend_mail_params(), because the mail queue runs on cron long
 * after the payment was saved and the message must describe what was true at
 * the instant of the trigger. Nothing here escapes anything.
 * ------------------------------------------------------------------------ */

/**
 * Formats the payment detail email sent to the 'backend' role.
 *
 * Called from myapi_mail() (in myapi.module) for the 'payment_created_admin'
 * key, enqueued by myapi_payment_notify_created() from POST /api/v1/payments.
 *
 * The subject carries the reference and the amount, the two values an operator
 * triages payments by, plus the nid so two payments with the same reference
 * never collapse into one thread.
 *
 * @param array $message  Mail message array, altered by reference.
 * @param array $params   'nid', 'reference', 'amount', 'method', 'bank',
 *                        'date', 'unit', 'condominium', 'resident', 'email',
 *                        'file', 'status', 'created' and 'edit_url', already
 *                        escaped.
 */
function myapi_mail_format_payment_admin(&$message, $params) {
  $message['subject'] = 'Nuevo pago #' . $params['nid'] . ' — Ref. '
    . decode_entities($params['reference']) . ', ' . $params['amount'];
  $message['body'][] = myapi_mail_payment_admin_html($params);

  $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
}

/**
 * Builds the HTML body of that email.
 *
 * The lines are the ones of the payment detail an operator needs before opening
 * the node: who paid, for which unit, how much, by which method and whether
 * there is a receipt attached. 'Comprobante' is always drawn, with the
 * placeholder when there is none, because "no attachment" is itself the piece
 * of information that decides whether the payment can be verified at all.
 *
 * The button points at the EDIT form, not the node view: the operator's next
 * action is changing field_estado_pago, so landing on the form saves a click.
 *
 * @param array $params  Already-escaped values (see the formatter).
 *
 * @return string  Full HTML document.
 */
function myapi_mail_payment_admin_html($params) {
  $lines = array(
    'Referencia'     => $params['reference'],
    'Monto'          => $params['amount'],
    'Forma de pago'  => $params['method'],
    'Banco'          => $params['bank'],
    'Fecha del pago' => $params['date'],
    'Vivienda'       => $params['unit'],
    'Condominio'     => $params['condominium'],
    'Residente'      => $params['resident'],
    'Email'          => $params['email'],
    'Comprobante'    => $params['file'],
    'Estado'         => $params['status'],
    'Registrado el'  => $params['created'],
  );

  return myapi_mail_reservation_html(
    'Pago #' . $params['nid'],
    'Nuevo pago',
    'Un residente registró un pago desde la aplicación y está pendiente de verificación.',
    $lines,
    'Pago #' . $params['nid'] . '<br>Al verificarlo se actualizarán los saldos de la vivienda y del condominio.',
    array('label' => 'Revisar pago', 'url' => $params['edit_url'])
  );
}

/* ---------------------------------------------------------------------------
 * Service request emails (SPEC 109).
 *
 * Two keys, fired together by myapi_service_request_notify_created() from
 * POST /api/v1/service-requests:
 * - 'service_request_provider': the heads-up every account of a notified
 *   provider gets. Four lines and NO button — a provider has no back office to
 *   land on, so the call to action is the app itself.
 * - 'service_request_admin': the whole request, sent to the 'backend' role,
 *   with the button that opens the node.
 *
 * Same shell as the reservation, claim and payment emails, for the same reason
 * (rule 3 of CLAUDE.md).
 *
 * Every value in $params arrives ALREADY RESOLVED AND ESCAPED from
 * myapi_service_request_provider_mail_params() /
 * myapi_service_request_admin_mail_params(), because the mail queue drains on
 * cron long after the request was saved: a condominium renamed or a provider
 * deleted in between must not change the message. Nothing here escapes
 * anything.
 * ------------------------------------------------------------------------ */

/**
 * Formats the heads-up email sent to a notified provider's accounts.
 *
 * Called from myapi_mail() (in myapi.module) for the 'service_request_provider'
 * key. The subject says which of the two notices this is, because a direct
 * request is already the provider's and an open one is a race — the same split
 * the push title draws.
 *
 * @param array $message  Mail message array, altered by reference.
 * @param array $params   'nid', 'title', 'category', 'desired_start',
 *                        'condominium' and 'is_direct', already escaped.
 */
function myapi_mail_format_service_request_provider(&$message, $params) {
  $heading = !empty($params['is_direct'])
    ? 'Nueva solicitud directa'
    : 'Nueva solicitud de servicio';

  // A subject is plain text, so the escaped title is decoded back: a request
  // titled 'Fuga en el baño A&B' must not read 'A&amp;B' in the inbox list.
  $message['subject'] = $heading . ' — ' . decode_entities($params['title']);
  $message['body'][] = myapi_mail_service_request_provider_html($params);

  $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
}

/**
 * Builds the HTML body of that email.
 *
 * Four lines and not one more: the unit, the requester and the description are
 * deliberately absent from every channel a provider reads (SPEC 109), and the
 * detail endpoint is what decides when they stop being absent.
 *
 * No button, unlike the other four admin-facing emails of this module: the
 * provider's next step is the app, and a link into the back office would land
 * them on an access-denied page.
 *
 * @param array $params  Already-escaped values (see the formatter).
 *
 * @return string  Full HTML document.
 */
function myapi_mail_service_request_provider_html($params) {
  $is_direct = !empty($params['is_direct']);

  $intro = $is_direct
    ? 'Un residente creó una solicitud de servicio y te la asignó directamente.'
    : 'Un residente creó una solicitud de servicio en una de tus categorías.';

  $lines = array(
    'Asunto'          => $params['title'],
    'Categoría'       => $params['category'],
    'Fecha de inicio' => $params['desired_start'],
    'Condominio'      => $params['condominium'],
  );

  return myapi_mail_reservation_html(
    'Solicitud #' . $params['nid'],
    $is_direct ? 'Nueva solicitud directa' : 'Nueva solicitud de servicio',
    $intro,
    $lines,
    'Revisa la solicitud en la app.'
  );
}

/**
 * Formats the service request detail email sent to the 'backend' role.
 *
 * Called from myapi_mail() (in myapi.module) for the 'service_request_admin'
 * key. The subject carries the nid and the condominium, the two values an
 * operator triages requests by, and the nid keeps two requests with the same
 * title from collapsing into one thread.
 *
 * @param array $message  Mail message array, altered by reference.
 * @param array $params   'nid', 'title', 'type', 'category', 'desired_start',
 *                        'unit', 'condominium', 'requester', 'requester_email',
 *                        'description', 'created' and 'node_url', already
 *                        escaped.
 */
function myapi_mail_format_service_request_admin(&$message, $params) {
  $message['subject'] = 'Nueva solicitud de servicio #' . $params['nid']
    . ' — ' . decode_entities($params['condominium']);
  $message['body'][] = myapi_mail_service_request_admin_html($params);

  $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
}

/**
 * Builds the HTML body of that email.
 *
 * The ten lines are the whole request as it was born, in the order an operator
 * reads it: what is being asked, whether it is open to the market or already
 * awarded, where and when, and who asked. 'Tipo' comes resolved from the params
 * builder, which is where the "Directa a {trade name}" branch is tested.
 *
 * The button points at the node view and not at the edit form: unlike a payment
 * (SPEC 80), whose next action is a status change, a request is read before it
 * is touched.
 *
 * @param array $params  Already-escaped values (see the formatter).
 *
 * @return string  Full HTML document.
 */
function myapi_mail_service_request_admin_html($params) {
  $lines = array(
    'Asunto'                => $params['title'],
    'Tipo'                  => $params['type'],
    'Categoría'             => $params['category'],
    'Fecha de inicio'       => $params['desired_start'],
    'Vivienda'              => $params['unit'],
    'Condominio'            => $params['condominium'],
    'Solicitante'           => $params['requester'],
    'Email del solicitante' => $params['requester_email'],
    'Descripción'           => $params['description'],
    'Creada el'             => $params['created'],
  );

  return myapi_mail_reservation_html(
    'Solicitud #' . $params['nid'],
    'Nueva solicitud de servicio',
    'Un residente creó una solicitud de servicio desde la aplicación.',
    $lines,
    'Solicitud #' . $params['nid'],
    array('label' => 'Ver solicitud', 'url' => $params['node_url'])
  );
}

/* ---------------------------------------------------------------------------
 * Offer-received email (SPEC 110).
 *
 * One key, fired by myapi_service_request_notify_offer_received() from
 * POST /api/v1/service-requests/{id}/offers, to the resident who created the
 * request. Unlike the two 'service_request_provider'/'service_request_admin'
 * emails above, this one carries a button: the resident's next step is the app
 * (see the deep link on myapi_service_request_app_deep_link_url()), the same
 * reason SPEC 07's password-reset email has one.
 *
 * $params arrives ALREADY RESOLVED AND ESCAPED from
 * myapi_service_offer_resident_mail_params() — nothing here escapes anything.
 * ------------------------------------------------------------------------ */

/**
 * Formats the offer-received email sent to the resident.
 *
 * Called from myapi_mail() (in myapi.module) for the
 * 'service_request_offer_resident' key.
 *
 * @param array $message  Mail message array, altered by reference.
 * @param array $params   'nid', 'name', 'subject', 'provider_name',
 *                        'amount_text' and 'deep_link_url', already escaped.
 */
function myapi_mail_format_service_request_offer_resident(&$message, $params) {
  // A subject is plain text, so the escaped title is decoded back — same
  // reason myapi_mail_format_service_request_provider() does it.
  $message['subject'] = 'Nueva oferta recibida — ' . decode_entities($params['subject']);
  $message['body'][] = myapi_mail_service_request_offer_resident_html($params);

  $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
}

/**
 * Builds the HTML body of that email.
 *
 * Three lines — the same three the push carries — and a button to the app.
 *
 * @param array $params  Already-escaped values (see the formatter).
 *
 * @return string  Full HTML document.
 */
function myapi_mail_service_request_offer_resident_html($params) {
  // Same greeting rule as the request creation emails: 'Hola {name}' when it
  // resolved, 'Hola' alone otherwise.
  $heading = (isset($params['name']) && $params['name'] !== '')
    ? 'Hola ' . $params['name']
    : 'Hola';

  $lines = array(
    'Asunto'    => $params['subject'],
    'Proveedor' => $params['provider_name'],
    'Monto'     => $params['amount_text'],
  );

  return myapi_mail_reservation_html(
    'Solicitud #' . $params['nid'],
    $heading,
    'Recibiste una nueva oferta para tu solicitud de servicio.',
    $lines,
    'Puedes ver el detalle completo desde el botón de abajo.',
    array('label' => 'Ver solicitud', 'url' => $params['deep_link_url'])
  );
}

/* ---------------------------------------------------------------------------
 * Offer-withdrawn email (SPEC 111).
 *
 * One key, fired by myapi_service_request_notify_offer_withdrawn() from
 * PUT /api/v1/service-offers/{id}/withdraw, to the resident who created the
 * request. Same shape as the offer-received email above, minus the amount
 * line: a withdrawn offer's amount is no longer actionable (see the spec's
 * decisions).
 *
 * $params arrives ALREADY RESOLVED AND ESCAPED from
 * myapi_service_offer_withdrawn_resident_mail_params() — nothing here
 * escapes anything.
 * ------------------------------------------------------------------------ */

/**
 * Formats the offer-withdrawn email sent to the resident.
 *
 * Called from myapi_mail() (in myapi.module) for the
 * 'service_request_offer_withdrawn_resident' key.
 *
 * @param array $message  Mail message array, altered by reference.
 * @param array $params   'nid', 'name', 'subject', 'provider_name' and
 *                        'deep_link_url', already escaped.
 */
function myapi_mail_format_service_request_offer_withdrawn_resident(&$message, $params) {
  // A subject is plain text, so the escaped title is decoded back — same
  // reason myapi_mail_format_service_request_offer_resident() does it.
  $message['subject'] = 'Oferta retirada — ' . decode_entities($params['subject']);
  $message['body'][] = myapi_mail_service_request_offer_withdrawn_resident_html($params);

  $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
}

/**
 * Builds the HTML body of that email.
 *
 * Two lines — the same two the push carries, no amount — and a button to the
 * app.
 *
 * @param array $params  Already-escaped values (see the formatter).
 *
 * @return string  Full HTML document.
 */
function myapi_mail_service_request_offer_withdrawn_resident_html($params) {
  // Same greeting rule as the offer-received email: 'Hola {name}' when it
  // resolved, 'Hola' alone otherwise.
  $heading = (isset($params['name']) && $params['name'] !== '')
    ? 'Hola ' . $params['name']
    : 'Hola';

  $lines = array(
    'Asunto'    => $params['subject'],
    'Proveedor' => $params['provider_name'],
  );

  return myapi_mail_reservation_html(
    'Solicitud #' . $params['nid'],
    $heading,
    'El proveedor retiró la oferta que había enviado para tu solicitud de servicio.',
    $lines,
    'Puedes ver el detalle completo desde el botón de abajo.',
    array('label' => 'Ver solicitud', 'url' => $params['deep_link_url'])
  );
}

/* ---------------------------------------------------------------------------
 * Offer-award emails (SPEC 112).
 *
 * Three keys, fired together by myapi_service_request_notify_offer_accepted()
 * from PUT /api/v1/service-offers/{id}/accept:
 * - 'service_request_offer_accepted_provider': the winner. NO button, same
 *   criterion as 'service_request_provider' (SPEC 109) — a provider has no
 *   back office to land on.
 * - 'service_request_offer_rejected_provider': each loser. Same shape, minus
 *   the amount line, and without a single field naming the winner.
 * - 'service_request_awarded_admin': the whole award, sent to the 'backend'
 *   role, with the button that opens the node — same shape as
 *   'service_request_admin' (SPEC 109).
 *
 * $params arrives ALREADY RESOLVED AND ESCAPED from
 * myapi_service_offer_accepted_provider_mail_params() /
 * myapi_service_offer_rejected_provider_mail_params() /
 * myapi_service_request_awarded_admin_mail_params() — nothing here escapes
 * anything.
 * ------------------------------------------------------------------------ */

/**
 * Formats the award email sent to the winning provider.
 *
 * Called from myapi_mail() (in myapi.module) for the
 * 'service_request_offer_accepted_provider' key.
 *
 * @param array $message  Mail message array, altered by reference.
 * @param array $params   'nid', 'subject' and 'amount_text', already escaped.
 */
function myapi_mail_format_service_offer_accepted_provider(&$message, $params) {
  // A subject is plain text, so the escaped title is decoded back — same
  // reason myapi_mail_format_service_request_provider() does it.
  $message['subject'] = 'Fuiste seleccionado — ' . decode_entities($params['subject']);
  $message['body'][] = myapi_mail_service_offer_accepted_provider_html($params);

  $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
}

/**
 * Builds the HTML body of that email.
 *
 * Two lines — the same two the push carries — and no button, same criterion
 * as myapi_mail_service_request_provider_html(): the provider's next step is
 * the app.
 *
 * @param array $params  Already-escaped values (see the formatter).
 *
 * @return string  Full HTML document.
 */
function myapi_mail_service_offer_accepted_provider_html($params) {
  $lines = array(
    'Asunto' => $params['subject'],
    'Monto'  => $params['amount_text'],
  );

  return myapi_mail_reservation_html(
    'Solicitud #' . $params['nid'],
    '¡Fuiste seleccionado!',
    'Tu oferta fue elegida para esta solicitud de servicio.',
    $lines,
    'Revisa la solicitud en la app.'
  );
}

/**
 * Formats the award email sent to a losing provider.
 *
 * Called from myapi_mail() (in myapi.module) for the
 * 'service_request_offer_rejected_provider' key.
 *
 * @param array $message  Mail message array, altered by reference.
 * @param array $params   'nid' and 'subject', already escaped.
 */
function myapi_mail_format_service_offer_rejected_provider(&$message, $params) {
  // A subject is plain text, so the escaped title is decoded back — same
  // reason myapi_mail_format_service_offer_accepted_provider() does it.
  $message['subject'] = 'Solicitud adjudicada — ' . decode_entities($params['subject']);
  $message['body'][] = myapi_mail_service_offer_rejected_provider_html($params);

  $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
}

/**
 * Builds the HTML body of that email.
 *
 * One line only — the request's own subject — and no button. Deliberately
 * without the winner's identity or amount, same rule as the push (see
 * myapi_service_offer_rejected_push_body()).
 *
 * @param array $params  Already-escaped values (see the formatter).
 *
 * @return string  Full HTML document.
 */
function myapi_mail_service_offer_rejected_provider_html($params) {
  $lines = array(
    'Asunto' => $params['subject'],
  );

  return myapi_mail_reservation_html(
    'Solicitud #' . $params['nid'],
    'Ya se seleccionó un proveedor',
    'Se seleccionó a otro proveedor para esta solicitud de servicio.',
    $lines,
    'Revisa la solicitud en la app.'
  );
}

/**
 * Formats the award detail email sent to the 'backend' role.
 *
 * Called from myapi_mail() (in myapi.module) for the
 * 'service_request_awarded_admin' key. Same subject pattern as
 * myapi_mail_format_service_request_admin() (SPEC 109): the nid and the
 * condominium, the two values an operator triages by.
 *
 * @param array $message  Mail message array, altered by reference.
 * @param array $params   'nid', 'subject', 'provider_name', 'amount_text',
 *                        'condominium', 'unit' and 'node_url', already
 *                        escaped.
 */
function myapi_mail_format_service_request_awarded_admin(&$message, $params) {
  $message['subject'] = 'Solicitud adjudicada #' . $params['nid']
    . ' — ' . decode_entities($params['condominium']);
  $message['body'][] = myapi_mail_service_request_awarded_admin_html($params);

  $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
}

/**
 * Builds the HTML body of that email.
 *
 * Five lines, in the order an operator reads them: what was asked, who won it,
 * for how much, and where — the same order the spec's data model dictates.
 *
 * @param array $params  Already-escaped values (see the formatter).
 *
 * @return string  Full HTML document.
 */
function myapi_mail_service_request_awarded_admin_html($params) {
  $lines = array(
    'Asunto'              => $params['subject'],
    'Proveedor adjudicado' => $params['provider_name'],
    'Monto'               => $params['amount_text'],
    'Condominio'          => $params['condominium'],
    'Vivienda'            => $params['unit'],
  );

  return myapi_mail_reservation_html(
    'Solicitud #' . $params['nid'],
    'Solicitud adjudicada',
    'Se adjudicó una solicitud de servicio a un proveedor.',
    $lines,
    'Solicitud #' . $params['nid'],
    array('label' => 'Ver solicitud', 'url' => $params['node_url'])
  );
}

/* ---------------------------------------------------------------------------
 * Request-cancelled emails (SPEC 113).
 *
 * Two keys, fired together by myapi_service_request_notify_cancelled() from
 * PUT /api/v1/service-requests/{id}/cancel:
 * - 'service_request_cancelled_provider': each provider whose offer was
 *   'sent' or 'selected' at the instant of cancellation. NO button, same
 *   criterion as the rest of the emails to a provider (SPEC 109, 112). NO
 *   reason — the resident's private text never reaches a provider.
 * - 'service_request_cancelled_admin': the whole cancellation, sent to the
 *   'backend' role, with the button that opens the node — same shape as
 *   'service_request_admin' (SPEC 109) and 'service_request_awarded_admin'
 *   (SPEC 112). Carries the reason when the resident gave one.
 *
 * $params arrives ALREADY RESOLVED AND ESCAPED from
 * myapi_service_request_cancelled_provider_mail_params() /
 * myapi_service_request_cancelled_admin_mail_params() — nothing here escapes
 * anything.
 * ------------------------------------------------------------------------ */

/**
 * Formats the cancellation email sent to an affected provider.
 *
 * Called from myapi_mail() (in myapi.module) for the
 * 'service_request_cancelled_provider' key.
 *
 * @param array $message  Mail message array, altered by reference.
 * @param array $params   'nid' and 'subject', already escaped.
 */
function myapi_mail_format_service_request_cancelled_provider(&$message, $params) {
  // A subject is plain text, so the escaped title is decoded back — same
  // reason myapi_mail_format_service_offer_accepted_provider() does it.
  $message['subject'] = 'Solicitud cancelada — ' . decode_entities($params['subject']);
  $message['body'][] = myapi_mail_service_request_cancelled_provider_html($params);

  $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
}

/**
 * Builds the HTML body of that email.
 *
 * One line only — the request's own subject — and no button, same rule as
 * the push (see myapi_service_request_cancelled_push_body()). No status
 * distinction and no reason, same rule as the push.
 *
 * @param array $params  Already-escaped values (see the formatter).
 *
 * @return string  Full HTML document.
 */
function myapi_mail_service_request_cancelled_provider_html($params) {
  $lines = array(
    'Asunto' => $params['subject'],
  );

  return myapi_mail_reservation_html(
    'Solicitud #' . $params['nid'],
    'Solicitud cancelada',
    'El residente canceló esta solicitud de servicio.',
    $lines,
    'Revisa la solicitud en la app.'
  );
}

/**
 * Formats the cancellation detail email sent to the 'backend' role.
 *
 * Called from myapi_mail() (in myapi.module) for the
 * 'service_request_cancelled_admin' key. Same subject pattern as
 * myapi_mail_format_service_request_awarded_admin() (SPEC 112): the nid and
 * the condominium, the two values an operator triages by.
 *
 * @param array $message  Mail message array, altered by reference.
 * @param array $params   'nid', 'subject', 'reason', 'affected_count',
 *                        'condominium', 'unit' and 'node_url', already
 *                        escaped.
 */
function myapi_mail_format_service_request_cancelled_admin(&$message, $params) {
  $message['subject'] = 'Solicitud cancelada #' . $params['nid']
    . ' — ' . decode_entities($params['condominium']);
  $message['body'][] = myapi_mail_service_request_cancelled_admin_html($params);

  $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
}

/**
 * Builds the HTML body of that email.
 *
 * Five lines, in the order the spec's data model dictates: what was asked,
 * why (when the resident gave a reason), how many providers lost their offer,
 * and where.
 *
 * @param array $params  Already-escaped values (see the formatter).
 *
 * @return string  Full HTML document.
 */
function myapi_mail_service_request_cancelled_admin_html($params) {
  $lines = array(
    'Asunto'                 => $params['subject'],
    'Motivo'                 => $params['reason'],
    'Proveedores afectados'  => $params['affected_count'],
    'Condominio'             => $params['condominium'],
    'Vivienda'               => $params['unit'],
  );

  return myapi_mail_reservation_html(
    'Solicitud #' . $params['nid'],
    'Solicitud cancelada',
    'Se canceló una solicitud de servicio.',
    $lines,
    'Solicitud #' . $params['nid'],
    array('label' => 'Ver solicitud', 'url' => $params['node_url'])
  );
}

/**
 * Shared HTML shell of the reservation emails — and, since SPEC 68, of the five
 * claim ones.
 *
 * One builder for the three keys: they differ in their heading, their context
 * sentence, the label/value pairs and the footer, and in nothing else. Every
 * value is printed as-is because the caller escaped it (see the block comment
 * above); the labels are literals authored here.
 *
 * Nothing in its parameters belongs to the reservation domain, which is why the
 * claim emails call it instead of copying it. The name is SPEC 48's and stays.
 *
 * @param string     $title   Document title and card heading.
 * @param string     $heading Greeting or heading line above the intro.
 * @param string     $intro   Context sentence.
 * @param array      $lines   Label => already-escaped value, in display order.
 * @param string     $footer  Footer copy; may contain a literal <br>.
 * @param array|NULL $button  Optional 'label' and 'url' (already escaped) for
 *   a call-to-action button printed between the data table and the footer.
 *   NULL (the default) omits it, which is what the two resident emails do.
 *
 * @return string  Full HTML document.
 */
function myapi_mail_reservation_html($title, $heading, $intro, array $lines, $footer, $button = NULL) {
  $font = "font-family:Arial,Helvetica,sans-serif;";

  $rows = '';
  foreach ($lines as $label => $value) {
    $rows .= '<tr>'
      . '<td style="' . $font . 'padding:8px 12px;font-size:13px;color:#907050;border-bottom:1px solid #E7DED3;white-space:nowrap;vertical-align:top;">' . $label . '</td>'
      . '<td style="' . $font . 'padding:8px 12px;font-size:14px;color:#2B1D14;border-bottom:1px solid #E7DED3;">' . $value . '</td>'
      . '</tr>';
  }

  $button_markup = '';
  if ($button !== NULL) {
    $button_markup = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:20px auto 0;">'
      . '<tr><td style="border-radius:10px;background-color:#4A3326;">'
      . '<a href="' . $button['url'] . '" style="' . $font . 'display:inline-block;padding:12px 28px;font-size:14px;font-weight:bold;color:#F7F1EA;text-decoration:none;border-radius:10px;">' . $button['label'] . '</a>'
      . '</td></tr>'
      . '</table>';
  }

  return '<!DOCTYPE html><html lang="es"><head>'
    . '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>' . $title . '</title>'
    . '</head>'
    . '<body style="margin:0;padding:0;background-color:#3D2A1E;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#3D2A1E;padding:32px 16px;">'
    . '<tr><td align="center">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background-color:#FCFAF7;border-radius:16px;overflow:hidden;">'
    . '<tr><td style="background-color:#4A3326;padding:28px 32px;text-align:center;">'
    . '<img src="' . check_plain(myapi_mail_logo_url()) . '" alt="CrespCord" width="44" height="44" style="width:44px;height:44px;vertical-align:middle;">'
    . '</td></tr>'
    . '<tr><td style="padding:32px;">'
    . '<h1 style="' . $font . 'margin:0 0 16px;font-size:20px;color:#2B1D14;">' . $heading . '</h1>'
    . '<p style="' . $font . 'margin:0 0 24px;font-size:15px;line-height:1.6;color:#74513E;">' . $intro . '</p>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background-color:#F7F1EA;border-radius:10px;overflow:hidden;">'
    . $rows
    . '</table>'
    . $button_markup
    . '</td></tr>'
    . '<tr><td style="padding:20px 32px;background-color:#F1EBE3;text-align:center;">'
    . '<p style="' . $font . 'margin:0;font-size:13px;line-height:1.5;color:#6B5D52;">' . $footer . '</p>'
    . '</td></tr>'
    . '</table>'
    . '</td></tr>'
    . '</table>'
    . '</body></html>';
}
