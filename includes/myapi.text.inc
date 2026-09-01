<?php

/**
 * @file
 * Plain-text flattening of stored rich text (SPEC 79) and search folding
 * (SPEC 119).
 *
 * Three helpers. myapi_text_to_plain() is shared by every resource that has to
 * send a ONE-LINE value to the Flutter app; myapi_text_to_multiline() is its
 * sister for the long fields a person writes with the Enter key, and the only
 * difference between the two is that this second one keeps the line breaks;
 * myapi_text_fold() is shared by every resource that has to compare a user's
 * search box against a stored string. They live in includes/ and not inside a
 * resource because the marketplace repeats the same problem in more than one
 * place (the provider description, the offer message, the rating comment, the
 * transaction comment), and rule 3 of CLAUDE.md says shared logic is written
 * once.
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
 * - Long text field edited with a rich editor (a term description): these
 *   helpers. check_plain() ESCAPES, so a description written in the editor
 *   would reach the app as the literal `&lt;p&gt;Texto&lt;/p&gt;` — exactly
 *   what must not be painted inside a Flutter Text widget. The destination is
 *   not HTML, so escaping protects nothing there and only dirties the data.
 *   Which of the two: myapi_text_to_multiline() when the person could have
 *   pressed Enter inside the field (a description, a message, a comment),
 *   myapi_text_to_plain() when they could not (a name, a code, a title) or
 *   when a '\n' would break the destination (a push banner).
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

/**
 * Flattens stored HTML into plain text KEEPING THE LINE BREAKS.
 *
 * The sister of myapi_text_to_plain(), and the difference between the two is
 * the only thing worth remembering about this file:
 *
 * - myapi_text_to_plain()      — one line out, always. For a value that IS one
 *                                line (a name, a code, a node title) or for a
 *                                place where a '\n' would break the layout (a
 *                                push banner, a search fold).
 * - myapi_text_to_multiline()  — the paragraphs survive. For the long fields a
 *                                person writes with the Enter key: the
 *                                description of a request, the message of an
 *                                offer, the comment of a rating or of a
 *                                transaction.
 *
 * Applying the first one to a description is what SPEC 79 refused to do, and
 * for a good reason: it collapses every '\n' into a space, so three paragraphs
 * arrive at the app as one endless line. Answering the value AS STORED was the
 * other option taken until now, and that is the one that shows `<p>` and
 * `&nbsp;` on screen the moment anything writes markup into the field — the
 * back-office node form with its rich editor, or a client that sends HTML on
 * an edit while it sends plain text on a create. This function is the third
 * answer, and the only one that is right for both: NO MARKUP OUT, AND THE
 * LINE BREAKS THE PERSON TYPED STILL THERE.
 *
 * Pure, like its sister: no database, no site state, and decode_entities() as
 * the only Drupal call.
 *
 * SIX STEPS, AND THE ORDER MATTERS AS MUCH AS IT DOES IN myapi_text_to_plain():
 *
 *   1. Normalise the line endings. A client on Windows sends "\r\n" and a very
 *      old one sends "\r"; everything downstream reasons about "\n" alone.
 *   2. Turn the markup that MEANS a line break into a real one — `<br>`, and
 *      the opening AND closing tag of every block element (p, div, li, tr,
 *      h1..h6, blockquote, pre). BEFORE strip_tags(), which is the whole
 *      point: afterwards the tag is gone and the break it stood for is lost,
 *      which is exactly how `<p>A</p><p>B</p>` used to come out as "A B".
 *   3. strip_tags() — every remaining tag, block or inline, goes away.
 *   4. decode_entities() — `&nbsp;` -> a no-break space, `&amp;` -> `&`. AFTER
 *      the stripping, for the reason myapi_text_to_plain() documents at
 *      length: a stored `&lt;script&gt;` is text the operator typed and has to
 *      survive as the literal characters `<script>`, not be decoded into a tag
 *      that step 3 would then delete.
 *   5. Collapse HORIZONTAL whitespace only — spaces, tabs and the no-break
 *      space step 4 leaves behind — into a single space. The '\n' is the one
 *      whitespace character this function protects, which is the whole
 *      difference with its sister.
 *   6. Tidy the vertical whitespace: no spaces hugging a line break, and never
 *      more than one blank line in a row, so the four empty paragraphs a rich
 *      editor emits between two sentences come out as one blank line. Then
 *      trim().
 *
 * The result is real plain text, UNESCAPED, with '\n' in it. The agreed
 * consumer is a Flutter Text widget, which renders it verbatim; there is no
 * HTML context left to inject into, since step 3 already removed every tag.
 *
 * Like its sister, this GUARANTEES NO MARKUP SURVIVES, not that the text
 * between two tags disappears: `<script>alert(1)</script>` answers the
 * characters `alert(1)`, with no tag left for anything to execute.
 *
 * The patterns run without the /u modifier on purpose, same as the sister:
 * they match the no-break space by its two UTF-8 bytes, so invalid UTF-8 in
 * the stored value yields a mangled-but-present string instead of making
 * preg_replace() answer NULL.
 *
 * @param string $value
 *   The stored or incoming value. NULL, arrays and any other non-string are
 *   accepted and answer "".
 *
 * @return string
 *   Plain text with its line breaks, never NULL. An empty or markup-only
 *   input answers "".
 */
function myapi_text_to_multiline($value) {
  if (!is_string($value)) {
    return '';
  }

  // 1. One line ending, and only one, from here on.
  $text = str_replace(["\r\n", "\r"], "\n", $value);

  // 2. The markup that means "new line", turned into one before it is deleted.
  $text = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $text);
  $text = preg_replace('#</?\s*(?:p|div|li|ul|ol|tr|table|h[1-6]|blockquote|pre|section|article)\b[^>]*>#i', "\n", $text);

  // 3 and 4. Same two steps, in the same order, as myapi_text_to_plain().
  $text = strip_tags($text);
  $text = decode_entities($text);

  // 5. Horizontal whitespace only: [^\S\n] is "whitespace that is not a line
  // break", and the \xC2\xA0 alternative catches the no-break space step 4
  // leaves behind from every &nbsp;.
  $text = preg_replace('/(?:\xC2\xA0|[^\S\n])+/', ' ', $text);

  // 6. No space hugging a break, and at most one blank line in a row.
  $text = preg_replace('/ *\n */', "\n", $text);
  $text = preg_replace('/\n{3,}/', "\n\n", $text);

  return trim($text);
}
