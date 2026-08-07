<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../resources/bank.resource.inc';

/**
 * Unit tests for myapi_bank_build_item() (SPEC 18, covered by SPEC 76).
 *
 * The one pure function of the banks resource: it turns a taxonomy term object
 * into the three keys the Flutter app reads. Everything the endpoint promises
 * about the SHAPE of an item is decided here — that `id` is an int and never
 * NULL, that both texts go through check_plain(), that a term with no
 * description answers "" and not null, and that nothing else of the term
 * leaks out (a term object carries vid, weight, depth, parents and the whole
 * Field API alongside the three fields the API exposes).
 *
 * The sibling class BankEndpointTest runs the same function through the real
 * dispatcher; this one calls it directly, so a failure here says "the mapping
 * is wrong" and not "the endpoint is wrong".
 */
class BankBuildItemTest extends TestCase {

  /**
   * A term object the way taxonomy_get_tree() answers it.
   */
  private function term(array $values = []) {
    return (object) ($values + [
      'tid'         => '3',
      'name'        => 'Banco Pichincha',
      'description' => 'Cuenta corriente 2100xxxxxx',
    ]);
  }

  /* -------------------------------------------------------------------------
   * The documented shape.
   * ---------------------------------------------------------------------- */

  /**
   * Exactly the three documented keys, in the documented order — the order is
   * asserted too because it is the order json_encode() prints, and docs/bank.md
   * shows it that way.
   */
  public function testReturnsExactlyTheThreeDocumentedKeysInOrder() {
    $item = myapi_bank_build_item($this->term());

    $this->assertSame(['id', 'name', 'description'], array_keys($item));
  }

  /**
   * The whole item, compared with types: this is the contract the app codes
   * against.
   */
  public function testMapsATermWhole() {
    $item = myapi_bank_build_item($this->term());

    $this->assertSame([
      'id'          => 3,
      'name'        => 'Banco Pichincha',
      'description' => 'Cuenta corriente 2100xxxxxx',
    ], $item);
  }

  /**
   * Nothing else of the term travels: a real term object carries vid, weight,
   * depth, parents, format, language and any attached field, and the endpoint
   * exposes three keys (SPEC 18, "Fuera de este spec").
   */
  public function testNoOtherTermPropertyIsExposed() {
    $item = myapi_bank_build_item($this->term([
      'vid'      => 4,
      'weight'   => -5,
      'depth'    => 0,
      'parents'  => [0],
      'format'   => 'filtered_html',
      'language' => 'es',
      'field_account_number' => ['es' => [['value' => '2100']]],
    ]));

    $this->assertSame(['id', 'name', 'description'], array_keys($item));
  }

  /**
   * The mapper does not touch the term it receives (it is the same object the
   * caller keeps in the tree).
   */
  public function testDoesNotMutateTheTerm() {
    $term = $this->term(['tid' => '3']);

    myapi_bank_build_item($term);

    $this->assertSame('3', $term->tid);
    $this->assertSame('Banco Pichincha', $term->name);
  }

  /* -------------------------------------------------------------------------
   * `id`: the (int) cast.
   * ---------------------------------------------------------------------- */

  /**
   * The database answers tid as a string; the API promises an int. Without the
   * cast the app receives "3" and every `id == 3` comparison in Dart fails.
   */
  public function testIdIsCastFromTheStringTheDatabaseAnswers() {
    $item = myapi_bank_build_item($this->term(['tid' => '3']));

    $this->assertSame(3, $item['id']);
  }

  /**
   * An int tid stays an int (taxonomy_get_tree() answers ints on some
   * drivers).
   */
  public function testIdAlreadyAnIntIsUnchanged() {
    $item = myapi_bank_build_item($this->term(['tid' => 3]));

    $this->assertSame(3, $item['id']);
  }

  /**
   * Leading zeros are dropped by the cast, not carried into the JSON.
   */
  public function testIdDropsLeadingZeros() {
    $item = myapi_bank_build_item($this->term(['tid' => '007']));

    $this->assertSame(7, $item['id']);
  }

  /**
   * A large tid keeps its value (no truncation at four digits, which is where
   * a real vocabulary lands after a few years).
   */
  public function testIdKeepsLargeValues() {
    $item = myapi_bank_build_item($this->term(['tid' => '1048576']));

    $this->assertSame(1048576, $item['id']);
  }

  /**
   * The promise SPEC 18 makes in its own words — "nunca NULL": a term with a
   * NULL tid answers 0, not null, so the app never has to null-check `id`.
   * Pinned as behaviour, not endorsed as data: a term without a tid does not
   * exist in Drupal.
   */
  public function testIdIsNeverNull() {
    $item = myapi_bank_build_item($this->term(['tid' => NULL]));

    $this->assertSame(0, $item['id']);
    $this->assertNotNull($item['id']);
  }

  /* -------------------------------------------------------------------------
   * `name` and `description`: check_plain().
   * ---------------------------------------------------------------------- */

  /**
   * Markup stored in the term name comes back escaped, never as live markup.
   * The vocabulary is edited from Drupal's admin UI on an EOL core, so the
   * term text is not trusted input.
   */
  public function testNameIsEscaped() {
    $item = myapi_bank_build_item($this->term(['name' => '<script>alert(1)</script>']));

    $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $item['name']);
  }

  /**
   * Same for the description, which is the field SPEC 18 was written around.
   */
  public function testDescriptionIsEscaped() {
    $item = myapi_bank_build_item($this->term(['description' => '<b>Cta.</b> 2100']));

    $this->assertSame('&lt;b&gt;Cta.&lt;/b&gt; 2100', $item['description']);
  }

  /**
   * Both quote styles are escaped: check_plain() is htmlspecialchars() with
   * ENT_QUOTES, so a single quote becomes &#039; and not a literal `'`. It
   * matters because the app renders these strings into HTML attributes.
   */
  public function testBothQuoteStylesAreEscaped() {
    $item = myapi_bank_build_item($this->term([
      'name'        => "Banco 'Del Pacífico'",
      'description' => 'Alias "ahorros"',
    ]));

    $this->assertSame('Banco &#039;Del Pacífico&#039;', $item['name']);
    $this->assertSame('Alias &quot;ahorros&quot;', $item['description']);
  }

  /**
   * An ampersand is escaped, and text that was ALREADY escaped in the term is
   * escaped a second time (`&amp;` -> `&amp;amp;`). Pinned rather than fixed:
   * check_plain() has no double_encode=FALSE, and an editor who pastes escaped
   * HTML into the term sees it doubly escaped in the app. The alternative
   * (decode_entities() first) would undo the escaping this function exists for.
   */
  public function testAmpersandIsEscapedAndAlreadyEscapedTextIsEscapedAgain() {
    $item = myapi_bank_build_item($this->term([
      'name'        => 'Pacífico & Guayaquil',
      'description' => 'Cta. &amp; ahorros',
    ]));

    $this->assertSame('Pacífico &amp; Guayaquil', $item['name']);
    $this->assertSame('Cta. &amp;amp; ahorros', $item['description']);
  }

  /**
   * UTF-8 letters are NOT entity-encoded: "Pichincha Ñ á" travels as itself.
   * check_plain() only touches the five HTML-special characters, and the app
   * shows bank names with accents.
   */
  public function testAccentedTextTravelsUnchanged() {
    $item = myapi_bank_build_item($this->term([
      'name'        => 'Banco Ñandú áéíóú',
      'description' => 'Sucursal Quitumbe — piso 2°',
    ]));

    $this->assertSame('Banco Ñandú áéíóú', $item['name']);
    $this->assertSame('Sucursal Quitumbe — piso 2°', $item['description']);
  }

  /**
   * Invalid UTF-8 in the term answers "" rather than a mangled string:
   * htmlspecialchars() rejects the whole input. Worth pinning because the
   * failure is silent — the bank simply loses its name in the app instead of
   * erroring — and because it is the one input that makes `name` empty.
   */
  public function testInvalidUtf8YieldsAnEmptyString() {
    $item = myapi_bank_build_item($this->term(['name' => "Banco \xC3\x28"]));

    $this->assertSame('', $item['name']);
  }

  /**
   * Surrounding whitespace and newlines are preserved: the mapper sanitizes,
   * it does not clean up editorial data.
   */
  public function testWhitespaceAndNewlinesArePreserved() {
    $item = myapi_bank_build_item($this->term([
      'name'        => '  Produbanco  ',
      'description' => "Cta. 1234\nSucursal Norte",
    ]));

    $this->assertSame('  Produbanco  ', $item['name']);
    $this->assertSame("Cta. 1234\nSucursal Norte", $item['description']);
  }

  /* -------------------------------------------------------------------------
   * The empty description (SPEC 18's explicit decision).
   * ---------------------------------------------------------------------- */

  /**
   * An empty description answers "" — a string, so the app never null-checks
   * it (SPEC 18, "Descripción vacía").
   */
  public function testEmptyDescriptionYieldsAnEmptyString() {
    $item = myapi_bank_build_item($this->term(['description' => '']));

    $this->assertSame('', $item['description']);
    $this->assertNotNull($item['description']);
  }

  /**
   * A NULL description — what the database answers for a term saved with the
   * field blank — answers "" as well, through check_plain() alone and with no
   * branch in the resource.
   *
   * The deprecation handler is about the TEST RUNTIME, not about the code:
   * production runs PHP 7.4, where htmlspecialchars(NULL) is silent; PHP 8.1+
   * deprecates passing NULL to a string parameter. Silencing it here keeps the
   * case honest on both versions instead of dropping the only assertion that
   * proves a bank with no description is not `null` in the JSON.
   */
  public function testNullDescriptionYieldsAnEmptyString() {
    set_error_handler(function () {
      return TRUE;
    }, E_DEPRECATED);

    try {
      $item = myapi_bank_build_item($this->term(['description' => NULL]));
    }
    finally {
      restore_error_handler();
    }

    $this->assertSame('', $item['description']);
    $this->assertNotNull($item['description']);
  }

  /**
   * A description of "0" survives: it is falsy in PHP, and any `empty()`-based
   * shortcut in a future rewrite would turn it into "".
   */
  public function testDescriptionOfZeroIsKept() {
    $item = myapi_bank_build_item($this->term(['description' => '0']));

    $this->assertSame('0', $item['description']);
  }

}
