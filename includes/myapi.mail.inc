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
    . '<span style="' . $font . 'font-size:20px;font-weight:bold;color:#F7F1EA;letter-spacing:1px;">CrespCord</span>'
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
