<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.text.inc';
require_once __DIR__ . '/../../includes/myapi.user.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../includes/myapi.unit_query.inc';
require_once __DIR__ . '/../../includes/myapi.bot_auth.inc';
require_once __DIR__ . '/../../resources/bot.resource.inc';

/**
 * Unit tests for GET /api/v1/bot/units (SPEC 128).
 *
 * The weight of this file is on the four pure functions, and that is where the
 * endpoint really lives. Whether the bot finds the unit a resident typed over
 * WhatsApp comes down to one definition —
 *
 *   normalize(v) = preg_replace('/[\s\-._,#\/]+/', '', myapi_text_fold(v))
 *
 * — applied to BOTH the term and the stored name, plus a table of thresholds
 * for the pass that forgives a wrong letter. None of it needs a database, and
 * all of it is where the failures are: a name written in a format nobody
 * thought of is a unit the bot cannot find, and the failure is silent — an
 * empty answer looks exactly like a building that does not exist.
 *
 * The accent cases here are deliberately NOT borrowed from TextFoldTest.
 * myapi_text_fold() arrived with SPEC 119 for the service-category search and
 * now has a second owner; whoever changes it for a marketplace case must break
 * a test that names this endpoint, not only one that names the other.
 *
 * What this file does not prove is the database's half. The fixture builder
 * records joins without resolving them (see the SPEC 74 disclaimer in
 * bootstrap.php), so "an unpublished condominium hides its units" is asserted
 * as the condition the query carries over the fixture that models it — not as
 * something MySQL did. Nor does it say anything about how unit names are
 * REALLY written in production: that is risk 1 of the spec, and it is answered
 * with a census over the real table, not from here.
 */
class BotUnitsSearchTest extends TestCase {

  /* -------------------------------------------------------------------------
   * myapi_bot_search_normalize() — the one definition, applied to both sides.
   * ---------------------------------------------------------------------- */

  /**
   * The six separators disappear: space, '-', '.', ',', '#' and '/'.
   *
   * This is the half myapi_text_fold() does not do, and the reason it exists:
   * '3b' does not find 'Dpto 3-B' while the hyphen is still there.
   */
  public function testNormalizeRemovesEverySeparator() {
    $this->assertSame('dpto3b', myapi_bot_search_normalize('Dpto 3-B'));
    $this->assertSame('depto3b', myapi_bot_search_normalize('Depto. #3 B'));
    $this->assertSame('torreazul', myapi_bot_search_normalize('torre azul'));
    $this->assertSame('torreazul', myapi_bot_search_normalize('torre-azul'));
    $this->assertSame('bloqueac', myapi_bot_search_normalize('Bloque A/C'));
    $this->assertSame('casa12', myapi_bot_search_normalize('Casa, 12'));
  }

  /**
   * Accents and case fold away, which is what lets somebody type a name
   * without reaching for the accent key on a phone.
   */
  public function testNormalizeFoldsAccentsAndCase() {
    $this->assertSame('climatizacion', myapi_bot_search_normalize('Climatización'));
    $this->assertSame('edificioelsauco', myapi_bot_search_normalize('Edificio El Sáuco'));
    $this->assertSame('canaveral', myapi_bot_search_normalize('Cañaveral'));
    $this->assertSame('penablanca', myapi_bot_search_normalize('PEÑA BLANCA'));
  }

  /**
   * The point of one definition: the four ways a person writes the same
   * building collapse to one string, so the comparison cannot drift.
   */
  public function testNormalizeCollapsesEveryWritingOfTheSameName() {
    $written = ['torre azul', 'TORRE AZUL', 'Torre Azul', 'torre-azul', 'Torre.Azul'];

    foreach ($written as $value) {
      $this->assertSame('torreazul', myapi_bot_search_normalize($value), $value);
    }
  }

  /**
   * A unit stored as the bare number 3 normalises to '3' and not to ''.
   *
   * myapi_text_fold() answers '' to anything that is not a string, and risk 1
   * of the spec is precisely that unit names may be bare numbers. The cast is
   * what keeps those searchable.
   */
  public function testNormalizeAcceptsNonStrings() {
    $this->assertSame('3', myapi_bot_search_normalize(3));
    $this->assertSame('', myapi_bot_search_normalize(NULL));
    $this->assertSame('', myapi_bot_search_normalize(''));
    $this->assertSame('', myapi_bot_search_normalize('  -  '));
  }

  /* -------------------------------------------------------------------------
   * myapi_bot_search_tokens() — the words, in any order.
   * ---------------------------------------------------------------------- */

  /**
   * The separators are word boundaries here, not noise to delete.
   */
  public function testTokensSplitOnEverySeparator() {
    $this->assertSame(['edificio', 'torre', 'azul'], myapi_bot_search_tokens('Edificio Torre Azul'));
    $this->assertSame(['dpto', '3', 'b'], myapi_bot_search_tokens('Dpto 3-B'));
    $this->assertSame(['depto', '3', 'b'], myapi_bot_search_tokens('Depto. #3 B'));
  }

  /**
   * Empty pieces are dropped, so a term with doubled or trailing separators
   * does not produce a token that matches everything.
   */
  public function testTokensDropEmptyPieces() {
    $this->assertSame(['torre', 'azul'], myapi_bot_search_tokens('  torre   azul  '));
    $this->assertSame(['torre', 'azul'], myapi_bot_search_tokens('torre -- azul'));
    $this->assertSame([], myapi_bot_search_tokens('   '));
    $this->assertSame([], myapi_bot_search_tokens(''));
  }

  /**
   * Each word is folded on its own, so the accent does not survive inside one.
   */
  public function testTokensAreFoldedOneByOne() {
    $this->assertSame(['edificio', 'el', 'sauco'], myapi_bot_search_tokens('Edificio El Sáuco'));
  }

  /* -------------------------------------------------------------------------
   * myapi_bot_fuzzy_match() — the thresholds, and the guard under them.
   * ---------------------------------------------------------------------- */

  /**
   * Under four characters nothing is ever approximate.
   *
   * This is the guard that stops '3b' from being corrected into '3c' — the
   * case that hands the neighbour's unit back with a payment behind it.
   */
  public function testFuzzyNeverMatchesUnderFourCharacters() {
    $this->assertFalse(myapi_bot_fuzzy_match('3b', '3C'));
    $this->assertFalse(myapi_bot_fuzzy_match('3b', 'Dpto 3-C'));
    $this->assertFalse(myapi_bot_fuzzy_match('a1', 'A2'));
    // Three characters is still under the guard, one edit away or not.
    $this->assertFalse(myapi_bot_fuzzy_match('12b', '12C'));
  }

  /**
   * Four to six characters: one edit, and no more than one.
   */
  public function testFuzzyAllowsOneEditFromFourToSixCharacters() {
    $this->assertTrue(myapi_bot_fuzzy_match('asul', 'azul'), 'one substitution');
    $this->assertTrue(myapi_bot_fuzzy_match('torr', 'torre'), 'one deletion');
    $this->assertTrue(myapi_bot_fuzzy_match('torrre', 'torre'), 'one insertion');
    $this->assertFalse(myapi_bot_fuzzy_match('asul', 'ozol'), 'two substitutions, six characters or fewer');
  }

  /**
   * Seven or more: two edits, or 80% of similarity.
   */
  public function testFuzzyAllowsTwoEditsFromSevenCharacters() {
    $this->assertTrue(myapi_bot_fuzzy_match('pradeira', 'pradera'), 'one insertion');
    $this->assertTrue(myapi_bot_fuzzy_match('praderia', 'pradera'), 'two edits');
    $this->assertFalse(myapi_bot_fuzzy_match('praderas', 'quintas'), 'nothing in common');
  }

  /**
   * The comparison runs against each WORD of the value as well as against the
   * whole of it.
   *
   * Without the per-word pass 'torre asul' would not find 'Edificio Torre
   * Azul': the distance from 'torreasul' to 'edificiotorreazul' is eight, and
   * the typo would only be forgiven to somebody who typed the full name.
   */
  public function testFuzzyComparesAgainstEachWordOfTheValue() {
    $this->assertTrue(myapi_bot_fuzzy_match('asul', 'Edificio Torre Azul'));
    $this->assertTrue(myapi_bot_fuzzy_match('torre asul', 'Torre Azul'));
    $this->assertTrue(myapi_bot_fuzzy_match('edificiio', 'Edificio Torre Azul'));
  }

  /**
   * The third pass: each WORD of the term against the words of the value.
   *
   * This is the case the first two passes cannot reach, and the one the spec
   * names as an acceptance criterion. 'torreasul' against 'torre' is four
   * edits and 71% of similarity — outside every threshold — while 'torre' and
   * 'asul' matched one by one are zero edits and one.
   */
  public function testFuzzyComparesTheWordsOfTheTermOneByOne() {
    $this->assertTrue(myapi_bot_fuzzy_match('torre asul', 'Edificio Torre Azul'));
    $this->assertTrue(myapi_bot_fuzzy_match('torrre azul', 'Edificio Torre Azul'));
  }

  /**
   * Every word of the term has to land, exactly like the literal token pass.
   */
  public function testFuzzyRequiresEveryWordOfTheTerm() {
    $this->assertFalse(myapi_bot_fuzzy_match('torre verdde', 'Edificio Torre Azul'));
  }

  /**
   * THE GUARD HOLDS INSIDE THE THIRD PASS.
   *
   * A word under four characters has to appear literally; it is never
   * corrected. Otherwise '3b' would reach '3c' through the back door of a
   * second word, and the guard that protects the neighbour's unit would only
   * work for somebody who typed the unit name alone.
   */
  public function testFuzzyNeverCorrectsAShortWordOfTheTerm() {
    // 'torrre' lands on 'torre' by one edit, and '3b' would only land by being
    // corrected into '3c'. The whole term is three edits and 77% away from
    // every word of the value, so the first two passes are out and this is the
    // third one deciding on its own.
    $this->assertFalse(myapi_bot_fuzzy_match('torrre 3b', 'Torre Azul 3-C'));
    $this->assertFalse(myapi_bot_fuzzy_match('torrre 3b', 'Torre Azul 12'));
    // The same two words against the unit that really is 3B: '3b' is written
    // as it is, so there is nothing to forgive there and the typo in the first
    // word is.
    $this->assertTrue(myapi_bot_fuzzy_match('torrre 3b', 'Torre Azul 3-B'));
  }

  /**
   * What the guard does NOT promise, pinned so nobody reads more into it.
   *
   * A term short enough to be one word plus a unit — 'dpto 3b', six characters
   * — is compared as a whole against the whole value, and there one edit is
   * allowed: 'Dpto 3-C' comes back approximate. That is not the guard leaking;
   * it is the behaviour the spec asks for by name, and it is why `match` says
   * 'fuzzy' and the bot is told to confirm before charging anything to it.
   */
  public function testFuzzyStillApproximatesAWholeShortTerm() {
    $this->assertTrue(myapi_bot_fuzzy_match('dpto 3b', 'Dpto 3-C'));
    $this->assertFalse(myapi_bot_fuzzy_match('dpto 3b', 'Dpto 5-C'), 'two edits at six characters');
  }

  /**
   * An empty value on either side never matches, however short the other is.
   */
  public function testFuzzyRejectsEmptyValues() {
    $this->assertFalse(myapi_bot_fuzzy_match('torre', ''));
    $this->assertFalse(myapi_bot_fuzzy_match('', 'torre'));
    $this->assertFalse(myapi_bot_fuzzy_match('', ''));
  }

  /* -------------------------------------------------------------------------
   * myapi_bot_match_rank() — what gets in, and in what order.
   * ---------------------------------------------------------------------- */

  /**
   * The three literal ranks, in the order they are tried.
   */
  public function testRankTellsTheThreeLiteralQualitiesApart() {
    $this->assertSame('exact', myapi_bot_match_rank('torre azul', 'Torre Azul'));
    $this->assertSame('prefix', myapi_bot_match_rank('torre', 'Torre Azul'));
    $this->assertSame('contains', myapi_bot_match_rank('azul', 'Torre Azul'));
    $this->assertSame('contains', myapi_bot_match_rank('3B', 'Dpto 3-B'), '3b sits inside dpto3b, and the prefix is dpto');
  }

  /**
   * 'exact' is about the normalised forms, not about the characters typed.
   */
  public function testRankIsExactAcrossWritings() {
    $this->assertSame('exact', myapi_bot_match_rank('climatizacion', 'Climatización'));
    $this->assertSame('exact', myapi_bot_match_rank('dpto 3-b', 'Dpto 3B'), 'the separators go on both sides');
  }

  /**
   * Every word of the term, in any order, is a 'contains'.
   */
  public function testRankMatchesTokensInAnyOrder() {
    $this->assertSame('contains', myapi_bot_match_rank('azul torre', 'Edificio Torre Azul'));
    $this->assertSame('contains', myapi_bot_match_rank('azul edificio', 'Edificio Torre Azul'));
  }

  /**
   * AND between the words, not OR: one word landing is not a match.
   */
  public function testRankRequiresEveryToken() {
    $this->assertNull(myapi_bot_match_rank('torre verde', 'Edificio Torre Azul'));
  }

  /**
   * The approximate rank only exists in the second pass.
   *
   * Same arguments, two answers: this is the whole mechanism by which a search
   * that already worked never starts answering with similar-looking
   * neighbours.
   */
  public function testRankOnlyApproximatesWhenAsked() {
    $this->assertNull(myapi_bot_match_rank('torre asul', 'Edificio Torre Azul'));
    $this->assertSame('fuzzy', myapi_bot_match_rank('torre asul', 'Edificio Torre Azul', TRUE));
    $this->assertSame('fuzzy', myapi_bot_match_rank('torrre azul', 'Edificio Torre Azul', TRUE));
  }

  /**
   * A literal match stays literal in the approximate pass — the rank does not
   * degrade to 'fuzzy' just because the pass allows it.
   */
  public function testRankKeepsTheLiteralQualityInTheSecondPass() {
    $this->assertSame('exact', myapi_bot_match_rank('torre azul', 'Torre Azul', TRUE));
    $this->assertSame('prefix', myapi_bot_match_rank('torre', 'Torre Azul', TRUE));
  }

  /**
   * An empty term matches nothing.
   *
   * Without the guard strpos() answers 0 for the empty needle and every value
   * in the table would come back as a prefix match.
   */
  public function testRankRejectsAnEmptyTerm() {
    $this->assertNull(myapi_bot_match_rank('', 'Torre Azul'));
    $this->assertNull(myapi_bot_match_rank('-', 'Torre Azul', TRUE));
    $this->assertNull(myapi_bot_match_rank('torre', ''));
  }

  /**
   * Nothing in common is NULL, in both passes.
   */
  public function testRankIsNullWhenNothingMatches() {
    $this->assertNull(myapi_bot_match_rank('pradera', 'Edificio Torre Azul'));
    $this->assertNull(myapi_bot_match_rank('pradera', 'Edificio Torre Azul', TRUE));
  }

  /* =========================================================================
   * The endpoint. GET /api/v1/bot/units, called the way hook_menu() calls it.
   * ====================================================================== */

  /**
   * The key every fixture request sends, and the one the variable holds.
   */
  const API_KEY = 'a-shared-secret-for-the-bot';

  protected function setUp(): void {
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');

    myapi_test_db_seed();
    $GLOBALS['myapi_test_variables'] = ['myapi_bot_api_key' => self::API_KEY];
    $GLOBALS['myapi_test_watchdog'] = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_X_API_KEY'] = self::API_KEY;
    $_GET = ['q' => 'api/v1/bot/units'];
  }

  protected function tearDown(): void {
    myapi_test_db_seed();
    $GLOBALS['myapi_test_variables'] = [];
    $GLOBALS['myapi_test_watchdog'] = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_X_API_KEY']);
    $_GET = [];
  }

  /**
   * A published 'condominio' node row.
   *
   * payment_information is the column myapi_unit_fetch_condominiums() projects
   * field_informacion_pago_value to, and it is seeded by default so that the
   * value travelling to the bot is proved rather than assumed to be NULL.
   */
  private function condominiumRow($nid, $title, $status = 1, $payment_information = 'Banco Pichincha 2100XXXXXX') {
    return [
      'nid'                 => (string) $nid,
      'type'                => 'condominio',
      'status'              => (string) $status,
      'title'               => $title,
      'payment_information' => $payment_information,
    ];
  }

  /**
   * A published 'vivienda' node row.
   *
   * It carries the condominium reference TWICE, under the two names the two
   * queries read it by: field_condominio_target_id is the column
   * myapi_unit_fetch_unit_nids_by_condominium() filters on, and condominio_nid
   * is the alias myapi_unit_fetch_units() projects it to. The fixture builder
   * records joins without resolving them (see the SPEC 74 disclaimer in
   * bootstrap.php), so the row seeded here is the row each join would have
   * produced.
   */
  private function unitRow($nid, $name, $condominium_nid, array $overrides = []) {
    return $overrides + [
      'nid'                        => (string) $nid,
      'type'                       => 'vivienda',
      'status'                     => '1',
      'title'                      => NULL,
      'name'                       => $name,
      'category'                   => NULL,
      'area_m2'                    => NULL,
      'field_condominio_target_id' => (string) $condominium_nid,
      'condominio_nid'             => (string) $condominium_nid,
      'owner_uid'                  => NULL,
      'saldo_actual'               => '1234.56',
    ];
  }

  /**
   * A users row with both profile fields, as myapi_user_display_names() reads
   * them.
   */
  private function userRow($uid, $first, $last, $name = 'cuenta') {
    return [
      'uid'        => (string) $uid,
      'name'       => $name,
      'first_name' => $first,
      'last_name'  => $last,
    ];
  }

  /**
   * Two buildings, one unit each, and an owner on the first.
   *
   * 'Edificio Torre Azul' and 'Torre Azul II' are not decoration: they are
   * risk 2 of the spec — two buildings whose names differ by one word — and
   * most cases below want a base where more than one answer is possible.
   */
  private function seedTwoBuildings(array $extra = []) {
    myapi_test_db_seed([
      'node' => array_merge([
        $this->condominiumRow(12, 'Edificio Torre Azul'),
        $this->condominiumRow(31, 'Torre Azul II'),
        $this->unitRow(45, 'Dpto 3-B', 12, ['owner_uid' => '7']),
        $this->unitRow(46, 'Local 2', 31),
      ], isset($extra['node']) ? $extra['node'] : []),
      'users' => isset($extra['users']) ? $extra['users'] : [$this->userRow(7, 'Juan', 'Pérez')],
    ]);
  }

  /**
   * Calls the dispatcher and answers the status and the decoded body.
   */
  private function request($condominium = 'torre azul', $unit = '3B', $method = 'GET') {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_GET = ['q' => 'api/v1/bot/units'];
    if ($condominium !== NULL) {
      $_GET['condominium'] = $condominium;
    }
    if ($unit !== NULL) {
      $_GET['unit'] = $unit;
    }

    return myapi_test_capture(function () {
      myapi_bot_units_dispatch();
    });
  }

  /* -------------------------------------------------------------------------
   * The method and the four parameter errors.
   * ---------------------------------------------------------------------- */

  /**
   * Only GET. The endpoint reads; the bot writes through the endpoints that
   * already exist.
   */
  public function testEveryOtherMethodIs405() {
    foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'] as $method) {
      $result = $this->request('torre azul', '3B', $method);

      $this->assertSame(405, $result['status'], $method);
      $this->assertSame('method_not_allowed', $result['json']['error_code'], $method);
    }
  }

  /**
   * An absent or empty parameter is a 422 and not an empty answer: it is a bug
   * in the n8n flow, and "I found nothing" would hide it behind a WhatsApp
   * conversation with somebody who typed correctly.
   */
  public function testAMissingParameterIs422() {
    foreach ([NULL, '', '   '] as $value) {
      $this->assertSame('missing_condominium', $this->request($value, '3B')['json']['error_code']);
      $this->assertSame('missing_unit', $this->request('torre azul', $value)['json']['error_code']);
    }

    $this->assertSame(422, $this->request(NULL, '3B')['status']);
    $this->assertSame(422, $this->request('torre azul', NULL)['status']);
  }

  /**
   * A term that survives as fewer than two characters is a 422 of its own.
   *
   * Two is the length below which the search stops meaning anything: with one
   * character "contains" answers with half the building list and the cap of
   * twenty becomes the real selection criterion.
   */
  public function testATooShortParameterIs422() {
    foreach (['-', 'a', '#'] as $value) {
      $result = $this->request($value, '3B');

      $this->assertSame(422, $result['status'], $value);
      $this->assertSame('invalid_condominium', $result['json']['error_code'], $value);
    }

    $result = $this->request('torre azul', '3');

    $this->assertSame(422, $result['status']);
    $this->assertSame('invalid_unit', $result['json']['error_code']);
  }

  /**
   * Both parameters missing is ONE 422, and it names the condominium: that is
   * the one validated first.
   */
  public function testTheCondominiumIsValidatedFirst() {
    $this->assertSame('missing_condominium', $this->request(NULL, NULL)['json']['error_code']);
    $this->assertSame('invalid_condominium', $this->request('a', '3')['json']['error_code']);
  }

  /**
   * Not a single table is read on the way to a 422.
   *
   * The credential is checked first and the parameters second, both before any
   * query — the same ordering EndpointContractTest asserts for the 401.
   */
  public function testA422ReadsNoTable() {
    $this->seedTwoBuildings();
    $this->request(NULL, NULL);

    $this->assertSame([], myapi_test_db_queries());
  }

  /* -------------------------------------------------------------------------
   * Finding the unit.
   * ---------------------------------------------------------------------- */

  /**
   * The case the endpoint exists for: the building without its first word and
   * without accents, the unit without its separator.
   */
  public function testItFindsTheUnitAcrossBothNames() {
    $this->seedTwoBuildings();

    $result = $this->request('torre azul', '3B');

    $this->assertSame(200, $result['status']);
    $this->assertTrue($result['json']['success']);

    $data = $result['json']['data'];
    $this->assertTrue($data['found']);
    $this->assertSame(1, $data['total']);
    $this->assertSame(
      [
        'unit_id'                  => 45,
        'unit'                     => 'Dpto 3-B',
        'condominium_id'           => 12,
        'condominium'              => 'Edificio Torre Azul',
        'condominium_payment_info' => 'Banco Pichincha 2100XXXXXX',
        'owner'                    => ['uid' => 7, 'name' => 'Juan Pérez'],
        'match'                    => 'exact',
      ],
      $data['units'][0]
    );
  }

  /**
   * The words of the building in any order, and the four writings of the unit.
   */
  public function testItFindsTheUnitHoweverTheTermIsWritten() {
    $this->seedTwoBuildings();

    foreach (['torre azul', 'TORRE AZUL', 'Torre Azul', 'torre-azul', 'azul torre'] as $condominium) {
      $data = $this->request($condominium, '3B')['json']['data'];
      $this->assertSame(45, $data['units'][0]['unit_id'], $condominium);
    }

    foreach (['3b', '3-B', '3 B', '# 3B'] as $unit) {
      $data = $this->request('torre azul', $unit)['json']['data'];
      $this->assertSame(45, $data['units'][0]['unit_id'], $unit);
    }
  }

  /**
   * A building stored with an accent is found without it.
   */
  public function testItFindsABuildingWrittenWithoutItsAccent() {
    myapi_test_db_seed([
      'node' => [
        $this->condominiumRow(12, 'Climatización'),
        $this->unitRow(45, 'Dpto 3-B', 12),
      ],
    ]);

    $data = $this->request('climatizacion', '3B')['json']['data'];

    $this->assertSame(1, $data['total']);
    $this->assertSame('Climatización', $data['units'][0]['condominium']);
  }

  /**
   * One letter wrong in the building name, and the element says so.
   */
  public function testAMisspelledBuildingIsFoundAndFlagged() {
    $this->seedTwoBuildings();

    foreach (['torre asul', 'torrre azul'] as $condominium) {
      $data = $this->request($condominium, '3B')['json']['data'];

      $this->assertTrue($data['found'], $condominium);
      $this->assertSame(45, $data['units'][0]['unit_id'], $condominium);
      $this->assertSame('fuzzy', $data['units'][0]['match'], $condominium);
    }
  }

  /**
   * The approximate pass only runs when the literal one found NOTHING.
   *
   * 'Praderia del Sur' is one letter from 'pradera' and does not contain it,
   * so it is reachable ONLY by similarity — the second half of this case
   * proves that by removing its rival. With 'Conjunto Pradera' present the
   * literal pass answers, the approximate one never runs, and the building
   * that merely looks alike stays out.
   *
   * The price, which the spec pays knowingly: a search that already works
   * never starts offering neighbours.
   */
  public function testTheApproximatePassOnlyRunsWhenTheLiteralOneFoundNothing() {
    $both = [
      $this->condominiumRow(52, 'Conjunto Pradera'),
      $this->unitRow(47, 'Dpto 3-B', 52),
      $this->condominiumRow(53, 'Praderia del Sur'),
      $this->unitRow(48, 'Dpto 3-B', 53),
    ];
    myapi_test_db_seed(['node' => $both]);

    $data = $this->request('pradera', '3B')['json']['data'];

    $this->assertSame([47], array_column($data['units'], 'unit_id'), 'only the literal match');
    $this->assertSame('exact', $data['units'][0]['match']);

    // The same term, the same building, with the literal rival gone: now the
    // second pass runs and 'Praderia del Sur' does come back — flagged.
    myapi_test_db_seed(['node' => array_slice($both, 2)]);

    $data = $this->request('pradera', '3B')['json']['data'];

    $this->assertSame([48], array_column($data['units'], 'unit_id'));
    $this->assertSame('fuzzy', $data['units'][0]['match']);
  }

  /**
   * A literal match by prefix or by containment is still `match: "exact"`.
   *
   * "exact" means NOT APPROXIMATED, not "identical": n8n has two behaviours —
   * charge, or confirm before charging — and a third label would be a field
   * nobody reads.
   */
  public function testALiteralPartialMatchIsReportedAsExact() {
    $this->seedTwoBuildings();

    // 'torreazul' is contained in 'edificiotorreazul', and '3b' in 'dpto3b':
    // neither is identical, and neither needed a letter corrected.
    $this->assertSame('exact', $this->request('torre azul', '3B')['json']['data']['units'][0]['match']);
  }

  /* -------------------------------------------------------------------------
   * The guard that protects the neighbour's unit.
   * ---------------------------------------------------------------------- */

  /**
   * '3b' does NOT reach '3C'. The whole reason the length guard exists.
   */
  public function testAShortUnitTermIsNeverApproximated() {
    myapi_test_db_seed([
      'node' => [
        $this->condominiumRow(12, 'Edificio Torre Azul'),
        $this->unitRow(45, 'Dpto 3-C', 12),
      ],
    ]);

    $data = $this->request('torre azul', '3b')['json']['data'];

    $this->assertFalse($data['found']);
    $this->assertSame(0, $data['total']);
    $this->assertSame([], $data['units']);
  }

  /**
   * 'dpto 3b' does reach 'Dpto 3-C', and it is flagged as approximate.
   *
   * The spec asks for this by name. The defence here is not the distance, it
   * is `match: "fuzzy"` reaching n8n and n8n confirming before it charges.
   */
  public function testALongerUnitTermCanBeApproximated() {
    myapi_test_db_seed([
      'node' => [
        $this->condominiumRow(12, 'Edificio Torre Azul'),
        $this->unitRow(45, 'Dpto 3-C', 12),
      ],
    ]);

    $data = $this->request('torre azul', 'dpto 3b')['json']['data'];

    $this->assertTrue($data['found']);
    $this->assertSame('fuzzy', $data['units'][0]['match']);
  }

  /* -------------------------------------------------------------------------
   * What never appears.
   * ---------------------------------------------------------------------- */

  /**
   * An unpublished unit is not in the answer.
   */
  public function testAnUnpublishedUnitNeverAppears() {
    myapi_test_db_seed([
      'node' => [
        $this->condominiumRow(12, 'Edificio Torre Azul'),
        $this->unitRow(45, 'Dpto 3-B', 12, ['status' => '0']),
      ],
    ]);

    $this->assertFalse($this->request('torre azul', '3B')['json']['data']['found']);
  }

  /**
   * A unit whose condominium is unpublished is not in the answer either — not
   * even when the building's exact name is what was typed.
   *
   * The nid never enters the map that resolves the term, so the second phase
   * never asks for its units.
   */
  public function testAUnitOfAnUnpublishedCondominiumNeverAppears() {
    myapi_test_db_seed([
      'node' => [
        $this->condominiumRow(12, 'Edificio Torre Azul', 0),
        $this->unitRow(45, 'Dpto 3-B', 12),
      ],
    ]);

    $data = $this->request('Edificio Torre Azul', '3B')['json']['data'];

    $this->assertFalse($data['found']);
    $this->assertSame(0, $data['total']);
  }

  /**
   * The balance is within reach of the query and still never travels, and
   * neither does anything of the owner beyond a uid and a name.
   *
   * The condominium's payment information is the exception, and a deliberate
   * one: it describes the building's bank account, the bot reads it back over
   * WhatsApp, and it is asserted present in the key list below.
   */
  public function testNoElementCarriesTheBalanceOrPersonalData() {
    $this->seedTwoBuildings();

    $element = $this->request('torre azul', '3B')['json']['data']['units'][0];

    $this->assertSame(
      ['unit_id', 'unit', 'condominium_id', 'condominium', 'condominium_payment_info', 'owner', 'match'],
      array_keys($element)
    );
    $this->assertSame(['uid', 'name'], array_keys($element['owner']));

    $body = json_encode($element);
    foreach (['1234.56', 'saldo', 'current_balance', 'telefono', 'email'] as $forbidden) {
      $this->assertStringNotContainsString($forbidden, $body, $forbidden);
    }
  }

  /**
   * Each element carries the payment information of ITS OWN building, and NULL
   * when that building has no row in field_data_field_informacion_pago.
   *
   * The value is resolved after the cut to five, by a query of its own over
   * the condominiums that survived, so what this pins is that the text does
   * not slide from one building to the next on the way.
   */
  public function testEachElementCarriesItsOwnBuildingsPaymentInformation() {
    myapi_test_db_seed([
      'node' => [
        $this->condominiumRow(12, 'Edificio Torre Azul', 1, 'Banco Pichincha 2100XXXXXX'),
        $this->condominiumRow(31, 'Torre Azul II', 1, NULL),
        $this->unitRow(45, 'Dpto 3-B', 12),
        $this->unitRow(46, 'Dpto 3-B', 31),
      ],
    ]);

    $units = $this->request('torre azul', '3B')['json']['data']['units'];

    $this->assertCount(2, $units);

    $info = [];
    foreach ($units as $unit) {
      $info[$unit['condominium_id']] = $unit['condominium_payment_info'];
    }

    $this->assertSame('Banco Pichincha 2100XXXXXX', $info[12]);
    $this->assertNull($info[31]);
  }

  /**
   * A unit with no owner assigned still appears, with `owner: null`.
   *
   * It is not omitted: the bot can charge the payment to it all the same.
   */
  public function testAUnitWithoutAnOwnerAppearsWithANullOwner() {
    $this->seedTwoBuildings();

    $data = $this->request('torre azul', 'local 2')['json']['data'];

    $this->assertSame(46, $data['units'][0]['unit_id']);
    $this->assertNull($data['units'][0]['owner']);
  }

  /**
   * An owner whose account no longer resolves is a null owner too, and the
   * unit still travels.
   */
  public function testAnOwnerWhoseAccountIsGoneIsANullOwner() {
    $this->seedTwoBuildings(['users' => []]);

    $data = $this->request('torre azul', '3B')['json']['data'];

    $this->assertSame(45, $data['units'][0]['unit_id']);
    $this->assertNull($data['units'][0]['owner']);
  }

  /* -------------------------------------------------------------------------
   * Nothing found, the cut, and the order.
   * ---------------------------------------------------------------------- */

  /**
   * No match is a 200 with the same shape, never a 404.
   */
  public function testNothingFoundIsA200WithTheSameShape() {
    $this->seedTwoBuildings();

    $result = $this->request('pradera', '3B');

    $this->assertSame(200, $result['status']);
    $this->assertSame(
      ['found' => FALSE, 'total' => 0, 'units' => []],
      $result['json']['data']
    );
  }

  /**
   * A building term that matches nothing does not touch the unit table.
   *
   * The performance criterion of the spec, counted rather than argued: one
   * query — the 150 titles — and no more.
   */
  public function testAnUnmatchedBuildingNeverQueriesTheUnitTable() {
    // seedTwoBuildings() reseeds, and reseeding clears the recorded queries,
    // so what follows is counted from zero.
    $this->seedTwoBuildings();
    $this->request('pradera', '3B');

    $queries = myapi_test_db_queries();

    $this->assertCount(1, $queries, 'only the condominium titles are read');
    $this->assertTrue($this->queryReads($queries[0], 'condominio'));
  }

  /**
   * Over five matches: five elements, and `total` with the real number.
   */
  public function testMoreThanFiveMatchesAnswerFiveAndTheRealTotal() {
    $nodes = [];
    for ($i = 1; $i <= 8; $i++) {
      $nodes[] = $this->condominiumRow(100 + $i, 'Torre Azul ' . $i);
      $nodes[] = $this->unitRow(200 + $i, 'Dpto 3-B', 100 + $i);
    }
    myapi_test_db_seed(['node' => $nodes]);

    $data = $this->request('torre azul', '3B')['json']['data'];

    $this->assertTrue($data['found']);
    $this->assertSame(8, $data['total']);
    $this->assertCount(5, $data['units']);
  }

  /**
   * Two identical calls answer the same five in the same order.
   */
  public function testTwoIdenticalCallsAnswerTheSameOrder() {
    $nodes = [];
    for ($i = 1; $i <= 8; $i++) {
      $nodes[] = $this->condominiumRow(100 + $i, 'Torre Azul ' . $i);
      $nodes[] = $this->unitRow(200 + $i, 'Dpto 3-B', 100 + $i);
    }
    myapi_test_db_seed(['node' => $nodes]);

    $first = $this->request('torre azul', '3B')['json']['data']['units'];
    $second = $this->request('torre azul', '3B')['json']['data']['units'];

    $this->assertSame($first, $second);
  }

  /**
   * The five criteria, in order: unit rank, condominium rank, condominium
   * title, unit name, unit_id.
   *
   * The fixture is built so each criterion decides exactly one pair:
   *   - 45 before everything else: its unit rank is 'exact' ('3b'), the rest
   *     are 'contains' ('dpto3b').
   *   - 46 before 47: equal unit rank, and 'Torre Azul II' is a prefix match
   *     while 'Edificio Torre Azul' only contains the term.
   *   - 47 before 48: equal ranks, and 'edificiotorreazul' sorts before
   *     'zetatorreazul'.
   *   - 48 before 49: equal ranks and the same building, and 'dpto3b' sorts
   *     before 'dpto3bis'.
   *   - 49 before 50: everything equal, and 49 is the lower nid.
   */
  public function testTheOrderFollowsTheFiveCriteria() {
    myapi_test_db_seed([
      'node' => [
        $this->condominiumRow(31, 'Torre Azul II'),
        $this->condominiumRow(12, 'Edificio Torre Azul'),
        $this->condominiumRow(90, 'Zeta Torre Azul'),
        $this->unitRow(50, 'Dpto 3-Bis', 90),
        $this->unitRow(49, 'Dpto 3-Bis', 90),
        $this->unitRow(48, 'Dpto 3-B', 90),
        $this->unitRow(47, 'Dpto 3-B', 12),
        $this->unitRow(46, 'Dpto 3-B', 31),
        $this->unitRow(45, '3B', 31),
      ],
    ]);

    $data = $this->request('torre azul', '3B')['json']['data'];

    $this->assertSame(6, $data['total']);
    $this->assertSame([45, 46, 47, 48, 49], array_column($data['units'], 'unit_id'));
  }

  /**
   * The cap: a term matching more than twenty buildings examines exactly
   * twenty, and `total` counts inside those.
   *
   * This is the honest cost of the cap, written down as a test rather than
   * left to be discovered: with 25 buildings holding one matching unit each,
   * the answer says 20 and not 25.
   */
  public function testOnlyTwentyCondominiumsAreExamined() {
    $nodes = [];
    for ($i = 1; $i <= 25; $i++) {
      $nodes[] = $this->condominiumRow(100 + $i, 'Torre Azul ' . sprintf('%02d', $i));
      $nodes[] = $this->unitRow(200 + $i, 'Dpto 3-B', 100 + $i);
    }
    myapi_test_db_seed(['node' => $nodes]);

    $data = $this->request('torre azul', '3B')['json']['data'];

    $this->assertSame(MYAPI_BOT_SEARCH_CONDOMINIUM_LIMIT, $data['total']);
    $this->assertCount(5, $data['units']);
  }

  /**
   * Whether a recorded query reads rows of a given node type.
   */
  private function queryReads(array $query, $type) {
    foreach ($query['conditions'] as $condition) {
      if ($condition['field'] === 'n.type' && $condition['value'] === $type) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
