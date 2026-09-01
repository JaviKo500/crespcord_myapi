<?php

/**
 * @file
 * The headers every managed file of this module is served with.
 *
 * SIX CALL SITES BUILT THIS BLOCK BY HAND until this file existed: the three
 * API endpoints that stream a file (claims, provider galleries, service
 * requests) and the three hook_file_download() branches that do the same for
 * the back office. All six wrote the same three headers, which meant a rule
 * added to one of them was a rule missing from five. Regla 3 de CLAUDE.md.
 *
 * WHAT THE RULE ACTUALLY IS. These files are served from the site's OWN origin,
 * and two of the three headers decide whether that is safe:
 *
 *   - 'Content-Disposition: inline' asks the browser to RENDER the file rather
 *     than download it, and it is what the app and the back office need for a
 *     photo.
 *   - 'Content-Type' tells the browser WHAT it is rendering.
 *
 * Put together, serving an attacker-supplied text/html — or an image/svg+xml,
 * which is markup that can carry <script> — inline from the site's origin IS
 * stored XSS, with the session of whoever opens the thumbnail. Nothing else in
 * the upload path stops it: myapi_node_files_save() re-checks the real MIME
 * with finfo, but the whitelist it checks against is DERIVED FROM THE FIELD'S
 * OWN file_extensions setting, which lives in the database and is edited from
 * the Drupal UI. Adding 'svg' to field_images is two clicks, reads like a
 * feature request, and would have silently opened that hole.
 *
 * So the decision is pinned HERE, in code, and it is deliberately NOT the
 * question the upload asks. The upload asks "is this one of the types this
 * field accepts?"; this file asks "can this type carry script?". A type the
 * field accepts but this file does not know is still stored and still served —
 * as an attachment, with an inert Content-Type — so the only thing a widened
 * field configuration can now cost is a download instead of a preview.
 */

/**
 * The MIME types that may be served inline.
 *
 * A CLOSED LIST OF TYPES THAT CANNOT CARRY SCRIPT, not a copy of what the
 * fields happen to accept today (png/jpg/jpeg for images, pdf/doc/docx/xls/xlsx
 * for attachments). Written that way on purpose: the fields are configuration
 * and this is a security boundary, so the list has to hold when the
 * configuration moves. gif and webp are here although no field asks for them
 * yet — they are raster images, they are safe, and a future field that accepts
 * them should get a preview rather than a surprise download.
 *
 * IMAGE/SVG+XML IS ABSENT AND THAT IS THE WHOLE POINT. It is an image by every
 * intuition and a script host by specification: an <svg> may contain a
 * <script> element and event-handler attributes, and a browser rendering one
 * inline executes them against this site's origin. Any list written as "the
 * image types" instead of "the types that cannot carry script" would have
 * included it.
 *
 * The office formats are absent for a milder reason: a browser cannot render
 * them anyway, so 'inline' buys nothing and 'attachment' is what the operator
 * actually wants.
 *
 * @return array  Lowercase MIME types.
 */
function myapi_file_download_inline_mimes() {
  return [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'application/pdf',
  ];
}

/**
 * Makes a stored filename safe to place inside a quoted header parameter.
 *
 * The six call sites interpolated $file->filename straight into
 * 'inline; filename="..."'. Drupal's file_munge_filename() makes a hostile name
 * hard to produce and PHP's header() refuses a newline outright, so this was
 * never the module's weakest point — but "hard to produce" is not a guarantee
 * an access-control header should rest on, and the fix costs one function.
 *
 * Three things go, in this order:
 *
 *   1. Control characters, CR and LF among them. A newline in a header value is
 *      a second header, and header() rejecting it would take the whole response
 *      down rather than serve a wrong one.
 *   2. The double quote and the backslash, which are what END the quoted string
 *      early and what escapes inside it. A name of `a".jpg` would otherwise
 *      close the parameter and let whatever follows read as a new one.
 *   3. Leading and trailing whitespace, which no longer means anything once the
 *      value is quoted.
 *
 * The empty result falls back to a generic name rather than to '', because a
 * 'filename=""' is a header a client may honour by saving nothing.
 *
 * @param mixed $filename  file_managed.filename, or anything at all.
 *
 * @return string  A non-empty name with no character that can escape the quotes.
 */
function myapi_file_download_safe_filename($filename) {
  if (!is_string($filename)) {
    return 'download';
  }

  // \x00-\x1F and \x7F: the C0 controls plus DEL. '"' and '\' close or escape
  // the quoted string.
  $safe = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '', $filename);
  $safe = trim((string) $safe);

  return $safe === '' ? 'download' : $safe;
}

/**
 * Builds the headers one managed file is streamed with.
 *
 * THE DISPOSITION AND THE CONTENT-TYPE ARE ONE DECISION AND NOT TWO. A file
 * whose type is not on the inline list is served as an attachment AND with an
 * inert 'application/octet-stream', because either half alone leaves the hole
 * open: 'attachment' with a text/html Content-Type still lets a browser that
 * ignores the disposition render the markup, and an inert type with 'inline'
 * still invites the sniffing that 'nosniff' has to be there to stop. They are
 * decided together, here, so no caller can take one and forget the other.
 *
 * 'X-Content-Type-Options: nosniff' travels on every response of this function,
 * inline or not: it is what stops a browser from deciding for itself that the
 * bytes look like HTML and rendering them regardless of what was declared.
 *
 * @param object $file   A loaded file_managed object.
 * @param array  $extra  Headers to add on top — the API endpoints pass their
 *                       'Cache-Control: private, no-store'. A key repeated here
 *                       wins over the ones computed above, which is what lets a
 *                       caller that genuinely needs a different disposition say
 *                       so out loud instead of rebuilding the block.
 *
 * @return array  Header name => value.
 */
function myapi_file_download_headers($file, array $extra = []) {
  $mime = is_object($file) && isset($file->filemime) ? drupal_strtolower((string) $file->filemime) : '';
  $inline = in_array($mime, myapi_file_download_inline_mimes(), TRUE);

  $headers = ['Content-Type' => $inline ? $mime : 'application/octet-stream'];

  // OMITTED RATHER THAN GUESSED when file_managed carries no size. Every real
  // row has one, but a 'Content-Length: 0' in front of a body that is not empty
  // is a truncated download the client reports as a corrupt file, while no
  // header at all just costs the progress bar. A wrong length is worse than a
  // missing one. Placed here so the order of the block stays the one every
  // caller has always sent: type, length, disposition.
  if (isset($file->filesize) && is_numeric($file->filesize)) {
    $headers['Content-Length'] = $file->filesize;
  }

  $headers['Content-Disposition'] = ($inline ? 'inline' : 'attachment')
    . '; filename="' . myapi_file_download_safe_filename(isset($file->filename) ? $file->filename : NULL) . '"';
  $headers['X-Content-Type-Options'] = 'nosniff';

  return $extra ? array_merge($headers, $extra) : $headers;
}
