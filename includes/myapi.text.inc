<?php

/**
 * @file
 * Plain-text flattening of stored rich text (SPEC 79) and search folding
 * (SPEC 119).
 *
 * Two helpers. myapi_text_to_plain() is shared by every resource that has to
 * send a long text field to the Flutter app; myapi_text_fold() is shared by
 * every resource that has to compare a user's search box against it. It lives in includes/ and not inside a
 * resource because the marketplace repeats the same problem in more than one
 * place (the provider description, the offer message, the rating comment), and
 * rule 3 of CLAUDE.md says shared logic is written once.
 *
 * It is NOT in includes/myapi.request.inc on purpose: that file is about input
 * coming in, this one is about output going out.
 *
 * ---------------------------------------------------------------------------
 * Why this exists next to check_plain(), and when to use which
 * ---------------------------------------------------------------------------
 * The module has two ways of treating a text field, and they are deliberate,
 * not an inconsistency to "fix" in the next refactor:
 *
 * - Single-line field with no rich editor (a name, a code): check_plain(),
 *   like /api/v1/banks and /api/v1/payment-methods do. There is no markup to
 *   flatten, and escaping keeps those values identical to the older endpoints.
 *
 * - Long text field edited with a rich editor (a term description): this
 *   helper. check_plain() ESCAPES, so a description written in the editor
 *   would reach the app as the literal `&lt;p&gt;Texto&lt;/p&gt;` — exactly
 *   what must not be painted inside a Flutter Text widget. The destination is
 *   not HTML, so escaping protects nothing there and only dirties the data.
 *
 * SPEC 79 decided NOT to apply this helper retroactively to the description of
 * /api/v1/banks and /api/v1/payment-methods: those responses are already
 * consumed in production, and changing them is a contract change that deserves
 * its own spec.
 */

/**
 * Flattens stored HTML into real plain text.
 *
 * Pure: no database, no site state, and the only Drupal call is
 * decode_entities() — which is what makes tests/unit/TextToPlainTest.php
 * possible with no site booted.
 *
 * Four steps, in this order:
 *   1. strip_tags()      — `<p>`, `<br>`, `<strong>` and friends go away.
 *   2. decode_entities() — `&nbsp;` -> space, `&amp;` -> `&`, `&lt;` -> `<`.
 *   3. Collapse every run of whitespace to a single space. Newlines, tabs and
 *      the no-break space (\xC2\xA0) that step 2 leaves behind from `&nbsp;`
 *      all count as whitespace here.
 *   4. trim().
 *
 * THE ORDER IS THE DELICATE PART. Decoding before stripping would turn a
 * stored `&lt;script&gt;` — characters the operator actually typed — into a
 * real `<script>` tag that strip_tags() would then delete, silently changing
 * the text that was written. With this order only markup that really was
 * markup is removed, and a stored `&lt;b&gt;` comes back as the literal text
 * `<b>`, which is correct: those are the characters the operator wrote.
 *
 * The result is real plain text, UNESCAPED. The agreed consumer is a Flutter
 * Text widget, which renders it verbatim; there is no HTML context left to
 * inject into, since step 1 already removed every tag.
 *
 * What step 1 guarantees is that NO MARKUP survives — not that the text
 * between two tags disappears. A stored `<script>alert(1)</script>` answers
 * the characters `alert(1)`: there is no tag left for anything to execute,
 * which is the whole point, but the body is text and text is kept. SPEC 79's
 * risk table words this as "sale como cadena vacía"; that wording is
 * imprecise, and tests/unit/TextToPlainTest.php pins the real behaviour.
 *
 * The whitespace pattern runs without the /u modifier on purpose: it matches
 * the no-break space by its two UTF-8 bytes, so invalid UTF-8 in the stored
 * value yields a mangled-but-present string instead of making preg_replace()
 * answer NULL.
 *
 * @param string $value
 *   The stored value, as Field API or the term object hands it over. NULL,
 *   arrays and any other non-string are accepted and answer "".
 *
 * @return string
 *   Plain text, never NULL. An empty or markup-only input answers "".
 */
function myapi_text_to_plain($value) {
  if (!is_string($value)) {
    return '';
  }

  $text = strip_tags($value);
  $text = decode_entities($text);
  $text = preg_replace('/(?:\xC2\xA0|\s)+/', ' ', $text);

  return trim($text);
}

/**
 * Folds a string for comparison: lower-cased and stripped of Spanish accents.
 *
 * Pure, and the whole reason ?search= is usable at all in this catalogue: the
 * categories are named "Plomería", "Jardinería", "Climatización" while every
 * `code` — and every keyboard a resident types on in a hurry — is unaccented.
 * Comparing the raw strings would mean "plomeria" finds nothing while
 * "Plomer" finds one thing, which reads as a broken search box, not as a
 * strict one.
 *
 * ONLY FOR COMPARING. The folded value is never answered: the response always
 * carries what the operator wrote, accents included. Nothing here is
 * sanitisation either — a folded string is not safe to print, it is only safe
 * to compare.
 *
 * The map covers the accented letters Spanish actually uses plus the ones a
 * borrowed word drags in (ä, â, ã, å, ë, ê, î, ï, ô, õ, û, ç). It is a plain
 * strtr() over UTF-8 byte sequences and not iconv()/transliteration: iconv
 * with //TRANSLIT depends on the C locale of the server and answers different
 * strings on different machines, and Drupal 7's transliteration module is not
 * a dependency of this module.
 *
 * Uppercase accented letters are mapped straight to their lowercase ASCII
 * letter, so the strtr() also does half the lower-casing; drupal_strtolower()
 * then handles the plain ASCII and whatever multibyte letters the map does not
 * know about.
 *
 * @param string $value
 *   Any stored or user-supplied string. NULL, arrays and any other non-string
 *   are accepted and answer "".
 *
 * @return string
 *   The folded string, never NULL.
 */
function myapi_text_fold($value) {
  if (!is_string($value)) {
    return '';
  }

  $map = [
    'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
    'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
    'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
    'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
    'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
    'ñ' => 'n', 'ç' => 'c',
    'Á' => 'a', 'À' => 'a', 'Ä' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Å' => 'a',
    'É' => 'e', 'È' => 'e', 'Ë' => 'e', 'Ê' => 'e',
    'Í' => 'i', 'Ì' => 'i', 'Ï' => 'i', 'Î' => 'i',
    'Ó' => 'o', 'Ò' => 'o', 'Ö' => 'o', 'Ô' => 'o', 'Õ' => 'o',
    'Ú' => 'u', 'Ù' => 'u', 'Ü' => 'u', 'Û' => 'u',
    'Ñ' => 'n', 'Ç' => 'c',
  ];

  return drupal_strtolower(strtr($value, $map));
}
