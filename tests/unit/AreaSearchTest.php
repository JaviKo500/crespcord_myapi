<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.text.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_query.inc';
require_once __DIR__ . '/../../resources/area.resource.inc';

/**
 * Unit tests for the ?search filter of GET /api/v1/condominiums/%/areas.
 *
 * The filter has two halves that are matched by two different mechanisms, and
 * the tests are split the same way:
 *
 *   - the CATEGORY half is pure PHP over the field's allowed_values
 *     (myapi_area_category_keys_matching()) — a label the user typed resolved
 *     to the machine keys the nodes actually store;
 *   - the NAME half is a SQL LIKE, exercised here through myapi_area_fetch()
 *     over fixture rows, together with the OR that joins the two halves and
 *     with myapi_area_count(), which must narrow by exactly the same rule or
 *     the pagination would advertise pages the client cannot reach.
 *
 * The fixture rows are FLAT — one row per area carrying the columns the joins
 * would have produced — because the query stub records joins instead of
 * resolving them (see MyapiTestSelectQuery). 'fsta.field_area_status_value' is
 * written qualified on purpose: its alias in the projection is 'status', which
 * collides with node.status, and only the qualified key can hold both.
 *
 * What is NOT proven here is the collation. In production the accent- and
 * case-insensitivity of the name half is MySQL's (utf8_general_ci), and the
 * stub stands in for it by folding both sides of the LIKE; a site running an
 * accent-sensitive collation would still find "Jardín" typed with its accent,
 * but not without it.
 */
class AreaSearchTest extends TestCase {

  /**
   * The condominium every fixture area belongs to, unless it is the intruder.
   */
  const CONDOMINIUM = 7;

  /**
   * A readable slice of the real field_area_category catalogue.
   *
   * Written out rather than read from _myapi_area_category_allowed_values(),
   * for the usual reason: an expectation derived from the code under test
   * proves nothing. What it must keep from the real one is the shape that the
   * matching depends on — Spanish labels, accents, a shared word across two
   * keys ("Piscina"), and a key whose English spelling is nowhere in its label.
   */
  private static function catalogue() {
    return [
      'gym'         => 'Gimnasio',
      'pool'        => 'Piscina',
      'kids_pool'   => 'Piscina infantil',
      'green_area'  => 'Zona verde / Jardín',
      'party_hall'  => 'Salón de eventos',
      'other'       => 'Otra',
    ];
  }

  protected function setUp(): void {
    myapi_test_db_seed();
    $GLOBALS['myapi_test_fields']['field_area_category'] = [
      'field_name' => 'field_area_category',
      'type'       => 'list_text',
      'settings'   => ['allowed_values' => self::catalogue()],
    ];
  }

  protected function tearDown(): void {
    unset($GLOBALS['myapi_test_fields']['field_area_category']);
    myapi_test_db_seed();
  }

  // ---------------------------------------------------------------------------
  // The category half: a typed label resolved to stored keys.
  // ---------------------------------------------------------------------------

  public function testCategoryNeedleMatchesEveryLabelContainingIt() {
    $this->assertSame(
      ['pool', 'kids_pool'],
      myapi_area_category_keys_matching('piscina'),
      'a substring shared by two labels answers both keys'
    );
  }

  public function testCategoryMatchIgnoresCaseAndAccents() {
    $this->assertSame(['green_area'], myapi_area_category_keys_matching('JARDIN'));
    $this->assertSame(['green_area'], myapi_area_category_keys_matching('jardín'));
    $this->assertSame(['party_hall'], myapi_area_category_keys_matching('salon'));
  }

  public function testCategoryMatchIsASubstringAndNotAPrefix() {
    $this->assertSame(['gym'], myapi_area_category_keys_matching('nasio'));
  }

  /**
   * The machine key is deliberately NOT searched (same call as SPEC 119).
   *
   * 'pool' is the value stored on the node and the value the app keys its icon
   * on; it is not something a resident types into a search box, and matching it
   * would make the needle "other" answer every area classified as 'Otra'.
   */
  public function testMachineKeysAreNotSearched() {
    $this->assertSame([], myapi_area_category_keys_matching('pool'));
    $this->assertSame([], myapi_area_category_keys_matching('green_area'));
  }

  public function testNoLabelMatchesAnswersNoKeys() {
    $this->assertSame([], myapi_area_category_keys_matching('helipuerto'));
  }

  public function testBlankNeedleAnswersNoKeys() {
    $this->assertSame([], myapi_area_category_keys_matching(''));
    $this->assertSame([], myapi_area_category_keys_matching('   '));
  }

  /**
   * A site whose field is missing must degrade to "no category matched", not
   * fatal — the caller then searches the name alone.
   */
  public function testMissingFieldAnswersNoKeys() {
    unset($GLOBALS['myapi_test_fields']['field_area_category']);

    $this->assertSame([], myapi_area_category_keys_matching('piscina'));
  }

  // ---------------------------------------------------------------------------
  // The whole filter, through the query.
  // ---------------------------------------------------------------------------

  public function testNoSearchAnswersEveryVisibleArea() {
    $this->seedAreas();

    $this->assertSame(
      ['Cancha techada', 'Gimnasio', 'Piscina principal', 'Zona húmeda'],
      $this->names(''),
      'an empty needle is not a filter'
    );
  }

  public function testSearchMatchesTheAreaName() {
    $this->seedAreas();

    $this->assertSame(['Cancha techada'], $this->names('cancha'));
  }

  public function testSearchMatchesTheNameIgnoringCaseAndAccents() {
    $this->seedAreas();

    $this->assertSame(['Zona húmeda'], $this->names('HUMEDA'));
  }

  /**
   * The OR that is the whole point: one needle, either field.
   *
   * "piscina" is in the NAME of one area and in the CATEGORY LABEL of another
   * ('kids_pool' on "Zona húmeda", whose title says nothing about a pool), and
   * both come back from a single request.
   */
  public function testSearchMatchesNameOrCategory() {
    $this->seedAreas();

    $this->assertSame(['Piscina principal', 'Zona húmeda'], $this->names('piscina'));
  }

  public function testSearchByCategoryAloneFindsAnAreaWhoseNameSaysNothing() {
    $this->seedAreas();

    $this->assertSame(['Gimnasio'], $this->names('gimna'));
  }

  public function testAnAreaWithNoCategoryIsStillFoundByItsName() {
    $this->seedAreas();

    $this->assertSame(['Cancha techada'], $this->names('techada'));
  }

  public function testNothingMatchesAnswersAnEmptyList() {
    $this->seedAreas();

    $this->assertSame([], $this->names('helipuerto'));
  }

  /**
   * db_like() escaping, which is the difference between a literal needle and a
   * match-everything wildcard.
   */
  public function testWildcardsTypedByTheUserAreLiteral() {
    myapi_test_db_seed(['node' => [
      $this->areaRow(1, 'Aforo 100% cubierto'),
      $this->areaRow(2, 'Aforo 50 personas'),
    ]]);

    $this->assertSame(['Aforo 100% cubierto'], $this->names('100%'), '% is escaped, not a wildcard');
    $this->assertSame([], $this->names('%%%'), 'a needle of wildcards matches nothing on its own');
  }

  public function testSearchNeverCrossesTheCondominiumOrTheVisibilityRule() {
    myapi_test_db_seed(['node' => [
      $this->areaRow(1, 'Piscina principal', 'pool'),
      $this->areaRow(2, 'Piscina del vecino', 'pool', ['condominium' => 99]),
      $this->areaRow(3, 'Piscina en obra', 'pool', ['area_status' => 'closed']),
      $this->areaRow(4, 'Piscina borrador', 'pool', ['status' => '0']),
    ]]);

    $this->assertSame(['Piscina principal'], $this->names('piscina'));
  }

  /**
   * The count has to narrow by the same rule as the page.
   *
   * It is a SEPARATE query with a separate set of joins — the category one is
   * added on demand there — so "the filter reached both" is not something the
   * code makes obvious, and a drift would show up as a client paging into
   * empty results.
   */
  public function testCountNarrowsWithTheSameFilterAsThePage() {
    $this->seedAreas();

    $this->assertSame(4, myapi_area_count(self::CONDOMINIUM), 'unfiltered');
    $this->assertSame(2, myapi_area_count(self::CONDOMINIUM, 'piscina'), 'name OR category');
    $this->assertSame(1, myapi_area_count(self::CONDOMINIUM, 'cancha'), 'name only');
    $this->assertSame(0, myapi_area_count(self::CONDOMINIUM, 'helipuerto'));
  }

  public function testTheSearchedSetIsStillPaginated() {
    $this->seedAreas();

    $this->assertSame(
      ['Piscina principal'],
      array_column(myapi_area_fetch(self::CONDOMINIUM, 1, 1, 'asc', 'piscina'), 'title'),
      'page 1 of the matches'
    );
    $this->assertSame(
      ['Zona húmeda'],
      array_column(myapi_area_fetch(self::CONDOMINIUM, 2, 1, 'asc', 'piscina'), 'title'),
      'page 2 of the matches'
    );
  }

  // ---------------------------------------------------------------------------
  // Fixtures.
  // ---------------------------------------------------------------------------

  /**
   * Four visible areas of the same condominium.
   *
   * "Zona húmeda" is the one that carries the point: its title has nothing to
   * do with a pool, and only its category ('kids_pool' -> "Piscina infantil")
   * makes it findable by "piscina".
   */
  private function seedAreas() {
    myapi_test_db_seed(['node' => [
      $this->areaRow(1, 'Piscina principal', 'pool'),
      $this->areaRow(2, 'Gimnasio', 'gym'),
      $this->areaRow(3, 'Zona húmeda', 'kids_pool'),
      $this->areaRow(4, 'Cancha techada'),
    ]]);
  }

  /**
   * One flat fixture row standing in for an area and all of its joined fields.
   */
  private function areaRow($nid, $title, $category = NULL, array $overrides = []) {
    $overrides += [
      'condominium'  => self::CONDOMINIUM,
      'area_status'  => 'active',
      'status'       => '1',
    ];

    return [
      'nid'                          => (string) $nid,
      'title'                        => $title,
      'type'                         => 'area',
      'status'                       => $overrides['status'],
      'field_condominium_target_id'  => (string) $overrides['condominium'],
      // Qualified: the projection aliases this column to 'status', which the
      // published flag above already occupies.
      'fsta.field_area_status_value' => $overrides['area_status'],
      'field_area_category_value'    => $category,
    ];
  }

  /**
   * The titles a ?search answers, in title order.
   */
  private function names($search) {
    $rows = myapi_area_fetch(self::CONDOMINIUM, 1, 50, 'asc', $search);

    return array_column($rows, 'title');
  }

}
