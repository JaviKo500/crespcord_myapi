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
    . '<img src="' . check_plain(myapi_mail_logo_url()) . '" alt="CrespCord" height="28" style="height:28px;vertical-align:middle;">'
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

/**
 * Shared HTML shell of the reservation emails.
 *
 * One builder for the three keys: they differ in their heading, their context
 * sentence, the label/value pairs and the footer, and in nothing else. Every
 * value is printed as-is because the caller escaped it (see the block comment
 * above); the labels are literals authored here.
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
    . '<img src="' . check_plain(myapi_mail_logo_url()) . '" alt="CrespCord" height="28" style="height:28px;vertical-align:middle;">'
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
