<?php
/**
 * Routes every outgoing mail through the SMTP module and restores the myapi
 * HTML mail keys. The server has no sendmail, so DefaultMailSystem fails.
 *
 * Usage: sudo -u www-data drush php-script /tmp/fix_mail_system.php        (fix + verify)
 *        sudo -u www-data drush php-script /tmp/fix_mail_system.php test   (fix + send a test mail)
 */
$before = variable_get('mail_system', array());
echo "before (db): " . json_encode(unserialize(db_query("SELECT value FROM {variable} WHERE name = 'mail_system'")->fetchField())) . "\n";

$mail_system = $before;
$mail_system['default-system'] = 'HTMLMailSystem__SmtpMailSystem';
$mail_system['htmlmail'] = 'HTMLMailSystem__SmtpMailSystem';
variable_set('mail_system', $mail_system);

module_load_install('myapi');
myapi_mail_system_register();

$db = unserialize(db_query("SELECT value FROM {variable} WHERE name = 'mail_system'")->fetchField());
$myapi_keys = array_filter(array_keys($db), function ($k) { return strpos($k, 'myapi_') === 0; });
echo "after (db): default-system=" . $db['default-system'] . ", htmlmail=" . $db['htmlmail'] . ", myapi keys=" . count($myapi_keys) . "\n";

$system = drupal_mail_system('myapi', 'payment_created_admin');
echo "payment_created_admin goes through: " . get_class($system) . "\n";
echo "smtp_on=" . json_encode(variable_get('smtp_on')) . " host=" . variable_get('smtp_host') . ":" . variable_get('smtp_port') . "\n";

$args = function_exists('drush_get_arguments') ? drush_get_arguments() : array();
if (in_array('test', $args, TRUE)) {
  $to = 'jjavikog@gmail.com';
  $message = drupal_mail('smtp', 'smtp-test', $to, language_default());
  echo "test mail to $to: " . (!empty($message['result']) ? "sent (result=true)" : "FAILED (result=false), see drush watchdog-show --type=smtp") . "\n";
}
