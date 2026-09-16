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

}
