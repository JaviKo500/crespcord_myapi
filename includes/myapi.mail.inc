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
 * @param array $message  Mail message array, altered by reference.
 * @param array $params   Associative array with 'link', 'minutes' and 'language'.
 */
function myapi_mail_format_password_reset(&$message, $params) {
  $replacements = [
    '@link'    => $params['link'],
    '@minutes' => $params['minutes'],
  ];

  $message['subject'] = myapi_t('password_reset_email_subject', [], $params['language']);
  $message['body'][]  = myapi_t('password_reset_email_body', $replacements, $params['language']);
}
