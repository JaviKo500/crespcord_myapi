<?php

/**
 * @file
 * First bootstrap after scripts/local/import-db.sh loaded and sanitized a dump.
 *
 * Usage (inside the web container, as www-data):
 *   drush php-script post-import.php          set local passwords + check mail
 *   drush php-script post-import.php verify   check mail only (after cc all)
 *
 * LOCAL ONLY. It gives every account the same known password, which is the
 * last thing production should ever run, so it refuses to do anything unless
 * the database host is the 'db' container of docker-compose.yml.
 */

$connection = Database::getConnectionInfo();
if ($connection['default']['host'] !== 'db') {
  echo "Refusing to run: this is not the local docker database.\n";
  exit(1);
}

$args = function_exists('drush_get_arguments') ? drush_get_arguments() : array();
$verify_only = in_array('verify', $args, TRUE);

if (!$verify_only) {
  $password = getenv('LOCAL_USER_PASSWORD');
  if ($password === FALSE || $password === '') {
    echo "LOCAL_USER_PASSWORD is empty.\n";
    exit(1);
  }
  // One hash for everybody: hashing 5,000+ accounts one by one takes minutes
  // and a shared salt is irrelevant for a password that is public by design.
  require_once DRUPAL_ROOT . '/' . variable_get('password_inc', 'includes/password.inc');
  $updated = db_update('users')
    ->fields(array('pass' => user_hash_password($password)))
    ->condition('uid', 0, '>')
    ->execute();
  echo "Local password set on $updated accounts.\n";

  $admin = db_query('SELECT name FROM {users} WHERE uid = 1')->fetchField();
  echo "Back-office login: user '$admin' (uid 1) with the local password.\n";
}

// mail_system must be what the dump carried. The mailsystem module drops or
// resets the entries whose class it cannot load, and it does so silently:
// EXPECTED_MAIL_SYSTEM_B64 is the value import-db.sh read with SQL before this
// first bootstrap.
module_load_install('myapi');
$stored = unserialize(db_query("SELECT value FROM {variable} WHERE name = 'mail_system'")->fetchField());
$stored = is_array($stored) ? $stored : array();
$expected = unserialize(base64_decode((string) getenv('EXPECTED_MAIL_SYSTEM_B64')));
if (!is_array($expected) || empty($expected['default-system'])) {
  echo "ERROR: EXPECTED_MAIL_SYSTEM_B64 does not hold a mail_system value.\n";
  exit(1);
}

// Entries the bootstrap changed or removed. Entries that only exist in the
// stored value are fine: update hooks of this branch may have added mail keys.
$altered = array();
foreach ($expected as $key => $class) {
  if (!isset($stored[$key]) || $stored[$key] !== $class) {
    $altered[] = $key . ' (' . $class . ' -> ' . (isset($stored[$key]) ? $stored[$key] : 'removed') . ')';
  }
}
if ($altered) {
  echo "ERROR: mail_system was altered on bootstrap: " . implode(', ', $altered) . "\n";
  echo "A class it names could not be loaded. Fix the cause (make pull-code) and import again.\n";
  exit(1);
}

// After `drush updb` every key of this branch must be registered as well.
$missing = array();
foreach (myapi_html_mail_keys() as $key) {
  if (!isset($stored[$key]) || $stored[$key] !== 'MyapiHtmlMailSystem') {
    $missing[] = $key;
  }
}
$myapi_keys = 0;
foreach ($stored as $key => $class) {
  $myapi_keys += (strpos($key, 'myapi_') === 0) ? 1 : 0;
}
echo 'mail_system: default-system=' . $stored['default-system'] . ', myapi keys=' . $myapi_keys . "\n";
if ($missing) {
  echo ($verify_only ? 'ERROR' : 'Note (expected before drush updb)') . ': keys not mapped to MyapiHtmlMailSystem: ' . implode(', ', $missing) . "\n";
  if ($verify_only) {
    exit(1);
  }
}

$system = drupal_mail_system('myapi', 'password_reset');
echo 'myapi/password_reset is formatted by ' . get_class($system)
  . ' and delivered by ' . $stored['default-system']
  . ' -> ' . variable_get('smtp_host') . ':' . variable_get('smtp_port') . "\n";

if (variable_get('smtp_host') !== 'mailpit') {
  echo "ERROR: the smtp module is not pointing at Mailpit.\n";
  exit(1);
}
