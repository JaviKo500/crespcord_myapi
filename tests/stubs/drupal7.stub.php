<?php

/**
 * @file
 * The Drupal 7 core API, as signatures only, for PHPStan (SPEC 124).
 *
 * NOTHING EXECUTES THIS FILE. It is named in phpstan.neon under stubFiles,
 * which PHPStan reads and never includes; it is outside myapi.info, outside
 * every module_load_include(), and outside tests/unit/bootstrap.php. If it
 * were ever require'd next to a real Drupal, every one of these would be a
 * fatal redeclaration — which is the reason it lives under tests/stubs/ and
 * not in includes/.
 *
 * Why it exists: PHPStan's whole value here is telling us that a function this
 * module calls does not exist, because on Drupal 7 that is not a linter error,
 * it is a white screen on the request that reaches the line. To say that about
 * OUR 757 functions it first has to be told which of the callees are Drupal's,
 * or it reports all 1,453 of them and the signal is gone.
 *
 * The signatures are deliberately empty of information: every parameter is
 * variadic and every return is mixed. This file is not a model of Drupal and
 * must not become one — it asserts that core provides these names, nothing
 * more. Checking how they are CALLED would mean transcribing 114 real
 * signatures out of a codebase that is not in this repository, and a
 * transcription error would be a CI failure on correct code.
 *
 * Maintenance: a new Drupal core call means a new line here, and that is on
 * purpose. Drupal 7 has been end-of-life since January 2025 — a deliberate
 * stop to confirm the function exists in 7.x, and is not something remembered
 * from a later version, is worth the one line it costs.
 */

/**
 * Drupal core's mail system contract, implemented by MyapiMailSystem.
 */
interface MailSystemInterface {

  /**
   * @return mixed
   */
  public function format(array $message);

  /**
   * @return mixed
   */
  public function mail(array $message);

}

/**
 * The default implementation MyapiMailSystem extends.
 */
class DefaultMailSystem implements MailSystemInterface {

  /**
   * @return mixed
   */
  public function format(array $message) {}

  /**
   * @return mixed
   */
  public function mail(array $message) {}

}

/**
 * The queue factory myapi.mail_queue.inc and myapi.notification.inc call.
 */
class DrupalQueue {

  /**
   * @param mixed ...$arguments
   *
   * @return mixed
   */
  public static function get(...$arguments) {}

}

/**
 * Thrown by an update hook to abort the update, in myapi.install.
 */
class DrupalUpdateException extends Exception {}

/**
 * The type hook_query_alter() receives, in myapi.module.
 */
interface QueryAlterableInterface {}

// The Drupal 7 core constants this module names.
//
// The values mirror core's own, except the two that only exist at runtime:
// DRUPAL_ROOT is the site's directory and REQUEST_TIME the timestamp of the
// request, and neither has a value outside a request. They are placeholders,
// which is the reason phpstan.neon stays at a level that does not reason about
// constant VALUES — see the note there before raising it.
define('COMMENT_NODE_HIDDEN', 0);
define('DRUPAL_ROOT', '');
define('FIELD_CARDINALITY_UNLIMITED', -1);
define('FILE_CREATE_DIRECTORY', 1);
define('FILE_EXISTS_RENAME', 0);
define('FILE_STATUS_PERMANENT', 1);
define('LANGUAGE_NONE', 'und');
define('MENU_CALLBACK', 4);
define('MENU_NORMAL_ITEM', 6);
define('NODE_ACCESS_DENY', 'deny');
define('NODE_ACCESS_IGNORE', NULL);
define('REQUEST_TIME', 0);
define('REQUIREMENT_WARNING', 1);
define('WATCHDOG_ERROR', 3);
define('WATCHDOG_WARNING', 4);
define('WATCHDOG_NOTICE', 5);
define('WATCHDOG_INFO', 6);

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function cache_clear_all(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function check_plain(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function confirm_form(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function db_add_field(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function db_and(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function db_create_table(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function db_delete(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function db_field_exists(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function db_insert(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function db_like(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function db_merge(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function db_or(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function db_select(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function db_table_exists(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function db_transaction(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function db_update(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function decode_entities(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function drupal_add_css(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function drupal_add_http_header(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function drupal_add_js(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function drupal_basename(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function drupal_exit(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function drupal_get_form(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function drupal_get_path(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function drupal_get_schema_unprocessed(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function drupal_http_request(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function drupal_json_encode(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function drupal_mail(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function drupal_realpath(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function drupal_set_message(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function drupal_static(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function drupal_strlen(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function drupal_strtolower(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function drupal_substr(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function element_children(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function entity_get_controller(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function entity_load(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function field_create_field(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function field_create_instance(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function field_delete_field(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function field_delete_instance(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function field_info_cache_clear(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function field_info_field(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function field_info_instance(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function field_purge_batch(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function field_read_field(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function field_read_instance(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function field_update_field(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function field_update_instance(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function file_create_url(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function file_delete(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function file_field_widget_upload_validators(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function file_field_widget_uri(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function file_get_mimetype(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function file_load(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function file_load_multiple(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function file_move(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function file_prepare_directory(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function file_save(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function file_save_upload(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function file_stream_wrapper_uri_normalize(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function file_stream_wrapper_valid_scheme(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function file_transfer(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function file_uri_scheme(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function file_usage_add(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function file_usage_delete(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function flood_clear_event(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function flood_is_allowed(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function flood_register_event(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function form_error(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function form_load_include(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function form_set_error(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function format_date(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function ip_address(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function l(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function language_default(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function module_invoke_all(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function module_load_include(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function node_access(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function node_delete(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function node_load(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function node_object_prepare(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function node_save(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function node_type_delete(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function node_type_load(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function node_type_save(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function node_type_set_defaults(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function t(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function taxonomy_get_tree(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function taxonomy_term_load(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function taxonomy_vocabulary_delete(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function taxonomy_vocabulary_machine_name_load(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function taxonomy_vocabulary_save(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function theme(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function truncate_utf8(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function url(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function user_access(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function user_check_password(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function user_load(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function user_load_by_mail(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function user_load_by_name(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function user_load_multiple(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function user_role_grant_permissions(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function user_role_load_by_name(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function user_role_permissions(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function user_role_revoke_permissions(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function user_role_save(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function user_save(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function user_view_access(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function valid_email_address(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function variable_get(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function variable_set(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function watchdog(...$arguments) {}

/**
 * @param mixed ...$arguments
 *
 * @return mixed
 */
function watchdog_exception(...$arguments) {}
