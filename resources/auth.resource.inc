<?php

/**
 * @file
 * Auth resource for the myapi module.
 */

module_load_include('inc', 'myapi', 'includes/myapi.request');
module_load_include('inc', 'myapi', 'includes/myapi.response');
module_load_include('inc', 'myapi', 'includes/myapi.i18n');
module_load_include('inc', 'myapi', 'includes/myapi.token');
module_load_include('inc', 'myapi', 'includes/myapi.auth');
module_load_include('inc', 'myapi', 'includes/myapi.flood');
module_load_include('inc', 'myapi', 'includes/myapi.user');

/**
 * Dispatcher for /api/v1/auth/login.
 *
 * Routes by HTTP method. Only POST is supported.
 */
function myapi_auth_dispatch() {
  $method = myapi_request_method();
  if ($method === 'POST') {
    myapi_auth_login();
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Handles POST /api/v1/auth/login.
 *
 * Authenticates by username + password against dr_users and, on success,
 * issues an opaque access/refresh token pair and returns them together with
 * the basic user data.
 */
function myapi_auth_login() {
  $ip   = ip_address();
  $body = myapi_request_body();
  myapi_request_require_strings((array) $body, ['username', 'password']);

  $username = $body['username'];
  $password = $body['password'];

  myapi_flood_check('myapi_login_ip',   $ip,       'myapi_flood_login_ip_limit',   'myapi_flood_login_ip_window');
  myapi_flood_check('myapi_login_user', $username, 'myapi_flood_login_user_limit', 'myapi_flood_login_user_window');

  // user_check_password() lives in includes/password.inc, which Drupal 7 does
  // not load on every request; pull it in before verifying credentials.
  require_once DRUPAL_ROOT . '/' . variable_get('password_inc', 'includes/password.inc');

  // Load the account. Any failure below returns the same generic 401 so the
  // response never reveals whether a given username exists.
  $account = user_load_by_name($username);
  if (!$account || $account->uid == 0 || $account->status == 0
      || !user_check_password($password, $account)) {
    myapi_flood_register('myapi_login_ip',   $ip,       'myapi_flood_login_ip_window');
    myapi_flood_register('myapi_login_user', $username, 'myapi_flood_login_user_window');
    myapi_error('invalid_credentials', 401);
  }

  flood_clear_event('myapi_login_ip',   $ip);
  flood_clear_event('myapi_login_user', $username);

  // Issue and persist a fresh token pair.
  $access = myapi_token_generate_access();
  $refresh = myapi_token_generate_refresh();
  myapi_token_persist($account->uid, $access, $refresh);

  myapi_respond([
    'access_token'  => $access,
    'refresh_token' => $refresh,
    'expires_in'    => myapi_token_access_ttl(),
    'user'          => myapi_auth_build_user_payload($account),
  ], 200);
}

/**
 * Builds the "user" sub-object returned by login and refresh.
 *
 * Combines account properties, mapped roles, and profile fields (first name,
 * last name, DNI, phone) read via myapi_user_fetch_profile_fields(). Shared
 * by both endpoints so their "user" payload never drifts apart.
 *
 * @param object $account  Loaded user account.
 *
 * @return array  The "user" sub-object.
 */
function myapi_auth_build_user_payload($account) {
  // Map $account->roles (rid => name) to a list of {name, uid} objects, where
  // uid is the role id (rid) per the client requirement.
  $roles = [];
  foreach ($account->roles as $rid => $name) {
    $roles[] = ['name' => $name, 'uid' => (int) $rid];
  }

  $profile = myapi_user_fetch_profile_fields($account->uid);

  return [
    'uid'        => (int) $account->uid,
    'name'       => $account->name,
    'mail'       => $account->mail,
    'first_name' => $profile['first_name'],
    'last_name'  => $profile['last_name'],
    'dni'        => $profile['dni'],
    'phone'      => $profile['phone'],
    'picture'    => NULL,
    'roles'      => $roles,
  ];
}

/**
 * Dispatcher for /api/v1/auth/refresh.
 *
 * Routes by HTTP method. Only POST is supported.
 */
function myapi_auth_refresh_dispatch() {
  $method = myapi_request_method();
  if ($method === 'POST') {
    myapi_auth_refresh();
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Dispatcher for /api/v1/auth/logout.
 *
 * Routes by HTTP method. Only POST is supported.
 */
function myapi_auth_logout_dispatch() {
  $method = myapi_request_method();
  if ($method === 'POST') {
    myapi_auth_logout();
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Handles POST /api/v1/auth/logout.
 *
 * Authenticates via access token in Authorization header, verifies that the
 * refresh token in the body belongs to the same session, and revokes the row.
 */
function myapi_auth_logout() {
  $ip = ip_address();

  myapi_flood_check('myapi_logout_ip', $ip, 'myapi_flood_logout_ip_limit', 'myapi_flood_logout_ip_window');

  $row = myapi_auth_require_access_token();

  $body = myapi_request_body();
  myapi_request_require_fields((array) $body, ['refresh_token']);

  $refresh_hash = myapi_token_hash($body['refresh_token']);
  if ($refresh_hash !== $row->refresh_token_hash) {
    myapi_flood_register('myapi_logout_ip', $ip, 'myapi_flood_logout_ip_window');
    myapi_error('invalid_token', 401);
  }

  db_update('my_api_tokens')
    ->fields(['revoked' => 1])
    ->condition('id', $row->id)
    ->execute();

  flood_clear_event('myapi_logout_ip', $ip);

  myapi_respond([], 200, 'logout_success');
}

/**
 * Handles POST /api/v1/auth/refresh.
 *
 * Validates the incoming refresh token, revokes it, issues a new token pair,
 * and returns the new tokens together with the basic user data.
 */
function myapi_auth_refresh() {
  $ip = ip_address();

  myapi_flood_check('myapi_refresh_ip', $ip, 'myapi_flood_refresh_ip_limit', 'myapi_flood_refresh_ip_window');

  $body = myapi_request_body();
  myapi_request_require_fields((array) $body, ['refresh_token']);

  $refresh_token = $body['refresh_token'];
  $hash = myapi_token_hash($refresh_token);

  $row = db_select('my_api_tokens', 't')
    ->fields('t')
    ->condition('refresh_token_hash', $hash)
    ->condition('revoked', 0)
    ->execute()
    ->fetchObject();

  if (!$row) {
    myapi_flood_register('myapi_refresh_ip', $ip, 'myapi_flood_refresh_ip_window');
    myapi_error('invalid_token', 401);
  }

  if ($row->refresh_expires_at < REQUEST_TIME) {
    myapi_flood_register('myapi_refresh_ip', $ip, 'myapi_flood_refresh_ip_window');
    myapi_error('token_expired', 401);
  }

  $account = user_load($row->uid);
  if (!$account || $account->status != 1) {
    myapi_flood_register('myapi_refresh_ip', $ip, 'myapi_flood_refresh_ip_window');
    myapi_error('invalid_token', 401);
  }

  db_update('my_api_tokens')
    ->fields(['revoked' => 1])
    ->condition('id', $row->id)
    ->execute();

  flood_clear_event('myapi_refresh_ip', $ip);

  $access  = myapi_token_generate_access();
  $refresh = myapi_token_generate_refresh();
  myapi_token_persist($account->uid, $access, $refresh);

  myapi_respond([
    'access_token'  => $access,
    'refresh_token' => $refresh,
    'expires_in'    => myapi_token_access_ttl(),
    'user'          => myapi_auth_build_user_payload($account),
  ], 200);
}

/**
 * Dispatcher for /api/v1/auth/password/forgot.
 *
 * Routes by HTTP method. Only POST is supported.
 */
function myapi_auth_password_forgot_dispatch() {
  $method = myapi_request_method();
  if ($method === 'POST') {
    myapi_auth_password_forgot();
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Handles POST /api/v1/auth/password/forgot.
 *
 * Always responds with a generic 200, whether or not the account exists, so
 * the response never reveals account existence. If the account exists and is
 * active, issues a single-use reset token and emails a link to it.
 */
function myapi_auth_password_forgot() {
  $ip = ip_address();

  myapi_flood_check('myapi_forgot_ip', $ip, 'myapi_flood_forgot_ip_limit', 'myapi_flood_forgot_ip_window');

  $body = (array) myapi_request_body();

  $has_username = isset($body['username']) && is_string($body['username']) && trim($body['username']) !== '';
  $has_email    = isset($body['email']) && is_string($body['email']) && trim($body['email']) !== '';

  if (!$has_username && !$has_email) {
    myapi_error('missing_field', 422, ['@field' => 'username_or_email']);
  }

  $identifier = $has_username ? $body['username'] : $body['email'];

  myapi_flood_check('myapi_forgot_identifier', $identifier, 'myapi_flood_forgot_identifier_limit', 'myapi_flood_forgot_identifier_window');

  // Registered on every valid request, whether or not the account exists, so
  // this counter never becomes a side channel that reveals account existence.
  myapi_flood_register('myapi_forgot_ip', $ip, 'myapi_flood_forgot_ip_window');
  myapi_flood_register('myapi_forgot_identifier', $identifier, 'myapi_flood_forgot_identifier_window');

  // Username takes priority; email is tried only when username was not
  // provided, or was provided but did not match any account.
  $account = NULL;
  if ($has_username) {
    $account = user_load_by_name($body['username']);
  }
  if (!$account && $has_email) {
    $account = user_load_by_mail($body['email']);
  }

  if ($account && $account->status == 1) {
    $token = myapi_token_generate_reset();
    myapi_password_reset_token_invalidate_previous($account->uid);
    myapi_password_reset_token_persist($account->uid, $token, $ip);

    // 'lang' travels with the link so the password/reset page renders in the
    // same language the email was sent in, regardless of the browser's own
    // Accept-Language header (see myapi_get_lang()).
    $link = url('password/reset', ['query' => ['token' => $token, 'lang' => myapi_get_lang()], 'absolute' => TRUE]);
    $minutes = round(myapi_password_reset_ttl() / 60);

    drupal_mail('myapi', 'password_reset', $account->mail, myapi_get_lang(), [
      'link'     => $link,
      'minutes'  => $minutes,
      'name'     => $account->name,
      'language' => myapi_get_lang(),
    ]);
  }

  myapi_respond([], 200, 'password_reset_requested');
}

/**
 * Core password reset logic, shared by the JSON endpoint and the web page.
 *
 * Assumes $token and $new_password are already non-empty strings; presence is
 * validated by each caller. Never touches HTTP output — callers decide how to
 * present the result (JSON vs HTML).
 *
 * @param string $token         Plaintext reset token.
 * @param string $new_password  New password to set.
 *
 * @return array  ['ok' => TRUE] on success, or
 *                ['ok' => FALSE, 'error_code' => '...', 'replacements' => [...]] on failure.
 */
function myapi_auth_password_reset_execute($token, $new_password) {
  if (strlen($new_password) < 8) {
    return ['ok' => FALSE, 'error_code' => 'field_too_short', 'replacements' => ['@field' => 'new_password']];
  }
  if (strlen($new_password) > 255) {
    return ['ok' => FALSE, 'error_code' => 'field_too_long', 'replacements' => ['@field' => 'new_password']];
  }

  $hash = myapi_token_hash($token);

  $row = db_select('myapi_password_reset_tokens', 't')
    ->fields('t')
    ->condition('token_hash', $hash)
    ->condition('used', 0)
    ->execute()
    ->fetchObject();

  if (!$row) {
    return ['ok' => FALSE, 'error_code' => 'invalid_token', 'replacements' => []];
  }

  if ($row->expires_at < REQUEST_TIME) {
    return ['ok' => FALSE, 'error_code' => 'token_expired', 'replacements' => []];
  }

  $account = user_load($row->uid);
  if (!$account || $account->status != 1) {
    return ['ok' => FALSE, 'error_code' => 'invalid_token', 'replacements' => []];
  }

  db_update('myapi_password_reset_tokens')
    ->fields(['used' => 1])
    ->condition('id', $row->id)
    ->execute();

  user_save($account, ['pass' => $new_password]);

  db_update('my_api_tokens')
    ->fields(['revoked' => 1])
    ->condition('uid', $account->uid)
    ->condition('revoked', 0)
    ->execute();

  return ['ok' => TRUE];
}

/**
 * Dispatcher for /api/v1/auth/password/reset.
 *
 * Routes by HTTP method. Only POST is supported.
 */
function myapi_auth_password_reset_dispatch() {
  $method = myapi_request_method();
  if ($method === 'POST') {
    myapi_auth_password_reset();
  }
  else {
    myapi_error('method_not_allowed', 405);
  }
}

/**
 * Handles POST /api/v1/auth/password/reset.
 *
 * Wraps the flood check and myapi_auth_password_reset_execute() and responds
 * in JSON.
 */
function myapi_auth_password_reset() {
  $ip = ip_address();

  myapi_flood_check('myapi_reset_ip', $ip, 'myapi_flood_reset_ip_limit', 'myapi_flood_reset_ip_window');

  $body = (array) myapi_request_body();
  myapi_request_require_fields($body, ['token', 'new_password']);

  $result = myapi_auth_password_reset_execute($body['token'], $body['new_password']);

  if (!$result['ok']) {
    myapi_flood_register('myapi_reset_ip', $ip, 'myapi_flood_reset_ip_window');

    $status_by_error = [
      'invalid_token'   => 401,
      'token_expired'   => 401,
      'field_too_short' => 422,
      'field_too_long'  => 422,
    ];
    $status = isset($status_by_error[$result['error_code']]) ? $status_by_error[$result['error_code']] : 422;

    myapi_error($result['error_code'], $status, $result['replacements']);
  }

  flood_clear_event('myapi_reset_ip', $ip);

  myapi_respond([], 200, 'password_reset_success');
}

/**
 * Page callback for GET/POST password/reset.
 *
 * HTML fallback outside the JSON envelope, for when the deep link in the
 * password reset email does not open the app. Never uses
 * myapi_respond()/myapi_error() — this is the only HTML surface in the API.
 */
function myapi_auth_password_reset_page() {
  drupal_add_http_header('Content-Type', 'text/html; charset=utf-8');

  if (myapi_request_method() === 'POST') {
    myapi_auth_password_reset_page_post();
  }
  else {
    myapi_auth_password_reset_page_get();
  }
}

/**
 * Handles GET password/reset?token=xxx.
 *
 * Prints minimal HTML with a meta-refresh attempting to open the app's deep
 * link, plus a form as fallback. Without a token, prints an "invalid link"
 * message instead.
 */
function myapi_auth_password_reset_page_get() {
  $token = isset($_GET['token']) && is_string($_GET['token']) ? trim($_GET['token']) : '';

  if ($token === '') {
    print myapi_auth_password_reset_page_html_message(myapi_t('invalid_token'));
    drupal_exit();
  }

  print myapi_auth_password_reset_page_html_form($token, NULL, TRUE);
  drupal_exit();
}

/**
 * Handles POST password/reset (form submission).
 *
 * Reuses the same flood counter as the JSON endpoint (myapi_reset_ip) and the
 * same core reset logic (myapi_auth_password_reset_execute()).
 */
function myapi_auth_password_reset_page_post() {
  $ip = ip_address();

  if (!myapi_flood_is_allowed('myapi_reset_ip', $ip, 'myapi_flood_reset_ip_limit', 'myapi_flood_reset_ip_window')) {
    print myapi_auth_password_reset_page_html_message(myapi_t('too_many_attempts'));
    drupal_exit();
  }

  $token = isset($_POST['token']) && is_string($_POST['token']) ? trim($_POST['token']) : '';
  $new_password = isset($_POST['new_password']) && is_string($_POST['new_password']) ? trim($_POST['new_password']) : '';

  if ($token === '' || $new_password === '') {
    print myapi_auth_password_reset_page_html_form($token, myapi_t('invalid_field', ['@field' => 'new_password']));
    drupal_exit();
  }

  $result = myapi_auth_password_reset_execute($token, $new_password);

  if (!$result['ok']) {
    myapi_flood_register('myapi_reset_ip', $ip, 'myapi_flood_reset_ip_window');
    print myapi_auth_password_reset_page_html_form($token, myapi_t($result['error_code'], $result['replacements']));
    drupal_exit();
  }

  flood_clear_event('myapi_reset_ip', $ip);

  print myapi_auth_password_reset_page_html_message(myapi_t('password_reset_page_success'));
  drupal_exit();
}

/**
 * Renders the shared HTML document shell (background, card, logo).
 *
 * Both the message screen and the form screen wrap their content in this same
 * shell, so the CSS and logo markup are written once (no duplication between
 * the two render paths).
 *
 * @param string $inner_html    Already-built, already-sanitized HTML to place inside the card.
 * @param string $meta_refresh  Optional <meta http-equiv="refresh"> tag, or ''.
 *
 * @return string  Full HTML document.
 */
function myapi_auth_password_reset_page_html_shell($inner_html, $meta_refresh = '') {
  $title = check_plain(myapi_t('password_reset_page_title'));
  $lang = check_plain(myapi_get_lang());

  return '<!DOCTYPE html><html lang="' . $lang . '"><head>'
    . '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    . $meta_refresh
    . '<title>' . $title . '</title>'
    . '<style>' . myapi_auth_password_reset_page_css() . '</style>'
    . '</head><body>'
    . '<div class="card"><div class="logo">' . myapi_auth_password_reset_page_logo_svg() . '<span>CrespCord</span></div>'
    . $inner_html
    . '</div></body></html>';
}

/**
 * CrespCord design tokens and styles for the reset page card.
 *
 * Inline (no drupal_add_css()) since this page does not use the theme layer.
 *
 * @return string  Raw CSS, safe to embed as-is (no user input).
 */
function myapi_auth_password_reset_page_css() {
  return <<<'CSS'
:root{
  --brown-900:#2B1D14;
  --brown-800:#3D2A1E;
  --brown-700:#4A3326;
  --brown-600:#604030;
  --brown-500:#74513E;
  --brown-400:#8D6B56;
  --brown-200:#D6C5B6;
  --sand-25:#FCFAF7;
  --danger-600:#B14439;
  --danger-100:#F8E3DF;
  --success-600:#3F7D5A;
}
*{box-sizing:border-box;}
body{
  margin:0;
  min-height:100vh;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:24px;
  background:linear-gradient(160deg, var(--brown-600), var(--brown-800));
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
}
.card{
  width:100%;
  max-width:420px;
  background:var(--sand-25);
  border-radius:20px;
  box-shadow:0 20px 50px rgba(0,0,0,0.35);
  padding:36px 32px;
}
.logo{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:24px;}
.logo span{font-weight:700;font-size:18px;letter-spacing:0.02em;color:var(--brown-700);}
h1{margin:0 0 8px;font-size:22px;font-weight:700;color:var(--brown-900);text-align:center;}
.subtitle{margin:0 0 24px;font-size:14px;line-height:1.5;color:var(--brown-500);text-align:center;}
.message{font-size:15px;color:var(--brown-700);text-align:center;line-height:1.6;}
.field{margin-bottom:16px;}
label{display:block;font-size:13px;font-weight:600;color:var(--brown-700);margin-bottom:6px;}
.field-wrap{position:relative;}
input[type=password],input[type=text]{
  width:100%;
  padding:12px 44px 12px 14px;
  border:1px solid var(--brown-200);
  border-radius:10px;
  font-size:15px;
  background:#fff;
  color:var(--brown-900);
}
input:focus{outline:none;border-color:var(--brown-500);}
.toggle{
  position:absolute;
  right:8px;
  top:50%;
  transform:translateY(-50%);
  background:none;
  border:none;
  cursor:pointer;
  font-size:16px;
  padding:4px;
  line-height:1;
}
.reqs{list-style:none;margin:0 0 20px;padding:0;display:flex;flex-direction:column;gap:6px;}
.reqs li{font-size:13px;color:var(--brown-400);display:flex;align-items:center;gap:8px;}
.reqs li.ok{color:var(--success-600);}
.reqs li .dot{width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block;flex:none;}
button[type=submit]{
  width:100%;
  padding:13px;
  border:none;
  border-radius:10px;
  background:var(--brown-700);
  color:#fff;
  font-size:15px;
  font-weight:600;
  cursor:pointer;
  margin-top:4px;
}
button[type=submit]:disabled{opacity:0.5;cursor:not-allowed;}
.alert{background:var(--danger-100);color:var(--danger-600);border-radius:10px;padding:12px 14px;font-size:14px;margin-bottom:20px;}
CSS;
}

/**
 * CrespCord logo badge (circle icon + wordmark), inline SVG.
 *
 * No external assets — self-contained, consistent with the rest of the page.
 *
 * @return string  Raw SVG markup, safe to embed as-is (no user input).
 */
function myapi_auth_password_reset_page_logo_svg() {
  return <<<'SVG'
<svg viewBox="0 0 120 120" width="40" height="40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
<circle cx="60" cy="60" r="60" fill="#4A3326"></circle>
<g fill="none" stroke="#F7F1EA" stroke-width="6" stroke-linecap="round" stroke-linejoin="round">
<circle cx="60" cy="46" r="14"></circle>
<path d="M60 60 L60 66 M46 66 L74 66 M46 66 L46 80 M74 66 L74 80 M46 80 L74 80"></path>
</g>
<circle cx="60" cy="44" r="5" fill="#F7F1EA"></circle>
</svg>
SVG;
}

/**
 * Progressive-enhancement script for the reset form.
 *
 * Show/hide toggles, a live password requirements checklist, and a
 * confirm-password match check. Purely client-side: the server still only
 * enforces the 8-255 character rule in myapi_auth_password_reset_execute();
 * the form still submits new_password + token without JavaScript.
 *
 * @return string  Raw <script> tag, safe to embed as-is (no user input).
 */
function myapi_auth_password_reset_page_script() {
  return <<<'SCRIPT'
<script>
(function () {
  var toggles = document.querySelectorAll('.toggle');
  for (var i = 0; i < toggles.length; i++) {
    toggles[i].addEventListener('click', function (e) {
      var input = document.getElementById(e.currentTarget.getAttribute('data-target'));
      if (input) {
        input.type = input.type === 'password' ? 'text' : 'password';
      }
    });
  }

  var pwd = document.getElementById('new_password');
  var confirm = document.getElementById('confirm_password');
  var submitBtn = document.getElementById('submit-btn');
  var reqs = document.getElementById('reqs');

  var rules = {
    length: function (v) { return v.length >= 8; },
    upper: function (v) { return /[A-Z]/.test(v); },
    number: function (v) { return /[0-9]/.test(v); },
    symbol: function (v) { return /[^A-Za-z0-9]/.test(v); }
  };

  function validate() {
    if (!pwd) {
      return;
    }
    var value = pwd.value;
    var allOk = true;
    for (var rule in rules) {
      var ok = rules[rule](value);
      if (!ok) {
        allOk = false;
      }
      var li = reqs ? reqs.querySelector('[data-rule="' + rule + '"]') : null;
      if (li) {
        li.classList.toggle('ok', ok);
      }
    }
    var confirmValue = confirm ? confirm.value : value;
    if (submitBtn) {
      submitBtn.disabled = !(allOk && confirmValue === value && value.length > 0);
    }
  }

  if (pwd) {
    pwd.addEventListener('input', validate);
  }
  if (confirm) {
    confirm.addEventListener('input', validate);
  }
  validate();
})();
</script>
SCRIPT;
}

/**
 * Renders a minimal HTML page with a single message, no form.
 *
 * Used for the "invalid link", "too many attempts" and success screens.
 *
 * @param string $message  Already-translated message (sanitized before print).
 *
 * @return string  Full HTML document.
 */
function myapi_auth_password_reset_page_html_message($message) {
  $title = check_plain(myapi_t('password_reset_page_title'));
  $safe_message = check_plain($message);

  $inner = '<h1>' . $title . '</h1>'
    . '<p class="message">' . $safe_message . '</p>';

  return myapi_auth_password_reset_page_html_shell($inner);
}

/**
 * Renders the CrespCord-styled HTML reset form.
 *
 * When $include_meta_refresh is TRUE, adds a meta-refresh attempting to open
 * the app's deep link (only used on the initial GET, not on POST re-renders).
 * Includes a confirm-password field and a live requirements checklist as
 * client-side progressive enhancement; the server only enforces the 8-255
 * character rule, and the form still works with JavaScript disabled.
 *
 * @param string      $token                 Reset token, echoed into a hidden field (sanitized).
 * @param string|null $error_message         Already-translated error message, or NULL.
 * @param bool        $include_meta_refresh  Whether to add the deep-link meta-refresh.
 *
 * @return string  Full HTML document.
 */
function myapi_auth_password_reset_page_html_form($token, $error_message = NULL, $include_meta_refresh = FALSE) {
  $title = check_plain(myapi_t('password_reset_page_title'));
  $subtitle = check_plain(myapi_t('password_reset_page_subtitle'));
  $label = check_plain(myapi_t('password_reset_page_new_password_label'));
  $confirm_label = check_plain(myapi_t('password_reset_page_confirm_label'));
  $button = check_plain(myapi_t('password_reset_page_submit_button'));
  $req_length = check_plain(myapi_t('password_reset_page_req_length'));
  $req_upper = check_plain(myapi_t('password_reset_page_req_upper'));
  $req_number = check_plain(myapi_t('password_reset_page_req_number'));
  $req_symbol = check_plain(myapi_t('password_reset_page_req_symbol'));
  $safe_token = check_plain($token);
  // Carries the resolved language into the POST target's query string, so the
  // re-rendered form (error or success) after submission stays in the same
  // language as this GET, instead of falling back to the browser's own
  // Accept-Language header.
  $action = check_plain(url('password/reset', ['query' => ['lang' => myapi_get_lang()]]));

  $meta_refresh = '';
  if ($include_meta_refresh) {
    $deep_link_base = variable_get('myapi_password_reset_deep_link_base', 'myapp://reset-password');
    $deep_link = check_plain($deep_link_base . '?token=' . rawurlencode($token));
    $meta_refresh = '<meta http-equiv="refresh" content="0;url=' . $deep_link . '">';
  }

  $error_html = '';
  if ($error_message !== NULL) {
    $error_html = '<div class="alert">' . check_plain($error_message) . '</div>';
  }

  $inner = '<h1>' . $title . '</h1>'
    . '<p class="subtitle">' . $subtitle . '</p>'
    . $error_html
    . '<form method="post" action="' . $action . '">'
    . '<div class="field">'
    . '<label for="new_password">' . $label . '</label>'
    . '<div class="field-wrap">'
    . '<input type="password" id="new_password" name="new_password" required>'
    . '<button type="button" class="toggle" data-target="new_password" aria-label="toggle">&#128065;</button>'
    . '</div></div>'
    . '<ul class="reqs" id="reqs">'
    . '<li data-rule="length"><span class="dot"></span>' . $req_length . '</li>'
    . '<li data-rule="upper"><span class="dot"></span>' . $req_upper . '</li>'
    . '<li data-rule="number"><span class="dot"></span>' . $req_number . '</li>'
    . '<li data-rule="symbol"><span class="dot"></span>' . $req_symbol . '</li>'
    . '</ul>'
    . '<div class="field">'
    . '<label for="confirm_password">' . $confirm_label . '</label>'
    . '<div class="field-wrap">'
    . '<input type="password" id="confirm_password">'
    . '<button type="button" class="toggle" data-target="confirm_password" aria-label="toggle">&#128065;</button>'
    . '</div></div>'
    . '<input type="hidden" name="token" value="' . $safe_token . '">'
    . '<button type="submit" id="submit-btn">' . $button . '</button>'
    . '</form>'
    . myapi_auth_password_reset_page_script();

  return myapi_auth_password_reset_page_html_shell($inner, $meta_refresh);
}
