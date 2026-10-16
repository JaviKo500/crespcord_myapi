<?php

/**
 * @file
 * Serves the back-office user manual to back-office accounts only (SPEC 133).
 *
 * The manual is static HTML built by scripts/build-manual.sh. The app and bot
 * manuals stay public under /manual/, served by Apache straight from disk; this
 * one lives OUTSIDE the document root and every request for it goes through
 * Drupal, because only Drupal knows whether the request carries a back-office
 * session. Apache cannot answer that question, and the two ways of making it
 * try (a shared .htpasswd, or checking that a session cookie is present) are
 * respectively a second password and no check at all.
 *
 * The published URLs did not change: /manual/backoffice/pagos/ used to be a
 * directory on disk and is now a Drupal path, because once the directory is
 * gone Drupal's own rewrite rule (!-f, !-d -> index.php) sends it here.
 */

/**
 * The roles that may read the back-office manual.
 *
 * Every role the manual is written for. It is NOT myapi_calendar_admin_roles()
 * although today the answer is the same: the question here is "may this
 * account read the manual?", and the two lists must be free to drift.
 *
 * @return array  Role names.
 */
function myapi_backoffice_manual_roles() {
  return ['administrator', 'backend', 'administrador edificio'];
}

/**
 * Decides what a request for the manual gets, from who is asking.
 *
 * Three outcomes and not two, which is why this is not an access callback: an
 * anonymous visitor is sent to the login form and brought back, while an
 * account that is logged in but has no back-office role gets a 403. An access
 * callback can only say yes or no, and "no" is a 403 for both.
 *
 * @param int   $uid    The current user's uid.
 * @param array $roles  The current user's role names (the values of
 *                      $user->roles).
 *
 * @return string  'login', 'denied' or 'allow'.
 */
function myapi_backoffice_manual_gate($uid, array $roles) {
  $uid = (int) $uid;
  if ($uid === 0) {
    return 'login';
  }
  if ($uid === 1 || array_intersect($roles, myapi_backoffice_manual_roles())) {
    return 'allow';
  }

  return 'denied';
}

/**
 * Turns the path segments after manual/backoffice into a file to serve.
 *
 * A CLOSED LIST OF SHAPES, never a concatenation of what the request sent. The
 * build only ever produces three kinds of file, so those three shapes are all
 * that is accepted, and with them there is no '..', no slash inside a segment
 * and no extension but .html (implied) and .png:
 *
 *   ()                     -> index.html
 *   (<slug>)               -> <slug>/index.html
 *   ('img', <name>.png)    -> img/<name>.png
 *
 * A trailing empty segment is what Drupal hands over for a URL ending in '/',
 * and it is ignored. 'img' is not a page slug.
 *
 * @param array $parts  The path segments, as the page callback receives them.
 *
 * @return string|null  The path relative to the manual directory, or NULL when
 *   the request matches none of the shapes (a 404).
 */
function myapi_backoffice_manual_relative_path(array $parts) {
  $parts = array_values($parts);
  if ($parts && end($parts) === '') {
    array_pop($parts);
  }
  foreach ($parts as $part) {
    if (!is_string($part)) {
      return NULL;
    }
  }

  if (!$parts) {
    return 'index.html';
  }
  if (count($parts) === 1 && $parts[0] !== 'img' && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $parts[0])) {
    return $parts[0] . '/index.html';
  }
  if (count($parts) === 2 && $parts[0] === 'img' && preg_match('/^[a-z0-9][a-z0-9_-]*\.png$/', $parts[1])) {
    return 'img/' . $parts[1];
  }

  return NULL;
}

/**
 * The one URL path a manual file is served from, relative to the site root.
 *
 * The manual's links are relative ('pagos/', '../img/x.png'), so a page only
 * works from its canonical path: /manual/backoffice/pagos/, with the trailing
 * slash. Two other ways of reaching the same page are common and both break
 * every link and screenshot on it:
 *   - without the slash (/manual/backoffice/pagos), the browser resolves the
 *     links against /manual/backoffice/;
 *   - through ?q= (/?q=manual/backoffice/pagos/), which is where Drupal sends
 *     the browser back after the login form on this site, because url() builds
 *     ?q= URLs here — and the links then resolve against /.
 * So a page is redirected to this path whenever the request did not use it.
 * Images have no links and are served from wherever they are asked.
 *
 * @param string $relative  Output of myapi_backoffice_manual_relative_path().
 *
 * @return string  e.g. 'manual/backoffice/pagos/' or 'manual/backoffice/img/x.png'.
 */
function myapi_backoffice_manual_canonical_path($relative) {
  $page = 'index.html';
  if (substr($relative, -strlen($page)) === $page) {
    $relative = substr($relative, 0, -strlen($page));
  }

  return 'manual/backoffice/' . $relative;
}

/**
 * Whether a request must be redirected to the canonical path of its file.
 *
 * @param string $relative      Output of myapi_backoffice_manual_relative_path().
 * @param string $request_path  The path of the request, without query string
 *                              (parse_url(request_uri(), PHP_URL_PATH)).
 * @param string $base_path     base_path(): '/' or the site's subdirectory.
 *
 * @return bool  TRUE for a page asked for anywhere but its canonical path.
 */
function myapi_backoffice_manual_needs_redirect($relative, $request_path, $base_path) {
  if (substr($relative, -4) === '.png') {
    return FALSE;
  }

  return (string) $request_path !== $base_path . myapi_backoffice_manual_canonical_path($relative);
}

/**
 * The headers a manual file is served with.
 *
 * 'private' on both: these responses depend on the session, and a shared cache
 * (a proxy, a CDN) that kept one would hand it to the next visitor without
 * asking Drupal. Pages are revalidated every time; screenshots are kept for a
 * year because the build appends ?v=<hash> to their URL whenever they change.
 *
 * @param string $relative  Output of myapi_backoffice_manual_relative_path().
 *
 * @return array  Header name => value.
 */
function myapi_backoffice_manual_headers($relative) {
  $is_png = substr($relative, -4) === '.png';

  return [
    'Content-Type' => $is_png ? 'image/png' : 'text/html; charset=utf-8',
    'Cache-Control' => $is_png ? 'private, max-age=31536000, immutable' : 'private, no-cache',
    'X-Content-Type-Options' => 'nosniff',
    'X-Robots-Tag' => 'noindex, nofollow',
  ];
}

/**
 * The absolute path of a manual file, or NULL when it is not inside the manual.
 *
 * The second barrier, after the closed list of shapes: realpath() resolves any
 * symlink left in the directory on the server, and the result must still be
 * inside it.
 *
 * @param string $dir       The manual directory.
 * @param string $relative  Output of myapi_backoffice_manual_relative_path().
 *
 * @return string|null
 */
function myapi_backoffice_manual_file($dir, $relative) {
  $root = realpath($dir);
  if ($root === FALSE) {
    return NULL;
  }
  $file = realpath($root . '/' . $relative);
  if ($file === FALSE || !is_file($file) || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0) {
    return NULL;
  }

  return $file;
}

/**
 * Page callback of manual/backoffice (SPEC 133).
 *
 * The route's access callback is TRUE; the decision is made here, see
 * myapi_backoffice_manual_gate(). Writes the file and exits, so the response
 * never goes through the theme or Drupal's page cache.
 *
 * @return int|null  MENU_ACCESS_DENIED or MENU_NOT_FOUND; nothing when a file
 *   was served or a redirect sent.
 */
function myapi_backoffice_manual_page() {
  global $user;

  $relative = myapi_backoffice_manual_relative_path(func_get_args());

  $gate = myapi_backoffice_manual_gate($user->uid, $user->roles);
  if ($gate === 'login') {
    // Back to the canonical page after logging in. Drupal sends the browser
    // there through a ?q= URL, and the redirect below fixes that on arrival.
    $destination = $relative === NULL ? 'manual/backoffice/' : myapi_backoffice_manual_canonical_path($relative);
    drupal_goto('user/login', ['query' => ['destination' => $destination]]);
  }
  if ($gate === 'denied') {
    return MENU_ACCESS_DENIED;
  }
  if ($relative === NULL) {
    return MENU_NOT_FOUND;
  }

  // An absolute URL, not a Drupal path: url() would build a ?q= URL on this
  // site, which is exactly the address the redirect is getting away from.
  $request_path = (string) parse_url(request_uri(), PHP_URL_PATH);
  if (myapi_backoffice_manual_needs_redirect($relative, $request_path, base_path())) {
    drupal_goto($GLOBALS['base_url'] . '/' . myapi_backoffice_manual_canonical_path($relative), [], 301);
  }

  $dir = variable_get('myapi_backoffice_manual_dir', '/var/www/manual-backoffice');
  $file = myapi_backoffice_manual_file($dir, $relative);
  if ($file === NULL) {
    return MENU_NOT_FOUND;
  }

  foreach (myapi_backoffice_manual_headers($relative) as $name => $value) {
    drupal_add_http_header($name, $value);
  }
  drupal_add_http_header('Content-Length', (string) filesize($file));
  drupal_send_headers();
  readfile($file);
  drupal_exit();
}
