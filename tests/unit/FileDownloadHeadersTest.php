<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.file_download.inc';

/**
 * Unit tests for includes/myapi.file_download.inc.
 *
 * These files are served from the site's OWN origin with
 * 'Content-Disposition: inline', which asks the browser to render them. That
 * combination is safe for a photo and is stored XSS for anything that can
 * carry script, so the list in myapi_file_download_inline_mimes() is an access
 * boundary and not a formatting preference — which is why it gets its own
 * suite rather than being asserted in passing by the four endpoint tests that
 * happen to stream a JPEG.
 *
 * The cases below are written as "what must NEVER be inline" first, because
 * that is the direction that fails silently: an image the browser downloads
 * instead of previewing is a bug somebody reports on day one, while an SVG
 * rendered inline is a hole nobody sees.
 */
class FileDownloadHeadersTest extends TestCase {

  /**
   * A file_managed row, as file_load() would hand it over.
   */
  private function file($filemime, $filename = 'x', $filesize = 1024) {
    return (object) [
      'filemime' => $filemime,
      'filename' => $filename,
      'filesize' => $filesize,
    ];
  }

  /* -------------------------------------------------------------------------
   * What must never be rendered inline.
   * ---------------------------------------------------------------------- */

  /**
   * SVG is not served inline, however much it looks like an image.
   *
   * The single case this file exists for. An <svg> may contain a <script>
   * element and event-handler attributes, so a browser rendering one inline
   * runs it against this site's origin, with the session of whoever opened the
   * thumbnail. Any allowlist written as "the image types" would have let it
   * through.
   */
  public function testSvgIsNeverInline() {
    $headers = myapi_file_download_headers($this->file('image/svg+xml', 'logo.svg'));

    $this->assertSame('application/octet-stream', $headers['Content-Type']);
    $this->assertStringStartsWith('attachment;', $headers['Content-Disposition']);
  }

  /**
   * HTML is not served inline either — the same hole with no disguise.
   */
  public function testHtmlIsNeverInline() {
    $headers = myapi_file_download_headers($this->file('text/html', 'note.html'));

    $this->assertSame('application/octet-stream', $headers['Content-Type']);
    $this->assertStringStartsWith('attachment;', $headers['Content-Disposition']);
  }

  /**
   * THE DISPOSITION AND THE CONTENT-TYPE MOVE TOGETHER, always.
   *
   * Either half alone leaves the hole open: 'attachment' with a text/html type
   * still lets a browser that ignores the disposition render the markup, and an
   * inert type with 'inline' still invites the sniffing. This is the assertion
   * that stops a future edit from relaxing one of them on its own.
   */
  public function testTheTypeAndTheDispositionNeverDisagree() {
    $mimes = [
      'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf',
      'image/svg+xml', 'text/html', 'application/xml', 'text/xml',
      'application/msword', 'application/vnd.ms-excel',
      'application/x-httpd-php', 'application/octet-stream', '',
    ];

    foreach ($mimes as $mime) {
      $headers = myapi_file_download_headers($this->file($mime, 'f'));
      $inline = strpos($headers['Content-Disposition'], 'inline;') === 0;

      if ($inline) {
        $this->assertSame($mime, $headers['Content-Type'], $mime . ': inline keeps the real type');
      }
      else {
        $this->assertSame('application/octet-stream', $headers['Content-Type'], $mime . ': attachment is inert');
      }
    }
  }

  /**
   * An unknown or empty MIME is an attachment, not a guess.
   *
   * file_managed.filemime is written from the upload, so "unknown" is a shape
   * the table really holds; failing closed is the only safe reading.
   */
  public function testAnUnknownMimeIsAnAttachment() {
    foreach (['', 'application/x-httpd-php', 'nonsense'] as $mime) {
      $headers = myapi_file_download_headers($this->file($mime));
      $this->assertSame('application/octet-stream', $headers['Content-Type'], $mime);
    }
  }

  /**
   * nosniff travels on every response, inline or attachment.
   *
   * It is what stops a browser from deciding for itself that the bytes look
   * like HTML and rendering them regardless of what was declared — so it is
   * needed most precisely on the attachment branch, where the declared type is
   * deliberately a lie about content the browser might recognise.
   */
  public function testNosniffIsAlwaysSent() {
    foreach (['image/png', 'image/svg+xml'] as $mime) {
      $headers = myapi_file_download_headers($this->file($mime));
      $this->assertSame('nosniff', $headers['X-Content-Type-Options'], $mime);
    }
  }

  /* -------------------------------------------------------------------------
   * What must keep working.
   * ---------------------------------------------------------------------- */

  /**
   * The types the app actually renders stay inline and keep their real type.
   *
   * png/jpg/jpeg is what field_images accepts and pdf is the readable half of
   * field_attachment; a regression here means every photo in the app becomes a
   * download prompt.
   */
  public function testPhotosAndPdfsStayInline() {
    foreach (['image/jpeg', 'image/png', 'application/pdf'] as $mime) {
      $headers = myapi_file_download_headers($this->file($mime, 'fuga.jpg', 20481));

      $this->assertSame($mime, $headers['Content-Type'], $mime);
      $this->assertSame('inline; filename="fuga.jpg"', $headers['Content-Disposition'], $mime);
      $this->assertSame(20481, $headers['Content-Length'], $mime);
    }
  }

  /**
   * The MIME comparison is case-insensitive.
   *
   * file_managed.filemime is whatever the upload wrote; an 'IMAGE/JPEG' that
   * fell through to attachment would be a silent regression for a real photo.
   */
  public function testTheMimeMatchIsCaseInsensitive() {
    $headers = myapi_file_download_headers($this->file('IMAGE/JPEG', 'a.jpg'));

    $this->assertSame('image/jpeg', $headers['Content-Type']);
    $this->assertStringStartsWith('inline;', $headers['Content-Disposition']);
  }

  /**
   * A caller's own header wins, which is what lets the API endpoints add their
   * 'Cache-Control: private, no-store' without rebuilding the block.
   */
  public function testExtraHeadersAreMergedAndWin() {
    $headers = myapi_file_download_headers($this->file('image/png'), [
      'Cache-Control' => 'private, no-store',
    ]);

    $this->assertSame('private, no-store', $headers['Cache-Control']);
    $this->assertSame('image/png', $headers['Content-Type'], 'the computed headers survive the merge');
  }

  /**
   * A row with no filesize omits Content-Length instead of claiming zero.
   *
   * Every real file_managed row has one, but 'Content-Length: 0' in front of a
   * non-empty body is a truncated download the client reports as a corrupt
   * file, while no header at all only costs the progress bar.
   */
  public function testAMissingFilesizeOmitsTheLengthRatherThanClaimingZero() {
    $file = (object) ['filemime' => 'image/jpeg', 'filename' => 'a.jpg'];
    $headers = myapi_file_download_headers($file);

    $this->assertArrayNotHasKey('Content-Length', $headers);
    $this->assertSame('inline; filename="a.jpg"', $headers['Content-Disposition']);
  }

  /* -------------------------------------------------------------------------
   * The filename, inside a quoted header parameter.
   * ---------------------------------------------------------------------- */

  /**
   * A double quote cannot close the parameter early.
   *
   * `a".jpg` interpolated raw produced 'inline; filename="a".jpg"', where
   * everything after the third quote reads as new parameters.
   */
  public function testAQuoteCannotEscapeTheFilenameParameter() {
    $headers = myapi_file_download_headers($this->file('image/jpeg', 'a".jpg'));

    $this->assertSame('inline; filename="a.jpg"', $headers['Content-Disposition']);
  }

  /**
   * A newline never reaches the header value.
   *
   * PHP's header() refuses one outright, so the raw version did not inject a
   * second header — it took the whole response down instead. Neither is an
   * answer.
   */
  public function testControlCharactersAreStripped() {
    $headers = myapi_file_download_headers($this->file('image/jpeg', "a\r\nX-Injected: 1\t.jpg"));

    $this->assertStringNotContainsString("\n", $headers['Content-Disposition']);
    $this->assertStringNotContainsString("\r", $headers['Content-Disposition']);
    $this->assertSame('inline; filename="aX-Injected: 1.jpg"', $headers['Content-Disposition']);
  }

  /**
   * A backslash goes too: inside a quoted string it is the escape character,
   * so it is the other way to change what the closing quote means.
   */
  public function testABackslashIsStripped() {
    $headers = myapi_file_download_headers($this->file('image/jpeg', 'a\\".jpg'));

    $this->assertSame('inline; filename="a.jpg"', $headers['Content-Disposition']);
  }

  /**
   * A name that sanitises down to nothing falls back to a generic one, because
   * 'filename=""' is a header a client may honour by saving nothing.
   */
  public function testAnEmptyNameFallsBackInsteadOfEmptyingTheParameter() {
    foreach (['', '   ', "\r\n", '"""'] as $name) {
      $headers = myapi_file_download_headers($this->file('image/jpeg', $name));
      $this->assertSame('inline; filename="download"', $headers['Content-Disposition'], var_export($name, TRUE));
    }
  }

  /**
   * Accents and spaces survive: they are legal inside a quoted string and
   * stripping them would mangle every real filename a Spanish-speaking
   * resident uploads.
   */
  public function testOrdinaryNamesAreLeftAlone() {
    $headers = myapi_file_download_headers($this->file('image/jpeg', 'fuga en el baño (2).jpg'));

    $this->assertSame('inline; filename="fuga en el baño (2).jpg"', $headers['Content-Disposition']);
  }

}
