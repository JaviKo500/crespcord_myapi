<?php

/**
 * @file
 * Sends one real myapi mail (the password reset one) and one mail through
 * PHP mail(), so both delivery paths can be seen arriving in Mailpit.
 *
 * Usage: scripts/local/drush.sh php-script \
 *   /var/www/html/sites/all/modules/myapi/scripts/local/send-test-mail.php
 */

$connection = Database::getConnectionInfo();
if ($connection['default']['host'] !== 'db') {
  echo "Refusing to run: this is not the local docker database.\n";
  exit(1);
}

$to = 'mail-test@example.test';

// Path 1: a myapi key -> MyapiHtmlMailSystem (format) -> site default (smtp).
// Same parameters resources/auth.resource.inc passes for a real request.
$message = drupal_mail('myapi', 'password_reset', $to, 'es', array(
  'link' => url('password/reset', array('absolute' => TRUE, 'query' => array('token' => str_repeat('0', 64), 'lang' => 'es'))),
  'minutes' => 60,
  'name' => 'mail-test',
  'language' => 'es',
));
echo 'myapi/password_reset: ' . (!empty($message['result']) ? 'sent' : 'FAILED') . "\n";

// Path 2: PHP mail() -> sendmail_path (msmtp) -> Mailpit.
$sent = mail($to, 'Local sendmail_path test', "Sent with PHP mail() through msmtp.\n", "From: drupal@localhost.test\r\n");
echo 'PHP mail(): ' . ($sent ? 'sent' : 'FAILED') . "\n";
