<?php

/**
 * @file
 * Creates (or resets) the account scripts/local/run-e2e-tests.sh logs in with.
 *
 * Idempotent. LOCAL ONLY: refuses to run unless the database host is the 'db'
 * container of docker-compose.yml.
 */

$connection = Database::getConnectionInfo();
if ($connection['default']['host'] !== 'db') {
  echo "Refusing to run: this is not the local docker database.\n";
  exit(1);
}

$name = getenv('E2E_USER');
$mail = getenv('E2E_MAIL');
$password = getenv('E2E_PASSWORD');
if (!$name || !$mail || !$password) {
  echo "E2E_USER, E2E_MAIL and E2E_PASSWORD are required.\n";
  exit(1);
}

$account = user_load_by_name($name);
$edit = array(
  'name' => $name,
  'mail' => $mail,
  'init' => $mail,
  'pass' => $password,
  'status' => 1,
);
$account = user_save($account ? $account : NULL, $edit);
if (!$account) {
  echo "Could not save the e2e account.\n";
  exit(1);
}

// A previous run may have left the login or reset flood counters high.
db_delete('flood')->execute();

echo "e2e account ready: {$account->name} (uid {$account->uid}).\n";
