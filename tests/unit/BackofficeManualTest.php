<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.backoffice_manual.inc';

/**
 * Unit tests for includes/myapi.backoffice_manual.inc (SPEC 133).
 *
 * The page callback reads a file from disk whose name comes from the URL, so
 * myapi_backoffice_manual_relative_path() is a security boundary: the cases
 * that must be REJECTED come first, because that is the direction that fails
 * silently. A page that 404s is reported on day one; a traversal that works is
 * not reported at all.
 */
class BackofficeManualTest extends TestCase {

  /**
   * A directory with the shape the build produces, plus a file outside it.
   */
  private $dir;
  private $outside;

  protected function setUp(): void {
    $base = sys_get_temp_dir() . '/myapi-manual-' . uniqid();
    $this->dir = $base . '/manual';
    mkdir($this->dir . '/pagos', 0777, TRUE);
    mkdir($this->dir . '/img', 0777, TRUE);
    file_put_contents($this->dir . '/index.html', '<h1>index</h1>');
    file_put_contents($this->dir . '/pagos/index.html', '<h1>pagos</h1>');
    file_put_contents($this->dir . '/img/pagos_reporte.png', 'png');
    $this->outside = $base . '/secret.html';
    file_put_contents($this->outside, 'secret');
  }

  protected function tearDown(): void {
    $base = dirname($this->dir);
    $files = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
      $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($base);
  }

  /* -------------------------------------------------------------------------
   * Paths that must never resolve.
   * ---------------------------------------------------------------------- */

  /**
   * @dataProvider rejectedPaths
   */
  public function testRejectedPaths(array $parts) {
    $this->assertNull(myapi_backoffice_manual_relative_path($parts));
  }

  public function rejectedPaths() {
    return [
      'parent directory' => [['..']],
      'traversal through img' => [['img', '..', '..', 'etc', 'passwd']],
      'dot-dot file name' => [['img', '..png']],
      'svg screenshot' => [['img', 'logo.svg']],
      'php file' => [['pagos', 'index.php']],
      'explicit index.html' => [['pagos', 'index.html']],
      'upper case slug' => [['Pagos']],
      'slug with a dot' => [['pagos.html']],
      'encoded slash survives as text' => [['img', 'a%2F..%2Fb.png']],
      'img alone is not a page' => [['img']],
      'too deep' => [['pagos', 'extra', 'x']],
      'empty segment in the middle' => [['img', '', 'x.png']],
      'hidden file' => [['img', '.htaccess.png']],
      'not a string' => [[['array']]],
    ];
  }

  /* -------------------------------------------------------------------------
   * The three shapes the build produces.
   * ---------------------------------------------------------------------- */

  public function testIndexWithAndWithoutTrailingSlash() {
    $this->assertSame('index.html', myapi_backoffice_manual_relative_path([]));
    $this->assertSame('index.html', myapi_backoffice_manual_relative_path(['']));
  }

  public function testPageWithAndWithoutTrailingSlash() {
    $this->assertSame('pagos/index.html', myapi_backoffice_manual_relative_path(['pagos']));
    $this->assertSame('pagos/index.html', myapi_backoffice_manual_relative_path(['pagos', '']));
    $this->assertSame('acceso-y-menu/index.html', myapi_backoffice_manual_relative_path(['acceso-y-menu']));
  }

  public function testScreenshot() {
    $this->assertSame('img/pagos_reporte.png', myapi_backoffice_manual_relative_path(['img', 'pagos_reporte.png']));
    $this->assertSame('img/crespcord-icon.png', myapi_backoffice_manual_relative_path(['img', 'crespcord-icon.png']));
  }

  /* -------------------------------------------------------------------------
   * Who gets in.
   * ---------------------------------------------------------------------- */

  public function testAnonymousIsSentToLogin() {
    $this->assertSame('login', myapi_backoffice_manual_gate(0, ['anonymous user']));
  }

  public function testResidentIsDenied() {
    $this->assertSame('denied', myapi_backoffice_manual_gate(42, ['authenticated user']));
  }

  public function testEveryBackOfficeRoleIsAllowed() {
    foreach (['administrator', 'backend', 'administrador edificio'] as $role) {
      $this->assertSame('allow', myapi_backoffice_manual_gate(42, ['authenticated user', $role]), $role);
    }
  }

  public function testUidOneIsAllowedWithoutRoles() {
    $this->assertSame('allow', myapi_backoffice_manual_gate(1, ['authenticated user']));
  }

  /**
   * Role names are compared exactly: 'Backend' is not 'backend'.
   */
  public function testRoleNamesAreComparedExactly() {
    $this->assertSame('denied', myapi_backoffice_manual_gate(42, ['Backend', 'administrador']));
  }

  /* -------------------------------------------------------------------------
   * Trailing slash and headers.
   * ---------------------------------------------------------------------- */

  public function testCanonicalPaths() {
    $this->assertSame('manual/backoffice/', myapi_backoffice_manual_canonical_path('index.html'));
    $this->assertSame('manual/backoffice/pagos/', myapi_backoffice_manual_canonical_path('pagos/index.html'));
    $this->assertSame('manual/backoffice/img/x.png', myapi_backoffice_manual_canonical_path('img/x.png'));
  }

  public function testPageWithoutSlashIsRedirected() {
    $this->assertTrue(myapi_backoffice_manual_needs_redirect('pagos/index.html', '/manual/backoffice/pagos', '/'));
    $this->assertTrue(myapi_backoffice_manual_needs_redirect('index.html', '/manual/backoffice', '/'));
  }

  /**
   * Where Drupal sends the browser after the login form on this site: the
   * request path is '/', and every relative link of the page would break.
   */
  public function testPageReachedThroughQueryStringIsRedirected() {
    $this->assertTrue(myapi_backoffice_manual_needs_redirect('pagos/index.html', '/', '/'));
    $this->assertTrue(myapi_backoffice_manual_needs_redirect('pagos/index.html', '/index.php', '/'));
  }

  public function testCanonicalPageAndImagesAreNotRedirected() {
    $this->assertFalse(myapi_backoffice_manual_needs_redirect('pagos/index.html', '/manual/backoffice/pagos/', '/'));
    $this->assertFalse(myapi_backoffice_manual_needs_redirect('index.html', '/manual/backoffice/', '/'));
    $this->assertFalse(myapi_backoffice_manual_needs_redirect('img/x.png', '/', '/'));
  }

  public function testSiteInASubdirectory() {
    $this->assertFalse(myapi_backoffice_manual_needs_redirect('pagos/index.html', '/drupal/manual/backoffice/pagos/', '/drupal/'));
    $this->assertTrue(myapi_backoffice_manual_needs_redirect('pagos/index.html', '/manual/backoffice/pagos/', '/drupal/'));
  }

  public function testPageHeadersArePrivateAndRevalidated() {
    $headers = myapi_backoffice_manual_headers('pagos/index.html');

    $this->assertSame('text/html; charset=utf-8', $headers['Content-Type']);
    $this->assertSame('private, no-cache', $headers['Cache-Control']);
    $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
    $this->assertSame('noindex, nofollow', $headers['X-Robots-Tag']);
  }

  /**
   * A shared cache must never keep a screenshot: 'private', even for a year.
   */
  public function testScreenshotHeadersArePrivate() {
    $headers = myapi_backoffice_manual_headers('img/x.png');

    $this->assertSame('image/png', $headers['Content-Type']);
    $this->assertStringStartsWith('private,', $headers['Cache-Control']);
  }

  /* -------------------------------------------------------------------------
   * The file on disk.
   * ---------------------------------------------------------------------- */

  public function testExistingFilesResolveInsideTheDirectory() {
    $this->assertSame(realpath($this->dir . '/pagos/index.html'), myapi_backoffice_manual_file($this->dir, 'pagos/index.html'));
    $this->assertSame(realpath($this->dir . '/img/pagos_reporte.png'), myapi_backoffice_manual_file($this->dir, 'img/pagos_reporte.png'));
  }

  public function testMissingFileOrDirectoryIsNull() {
    $this->assertNull(myapi_backoffice_manual_file($this->dir, 'reservas/index.html'));
    $this->assertNull(myapi_backoffice_manual_file($this->dir . '/nope', 'index.html'));
  }

  /**
   * A directory is not a file, even when its name matches.
   */
  public function testDirectoryIsNotServed() {
    $this->assertNull(myapi_backoffice_manual_file($this->dir, 'pagos'));
  }

  /**
   * The second barrier: a symlink left on the server that points outside the
   * manual is not followed, although its name has a valid shape.
   */
  public function testSymlinkOutsideTheDirectoryIsNotFollowed() {
    mkdir($this->dir . '/fuga');
    symlink($this->outside, $this->dir . '/fuga/index.html');

    $this->assertSame('fuga/index.html', myapi_backoffice_manual_relative_path(['fuga']));
    $this->assertNull(myapi_backoffice_manual_file($this->dir, 'fuga/index.html'));
  }

}
