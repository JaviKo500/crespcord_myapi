<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.text.inc';
require_once __DIR__ . '/../../resources/service_category.resource.inc';

/**
 * Unit tests for myapi_service_category_build_item() (SPEC 79).
 *
 * The pure function of the service categories resource: it turns a hydrated
 * taxonomy term into the six keys the Flutter app reads. Everything SPEC 79
 * promises about the SHAPE of an item is decided here — the six keys and their
 * order, that `id` is an int and never NULL, that a term with an empty
 * field_category_code is KEPT with code "", that `description` is flattened to
 * plain text instead of escaped, and that `icon_id` and `icon_url` are both
 * filled or both NULL, never one without the other.
 *
 * The sibling class ServiceCategoryEndpointTest runs the same function through
 * the real dispatcher; this one calls it directly, so a failure here says "the
 * mapping is wrong" and not "the endpoint is wrong".
 */
class ServiceCategoryBuildItemTest extends TestCase {

  /**
   * A hydrated term object, the way entity_load() answers it.
   */
  private function term(array $values = []) {
    return (object) ($values + [
      'tid'                 => '3',
      'name'                => 'Plomería',
      'description'         => '<p>Instalación y reparación de tuberías.</p>',
      'field_category_code' => [LANGUAGE_NONE => [['value' => 'plumbing']]],
      'field_category_icon' => [LANGUAGE_NONE => [[
        'fid' => '42',
        'uri' => 'public://category-icons/plumbing.png',
      ]]],
    ]);
  }

  /* -------------------------------------------------------------------------
   * The documented shape.
   * ---------------------------------------------------------------------- */

  /**
   * Exactly the six documented keys, in the documented order — the order is
   * asserted because it is the one json_encode() prints and the one
   * docs/service-category.md shows.
   */
  public function testReturnsExactlyTheSixDocumentedKeysInOrder() {
    $item = myapi_service_category_build_item($this->term());

    $this->assertSame(
      ['id', 'code', 'name', 'description', 'icon_id', 'icon_url'],
      array_keys($item)
    );
  }

  /**
   * The whole item, compared with types: the contract the app codes against.
   */
  public function testMapsATermWhole() {
    $item = myapi_service_category_build_item($this->term());

    $this->assertSame([
      'id'          => 3,
      'code'        => 'plumbing',
      'name'        => 'Plomería',
      'description' => 'Instalación y reparación de tuberías.',
      'icon_id'     => 42,
      'icon_url'    => 'https://crespcord.example.com/sites/default/files/category-icons/plumbing.png',
    ], $item);
  }

  /**
   * Nothing else of the term travels: a real hydrated term carries vid,
   * weight, depth, parents, format, language and every other attached field,
   * and the endpoint exposes six keys.
   */
  public function testNoOtherTermPropertyIsExposed() {
    $item = myapi_service_category_build_item($this->term([
      'vid'                    => 4,
      'weight'                 => -5,
      'depth'                  => 0,
      'parents'                => [0],
      'format'                 => 'filtered_html',
      'language'               => 'es',
      'field_internal_note'    => [LANGUAGE_NONE => [['value' => 'no exponer']]],
    ]));

    $this->assertSame(
      ['id', 'code', 'name', 'description', 'icon_id', 'icon_url'],
      array_keys($item)
    );
    $this->assertStringNotContainsString('no exponer', json_encode($item));
  }

  /**
   * The mapper does not touch the term it receives (it is the same object the
   * caller keeps in the loaded set).
   */
  public function testDoesNotMutateTheTerm() {
    $term = $this->term();

    myapi_service_category_build_item($term);

    $this->assertSame('3', $term->tid);
    $this->assertSame('<p>Instalación y reparación de tuberías.</p>', $term->description);
    $this->assertSame('42', $term->field_category_icon[LANGUAGE_NONE][0]['fid']);
  }

  /* -------------------------------------------------------------------------
   * `id`: the (int) cast.
   * ---------------------------------------------------------------------- */

  /**
   * The database answers tid as a string; the API promises an int. Without the
   * cast the app receives "3" and every `id == 3` comparison in Dart fails.
   */
  public function testIdIsCastFromTheStringTheDatabaseAnswers() {
    $item = myapi_service_category_build_item($this->term(['tid' => '3']));

    $this->assertSame(3, $item['id']);
  }

  /**
   * An int tid stays an int, and a large one keeps its value.
   */
  public function testIdAlreadyAnIntAndLargeIdsAreKept() {
    $this->assertSame(3, myapi_service_category_build_item($this->term(['tid' => 3]))['id']);
    $this->assertSame(1048576, myapi_service_category_build_item($this->term(['tid' => '1048576']))['id']);
  }

  /**
   * SPEC 79's promise in its own words — `id` is never NULL: a term with a
   * NULL tid answers 0. Pinned as behaviour, not endorsed as data.
   */
  public function testIdIsNeverNull() {
    $item = myapi_service_category_build_item($this->term(['tid' => NULL]));

    $this->assertSame(0, $item['id']);
    $this->assertNotNull($item['id']);
  }

  /* -------------------------------------------------------------------------
   * `code`: kept even when empty (the decision that parts ways with
   * payment-methods).
   * ---------------------------------------------------------------------- */

  /**
   * The value of field_category_code travels as `code`, escaped.
   */
  public function testCodeComesFromTheFieldValue() {
    $item = myapi_service_category_build_item($this->term([
      'field_category_code' => [LANGUAGE_NONE => [['value' => 'gardening']]],
    ]));

    $this->assertSame('gardening', $item['code']);
  }

  /**
   * A term whose field is missing altogether answers code "" and is STILL
   * returned as an item — the mapper never answers NULL, unlike
   * myapi_payment_method_build_item(). Hiding the term would make a category
   * disappear from the marketplace with no trace.
   */
  public function testTermWithNoCodeFieldIsKeptWithAnEmptyCode() {
    $term = $this->term();
    unset($term->field_category_code);

    $item = myapi_service_category_build_item($term);

    $this->assertIsArray($item);
    $this->assertSame('', $item['code']);
    $this->assertSame(3, $item['id']);
  }

  /**
   * Same for the field present but empty, in the three shapes Field API and
   * the database produce for "the operator left it blank".
   */
  public function testEmptyCodeValuesAnswerAnEmptyStringAndKeepTheTerm() {
    $shapes = [
      'empty field'        => [],
      'empty language'     => [LANGUAGE_NONE => []],
      'empty string value' => [LANGUAGE_NONE => [['value' => '']]],
    ];

    foreach ($shapes as $label => $value) {
      $item = myapi_service_category_build_item($this->term(['field_category_code' => $value]));

      $this->assertSame('', $item['code'], $label);
      $this->assertSame(3, $item['id'], $label);
    }
  }

  /**
   * A code of "0" survives: it is falsy in PHP, and an empty()-based shortcut
   * would turn it into "".
   */
  public function testCodeOfZeroIsKept() {
    $item = myapi_service_category_build_item($this->term([
      'field_category_code' => [LANGUAGE_NONE => [['value' => '0']]],
    ]));

    $this->assertSame('0', $item['code']);
  }

  /**
   * `code` goes through check_plain(), like every other single-line value of
   * the module: it is not passed through the plain-text helper, so markup in
   * it comes back escaped and cannot be mistaken for a code.
   */
  public function testCodeIsEscaped() {
    $item = myapi_service_category_build_item($this->term([
      'field_category_code' => [LANGUAGE_NONE => [['value' => '<b>plumbing</b>']]],
    ]));

    $this->assertSame('&lt;b&gt;plumbing&lt;/b&gt;', $item['code']);
  }

  /* -------------------------------------------------------------------------
   * `name`: check_plain(), like banks and payment-methods.
   * ---------------------------------------------------------------------- */

  /**
   * Markup stored in the term name comes back escaped, never as live markup.
   */
  public function testNameIsEscaped() {
    $item = myapi_service_category_build_item($this->term(['name' => '<script>alert(1)</script>']));

    $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $item['name']);
  }

  /**
   * Accented text travels as itself: check_plain() only touches the five
   * HTML-special characters, and every category name has accents.
   */
  public function testAccentedNameTravelsUnchanged() {
    $item = myapi_service_category_build_item($this->term(['name' => 'Jardinería y áreas verdes']));

    $this->assertSame('Jardinería y áreas verdes', $item['name']);
  }

  /* -------------------------------------------------------------------------
   * `description`: myapi_text_to_plain(), NOT check_plain().
   *
   * The divergence with banks/payment-methods, and the reason SPEC 79 exists
   * in this shape. The helper has its own suite (TextToPlainTest); what is
   * pinned here is that the mapper calls IT and not check_plain().
   * ---------------------------------------------------------------------- */

  /**
   * The description of a term written in the rich editor comes back as plain
   * text: no tags, no entities, no leftover whitespace — the acceptance
   * criterion of SPEC 79, word for word.
   */
  public function testDescriptionIsFlattenedToPlainText() {
    $item = myapi_service_category_build_item($this->term([
      'description' => '<p>Hola <strong>mundo</strong></p>&nbsp;',
    ]));

    $this->assertSame('Hola mundo', $item['description']);
  }

  /**
   * And it is NOT escaped: an ampersand travels as `&`, where
   * /api/v1/banks would answer `&amp;`. This single assertion is the whole
   * contract difference between the two endpoints.
   */
  public function testDescriptionIsNotEscaped() {
    $item = myapi_service_category_build_item($this->term([
      'description' => '<p>Plomería &amp; gasfitería</p>',
    ]));

    $this->assertSame('Plomería & gasfitería', $item['description']);
    $this->assertStringNotContainsString('&amp;', $item['description']);
  }

  /**
   * A stored `&lt;b&gt;` is text the operator typed and comes back as the
   * literal `<b>`: it does not disappear. The order inside the helper is what
   * guarantees this, and the mapper inherits it.
   */
  public function testEntityEncodedMarkupInTheDescriptionSurvivesAsText() {
    $item = myapi_service_category_build_item($this->term([
      'description' => 'Usa la etiqueta &lt;b&gt; para negrita',
    ]));

    $this->assertSame('Usa la etiqueta <b> para negrita', $item['description']);
  }

  /**
   * An empty description answers "" — a string, so the app never null-checks
   * it.
   */
  public function testEmptyDescriptionYieldsAnEmptyString() {
    $item = myapi_service_category_build_item($this->term(['description' => '']));

    $this->assertSame('', $item['description']);
    $this->assertNotNull($item['description']);
  }

  /**
   * A NULL description — what the database answers for a term saved with the
   * field blank — answers "" too, with no notice and no deprecation on any PHP
   * version, because the helper guards on is_string() before touching it.
   */
  public function testNullDescriptionYieldsAnEmptyString() {
    $item = myapi_service_category_build_item($this->term(['description' => NULL]));

    $this->assertSame('', $item['description']);
    $this->assertNotNull($item['description']);
  }

  /* -------------------------------------------------------------------------
   * The icon: both keys or neither.
   * ---------------------------------------------------------------------- */

  /**
   * A term with an icon answers the fid as an int and the absolute URL built
   * from the uri. The fid is cast for the same reason `id` is: the database
   * answers a string.
   */
  public function testIconIsMappedFromFidAndUri() {
    $item = myapi_service_category_build_item($this->term([
      'field_category_icon' => [LANGUAGE_NONE => [[
        'fid'      => '42',
        'uri'      => 'public://category-icons/plumbing.png',
        'filename' => 'plumbing.png',
        'filemime' => 'image/png',
        'filesize' => '10240',
        'width'    => '256',
        'height'   => '256',
        'alt'      => 'Plomería',
        'title'    => '',
      ]]],
    ]));

    $this->assertSame(42, $item['icon_id']);
    $this->assertSame(
      'https://crespcord.example.com/sites/default/files/category-icons/plumbing.png',
      $item['icon_url']
    );
  }

  /**
   * The rest of the image value does not leak: filename, filemime, filesize,
   * width, height, alt and title stay out of the response.
   */
  public function testTheRestOfTheImageValueIsNotExposed() {
    $item = myapi_service_category_build_item($this->term([
      'field_category_icon' => [LANGUAGE_NONE => [[
        'fid'      => '42',
        'uri'      => 'public://category-icons/plumbing.png',
        'filename' => 'plumbing.png',
        'alt'      => 'texto alternativo',
      ]]],
    ]));

    $json = json_encode($item);
    $this->assertStringNotContainsString('filename', $json);
    $this->assertStringNotContainsString('texto alternativo', $json);
  }

  /**
   * A term with no icon answers BOTH keys as NULL — never a missing key, so
   * the app always finds them.
   */
  public function testTermWithoutIconAnswersBothKeysAsNull() {
    $term = $this->term();
    unset($term->field_category_icon);

    $item = myapi_service_category_build_item($term);

    $this->assertArrayHasKey('icon_id', $item);
    $this->assertArrayHasKey('icon_url', $item);
    $this->assertNull($item['icon_id']);
    $this->assertNull($item['icon_url']);
  }

  /**
   * The empty shapes of an image field answer the same pair of NULLs.
   */
  public function testEmptyIconValuesAnswerBothKeysAsNull() {
    $shapes = [
      'empty field'    => [],
      'empty language' => [LANGUAGE_NONE => []],
    ];

    foreach ($shapes as $label => $value) {
      $item = myapi_service_category_build_item($this->term(['field_category_icon' => $value]));

      $this->assertNull($item['icon_id'], $label);
      $this->assertNull($item['icon_url'], $label);
    }
  }

  /**
   * The promise SPEC 79 states as a rule: never one key filled and the other
   * NULL. A half-written value — a fid with no uri, a uri with no fid, an
   * empty uri, a fid of 0 — is treated as NO icon, not as half an icon, so the
   * app's `icon_url != null` check is enough.
   */
  public function testAHalfWrittenIconValueIsTreatedAsNoIcon() {
    $shapes = [
      'fid without uri'  => ['fid' => '42'],
      'uri without fid'  => ['uri' => 'public://category-icons/plumbing.png'],
      'empty uri'        => ['fid' => '42', 'uri' => ''],
      'fid of zero'      => ['fid' => '0', 'uri' => 'public://category-icons/plumbing.png'],
      'null fid'         => ['fid' => NULL, 'uri' => 'public://category-icons/plumbing.png'],
      'not an array'     => 'public://category-icons/plumbing.png',
    ];

    foreach ($shapes as $label => $value) {
      $item = myapi_service_category_build_item($this->term([
        'field_category_icon' => [LANGUAGE_NONE => [$value]],
      ]));

      $this->assertNull($item['icon_id'], $label);
      $this->assertNull($item['icon_url'], $label);
    }
  }

  /* -------------------------------------------------------------------------
   * `providers_count`: the optional seventh key.
   * ---------------------------------------------------------------------- */

  /**
   * With no second argument — the request did not ask for counts — the item is
   * the six-key one and `providers_count` is ABSENT, not null.
   */
  public function testWithoutACountTheKeyIsAbsent() {
    $item = myapi_service_category_build_item($this->term());

    $this->assertArrayNotHasKey('providers_count', $item);
    $this->assertCount(6, $item);
  }

  /**
   * Passing NULL explicitly is the same as not passing anything: NULL means
   * "not asked for", it is never a value that travels.
   */
  public function testAnExplicitNullCountIsTheSameAsNoCount() {
    $item = myapi_service_category_build_item($this->term(), NULL);

    $this->assertArrayNotHasKey('providers_count', $item);
    $this->assertStringNotContainsString('providers_count', json_encode($item));
  }

  /**
   * With a count, the key is appended LAST: the six documented keys keep their
   * order, so an app reading the JSON positionally is unaffected.
   */
  public function testWithACountTheKeyIsAppendedLast() {
    $item = myapi_service_category_build_item($this->term(), 3);

    $this->assertSame(
      ['id', 'code', 'name', 'description', 'icon_id', 'icon_url', 'providers_count'],
      array_keys($item)
    );
    $this->assertSame(3, $item['providers_count']);
  }

  /**
   * A count of 0 IS a value and does travel: "this category has no provider
   * yet" is information the app shows. The key must not be dropped by a
   * falsy check.
   */
  public function testACountOfZeroTravels() {
    $item = myapi_service_category_build_item($this->term(), 0);

    $this->assertArrayHasKey('providers_count', $item);
    $this->assertSame(0, $item['providers_count']);
  }

  /**
   * The count is cast to int: COUNT() answers a string on most drivers, and
   * the app compares it as a number.
   */
  public function testTheCountIsCastToAnInteger() {
    $this->assertSame(7, myapi_service_category_build_item($this->term(), '7')['providers_count']);
    $this->assertSame(0, myapi_service_category_build_item($this->term(), '0')['providers_count']);
  }

  /**
   * The count does not disturb the rest of the item: the other six keys answer
   * the same values with and without it.
   */
  public function testTheCountDoesNotChangeTheOtherKeys() {
    $without = myapi_service_category_build_item($this->term());
    $with = myapi_service_category_build_item($this->term(), 3);

    unset($with['providers_count']);
    $this->assertSame($without, $with);
  }

  /**
   * Only the first delta is read: the field is single-valued (SPEC 77), and a
   * second value — left behind by a cardinality change — does not travel.
   */
  public function testOnlyTheFirstIconDeltaIsRead() {
    $item = myapi_service_category_build_item($this->term([
      'field_category_icon' => [LANGUAGE_NONE => [
        ['fid' => '42', 'uri' => 'public://category-icons/plumbing.png'],
        ['fid' => '99', 'uri' => 'public://category-icons/otro.png'],
      ]],
    ]));

    $this->assertSame(42, $item['icon_id']);
    $this->assertStringNotContainsString('otro.png', $item['icon_url']);
  }

}
