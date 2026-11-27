-- Sanitizes a production dump right after it is loaded into the LOCAL database.
--
-- Run by scripts/local/import-db.sh with the mariadb client, BEFORE Drupal
-- bootstraps for the first time, so no hook, cron or queue worker ever sees
-- the real values. Plain SQL on purpose: it must not depend on the site being
-- able to boot. Idempotent: running it on an already sanitized database
-- changes nothing.
--
-- What it guarantees: no real e-mail address, phone number, live session,
-- queued notification or third-party credential is left where the site could
-- use it to reach a person or an external service.
-- What it does NOT do: anonymize names, ID numbers or amounts. The local
-- database still holds personal data and must be treated as such.
--
-- Passwords are not handled here (the Drupal hash needs PHP): see
-- scripts/local/post-import.php.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. Node titles that embed the owner's user name.
--
-- Nearly every user name on this site IS the e-mail address, and
-- auto_entitylabel builds the unit title as "<unit> - <condominium> - <owner>"
-- and the receipt/payment titles as "... - <unit title>". Those are rewritten
-- with the same test address the account gets below, so titles and accounts
-- still match. This has to happen while dr_users still has the real names.
-- ---------------------------------------------------------------------------
UPDATE dr_node n
  JOIN dr_field_data_field_propietario p
    ON p.entity_type = 'node' AND p.entity_id = n.nid
  JOIN dr_users u ON u.uid = p.field_propietario_target_id
SET n.title = REPLACE(n.title, u.name, CONCAT('user', u.uid, '@example.test'))
WHERE n.type = 'vivienda'
  AND u.name LIKE '%@%'
  AND u.name NOT LIKE '%@example.test';

UPDATE dr_node n
  JOIN dr_field_data_field_vivienda v
    ON v.entity_type = 'node' AND v.entity_id = n.nid
  JOIN dr_field_data_field_propietario p
    ON p.entity_type = 'node' AND p.entity_id = v.field_vivienda_target_id
  JOIN dr_users u ON u.uid = p.field_propietario_target_id
SET n.title = REPLACE(n.title, u.name, CONCAT('user', u.uid, '@example.test'))
WHERE n.type IN ('recibo', 'pagos', 'alicuota_extra')
  AND u.name LIKE '%@%'
  AND u.name NOT LIKE '%@example.test';

-- ---------------------------------------------------------------------------
-- 2. Accounts: test addresses. The user name follows when it was an address,
--    which keeps the name = mail convention of the site.
-- ---------------------------------------------------------------------------
UPDATE dr_users
SET name = IF(name LIKE '%@%', CONCAT('user', uid, '@example.test'), name),
    mail = CONCAT('user', uid, '@example.test'),
    init = CONCAT('user', uid, '@example.test')
WHERE uid > 0;

-- Comments keep a copy of the author's name.
UPDATE dr_comment c
  JOIN dr_users u ON u.uid = c.uid
SET c.name = u.name
WHERE c.uid > 0;

UPDATE dr_comment
SET name = REGEXP_REPLACE(name, '@[A-Za-z0-9-]+([.][A-Za-z0-9-]+)+', '@example.test'),
    mail = IF(mail = '', '', CONCAT('comment', cid, '@example.test')),
    hostname = '127.0.0.1';

-- ---------------------------------------------------------------------------
-- 3. Every other address: the domain is replaced, so what is left can no
--    longer be delivered. Covers titles the joins above did not reach (a unit
--    whose owner changed, free text) and the text fields found to hold
--    addresses. import-db.sh scans all text fields afterwards and reports any
--    column this list misses.
-- ---------------------------------------------------------------------------
UPDATE dr_node
SET title = REGEXP_REPLACE(title, '@[A-Za-z0-9-]+([.][A-Za-z0-9-]+)+', '@example.test')
WHERE title LIKE '%@%';

UPDATE dr_node_revision
SET title = REGEXP_REPLACE(title, '@[A-Za-z0-9-]+([.][A-Za-z0-9-]+)+', '@example.test')
WHERE title LIKE '%@%';

-- The current revision carries the same title as its node.
UPDATE dr_node_revision r
  JOIN dr_node n ON n.vid = r.vid
SET r.title = n.title
WHERE r.title <> n.title;

UPDATE dr_field_data_field_nombre
SET field_nombre_value = REGEXP_REPLACE(field_nombre_value, '@[A-Za-z0-9-]+([.][A-Za-z0-9-]+)+', '@example.test')
WHERE field_nombre_value LIKE '%@%';
UPDATE dr_field_revision_field_nombre
SET field_nombre_value = REGEXP_REPLACE(field_nombre_value, '@[A-Za-z0-9-]+([.][A-Za-z0-9-]+)+', '@example.test')
WHERE field_nombre_value LIKE '%@%';

UPDATE dr_field_data_field_apellidos
SET field_apellidos_value = REGEXP_REPLACE(field_apellidos_value, '@[A-Za-z0-9-]+([.][A-Za-z0-9-]+)+', '@example.test')
WHERE field_apellidos_value LIKE '%@%';
UPDATE dr_field_revision_field_apellidos
SET field_apellidos_value = REGEXP_REPLACE(field_apellidos_value, '@[A-Za-z0-9-]+([.][A-Za-z0-9-]+)+', '@example.test')
WHERE field_apellidos_value LIKE '%@%';

UPDATE dr_field_data_field_mensaje
SET field_mensaje_value = REGEXP_REPLACE(field_mensaje_value, '@[A-Za-z0-9-]+([.][A-Za-z0-9-]+)+', '@example.test')
WHERE field_mensaje_value LIKE '%@%';
UPDATE dr_field_revision_field_mensaje
SET field_mensaje_value = REGEXP_REPLACE(field_mensaje_value, '@[A-Za-z0-9-]+([.][A-Za-z0-9-]+)+', '@example.test')
WHERE field_mensaje_value LIKE '%@%';

UPDATE dr_field_data_field_informacion_pago
SET field_informacion_pago_value = REGEXP_REPLACE(field_informacion_pago_value, '@[A-Za-z0-9-]+([.][A-Za-z0-9-]+)+', '@example.test')
WHERE field_informacion_pago_value LIKE '%@%';
UPDATE dr_field_revision_field_informacion_pago
SET field_informacion_pago_value = REGEXP_REPLACE(field_informacion_pago_value, '@[A-Za-z0-9-]+([.][A-Za-z0-9-]+)+', '@example.test')
WHERE field_informacion_pago_value LIKE '%@%';

-- ---------------------------------------------------------------------------
-- 4. Phone numbers (the WhatsApp bot identifies people by them): a fake
--    number derived from the entity id, in the local 10-digit mobile format.
-- ---------------------------------------------------------------------------
UPDATE dr_field_data_field_telefono
SET field_telefono_value = CONCAT('09', LPAD(entity_id, 8, '0'));
UPDATE dr_field_revision_field_telefono
SET field_telefono_value = CONCAT('09', LPAD(entity_id, 8, '0'));

UPDATE dr_field_data_field_phone
SET field_phone_value = CONCAT('09', LPAD(entity_id, 8, '0'));
UPDATE dr_field_revision_field_phone
SET field_phone_value = CONCAT('09', LPAD(entity_id, 8, '0'));

UPDATE dr_field_data_field_phone_number
SET field_phone_number_value = CONCAT('09', LPAD(entity_id, 8, '0'));
UPDATE dr_field_revision_field_phone_number
SET field_phone_number_value = CONCAT('09', LPAD(entity_id, 8, '0'));

-- ---------------------------------------------------------------------------
-- 5. PayPhone (payment gateway) credentials and tokens, stored in nodes of
--    type configuracion_payphone and tokens_payphone. With these gone no
--    local code can charge a card or query a real merchant account.
-- ---------------------------------------------------------------------------
UPDATE dr_field_data_field_app_id            SET field_app_id_value            = 'local-disabled';
UPDATE dr_field_revision_field_app_id        SET field_app_id_value            = 'local-disabled';
UPDATE dr_field_data_field_client_id         SET field_client_id_value         = 'local-disabled';
UPDATE dr_field_revision_field_client_id     SET field_client_id_value         = 'local-disabled';
UPDATE dr_field_data_field_client_secret     SET field_client_secret_value     = 'local-disabled';
UPDATE dr_field_revision_field_client_secret SET field_client_secret_value     = 'local-disabled';
UPDATE dr_field_data_field_private_key       SET field_private_key_value       = 'local-disabled';
UPDATE dr_field_revision_field_private_key   SET field_private_key_value       = 'local-disabled';
UPDATE dr_field_data_field_public_key        SET field_public_key_value        = 'local-disabled';
UPDATE dr_field_revision_field_public_key    SET field_public_key_value        = 'local-disabled';
UPDATE dr_field_data_field_public_server_key     SET field_public_server_key_value = 'local-disabled';
UPDATE dr_field_revision_field_public_server_key SET field_public_server_key_value = 'local-disabled';
UPDATE dr_field_data_field_token             SET field_token_value             = 'local-disabled';
UPDATE dr_field_revision_field_token         SET field_token_value             = 'local-disabled';
UPDATE dr_field_data_field_refresh_token     SET field_refresh_token_value     = 'local-disabled';
UPDATE dr_field_revision_field_refresh_token SET field_refresh_token_value     = 'local-disabled';

-- ---------------------------------------------------------------------------
-- 6. Variables: SMTP credentials, site secrets and notification addresses.
--    settings.php overrides the smtp_* transport with Mailpit anyway; deleting
--    the stored values means the real ones are simply not on this machine.
--    The 'mail_system' variable is NOT touched: it must arrive exactly as it
--    is in production, myapi_* keys included.
-- ---------------------------------------------------------------------------
DELETE FROM dr_variable
WHERE name IN (
  'smtp_username', 'smtp_password', 'smtp_host', 'smtp_hostbackup',
  'smtp_port', 'smtp_protocol', 'smtp_reroute_address',
  'update_notify_emails', 'update_last_email_notification',
  'cron_key', 'drupal_private_key',
  'myapi_onesignal_app_id', 'myapi_onesignal_rest_api_key',
  'myapi_firebase_service_account', 'myapi_firebase_database_url',
  'myapi_bot_api_key'
);

UPDATE dr_variable SET value = 's:18:"local@example.test";'
WHERE name IN ('site_mail', 'smtp_from');

-- ---------------------------------------------------------------------------
-- 7. Anything pending or alive. pull-db.sh already dumps these tables
--    without rows; repeated here so that a dump taken any other way is safe.
-- ---------------------------------------------------------------------------
TRUNCATE dr_queue;
TRUNCATE dr_sessions;
TRUNCATE dr_flood;
TRUNCATE dr_batch;
TRUNCATE dr_semaphore;
TRUNCATE dr_watchdog;
TRUNCATE dr_job_schedule;
TRUNCATE dr_rules_scheduler;
TRUNCATE dr_my_api_tokens;
TRUNCATE dr_myapi_password_reset_tokens;
