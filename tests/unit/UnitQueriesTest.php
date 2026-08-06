<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../resources/unit.resource.inc';

/**
 * Unit tests for the four fetchers of resources/unit.resource.inc and for
 * myapi_unit_related_nids() (SPECS 08/09/10, covered by SPEC 74).
 *
 * These are the functions SPEC 73 would have left out, because each one starts
 * with a db_select(). What SPEC 74 adds to the bootstrap is a FIXTURE query
 * builder — read its disclaimer there before reading these cases — and what
 * that buys is exactly the half of these functions that is theirs and not the
 * database's:
 *
 *   - the loop that turns rows into a map (occupant uids, user names,
 *     condominium titles), including which row wins when two answer for the
 *     same key;
 *   - the fallback chains: field_ocupantes before field_ocupante,
 *     "nombre apellidos" before users.name;
 *   - the early returns that skip a query for an empty input;
 *   - and the SHAPE of the query built, recorded call by call, which is the
 *     only way to assert from here the conditions the database would apply:
 *     type/status/deleted/entity_type.
 *
 * That last kind is not a substitute for running SQL, and the cases say which
 * they are. Whether a LEFT JOIN over a multi-value field duplicates a unit row
 * is a question only a real database answers, and it stays with
 * tests/integration.
 */
class UnitQueriesTest extends TestCase {

  protected function setUp(): void {
    myapi_test_db_seed();
  }

  /**
   * The ON clause of a recorded join, by joined table name.
   */
  private function joinCondition(array $query, $table) {
    foreach ($query['joins'] as $join) {
      if ($join['table'] === $table) {
        return $join['condition'];
      }
    }

    return NULL;
  }

  /**
   * The value of a recorded condition, by field name.
   */
  private function conditionValue(array $query, $field) {
    foreach ($query['conditions'] as $condition) {
      if ($condition['field'] === $field) {
        return $condition['value'];
      }
    }

    return NULL;
  }

  /**
   * Whether a recorded query carries a given condition.
   */
  private function hasCondition(array $query, $field, $value, $operator = '=') {
    foreach ($query['conditions'] as $condition) {
      if ($condition['field'] === $field && $condition['value'] == $value && $condition['operator'] === $operator) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /* -------------------------------------------------------------------------
   * myapi_unit_related_nids() — SPEC 08, step 2.
   * ---------------------------------------------------------------------- */

  /**
   * The three relations of SPEC 08 merged into one list: owner, legacy
   * occupant and current occupant.
   */
  public function testRelatedNidsMergesTheThreeRelations() {
    myapi_test_db_seed([
      'field_data_field_propietario' => [
        ['entity_id' => '45', 'field_propietario_target_id' => '3', 'deleted' => '0'],
      ],
      'field_data_field_ocupante' => [
        ['entity_id' => '46', 'field_ocupante_target_id' => '3', 'deleted' => '0'],
      ],
      'field_data_field_ocupantes' => [
        ['entity_id' => '47', 'field_ocupantes_target_id' => '3', 'deleted' => '0'],
      ],
    ]);

    $this->assertSame(['45', '46', '47'], myapi_unit_related_nids(3));
  }

  /**
   * A unit the user owns AND occupies is listed once: the merge dedupes, or
   * the endpoint would fetch and answer the same unit twice.
   */
  public function testRelatedNidsDedupesAcrossRelations() {
    myapi_test_db_seed([
      'field_data_field_propietario' => [
        ['entity_id' => '45', 'field_propietario_target_id' => '3', 'deleted' => '0'],
      ],
      'field_data_field_ocupantes' => [
        ['entity_id' => '45', 'field_ocupantes_target_id' => '3', 'deleted' => '0'],
        ['entity_id' => '46', 'field_ocupantes_target_id' => '3', 'deleted' => '0'],
      ],
    ]);

    $nids = myapi_unit_related_nids(3);

    $this->assertSame(['45', '46'], $nids);
    $this->assertSame([0, 1], array_keys($nids), 'array_values() re-indexes after the dedupe');
  }

  /**
   * Rows belonging to another user are not returned. The filter is the
   * database's, and the fixture applies it because the condition names a
   * column the fixture carries — a case that would be untestable if the
   * builder ignored conditions.
   */
  public function testRelatedNidsOnlyReturnsTheUsersOwnRows() {
    myapi_test_db_seed([
      'field_data_field_propietario' => [
        ['entity_id' => '45', 'field_propietario_target_id' => '3', 'deleted' => '0'],
        ['entity_id' => '99', 'field_propietario_target_id' => '8', 'deleted' => '0'],
      ],
    ]);

    $this->assertSame(['45'], myapi_unit_related_nids(3));
  }

  /**
   * A user with no relation at all gets an empty list — the value SPEC 08 uses
   * to answer {"properties": []} without a single further query.
   */
  public function testRelatedNidsIsEmptyForAnUnrelatedUser() {
    $this->assertSame([], myapi_unit_related_nids(3));
  }

  /**
   * The three queries hit the three field tables, each filtering by uid and by
   * deleted = 0. SPEC 08 chose three queries merged in PHP over a UNION, and
   * this is what pins that decision: a fourth relation added later must show
   * up here.
   */
  public function testRelatedNidsBuildsThreeFilteredQueries() {
    myapi_unit_related_nids(3);
    $queries = myapi_test_db_queries();

    $this->assertCount(3, $queries);
    $this->assertSame(
      ['field_data_field_propietario', 'field_data_field_ocupante', 'field_data_field_ocupantes'],
      array_column($queries, 'table')
    );

    $this->assertTrue($this->hasCondition($queries[0], 'field_propietario_target_id', 3));
    $this->assertTrue($this->hasCondition($queries[1], 'field_ocupante_target_id', 3));
    $this->assertTrue($this->hasCondition($queries[2], 'field_ocupantes_target_id', 3));

    foreach ($queries as $index => $query) {
      $this->assertTrue($this->hasCondition($query, 'deleted', 0), 'query ' . $index . ' filters deleted rows out');
    }
  }

  /* -------------------------------------------------------------------------
   * myapi_unit_fetch_units() — SPECS 08/10.
   * ---------------------------------------------------------------------- */

  /**
   * Seeds one published 'vivienda' with every joined value present.
   */
  private function seedUnitNodes(array $extra_rows = []) {
    myapi_test_db_seed([
      'node' => array_merge([
        [
          'nid'            => '45',
          'type'           => 'vivienda',
          'status'         => '1',
          'name'           => 'Depto. 4B',
          'category'       => 'departamento',
          'area_m2'        => '92.00',
          'condominio_nid' => '12',
          'owner_uid'      => '3',
          'saldo_actual'   => '-3393.0000',
        ],
      ], $extra_rows),
    ]);
  }

  /**
   * The row comes back with the seven aliases the response builder reads, and
   * no others: the alias list IS the contract between the two functions.
   */
  public function testFetchUnitsReturnsTheAliasedColumns() {
    $this->seedUnitNodes();

    $units = myapi_unit_fetch_units(['45']);

    $this->assertCount(1, $units);
    $this->assertSame(
      ['nid', 'name', 'category', 'area_m2', 'condominio_nid', 'owner_uid', 'saldo_actual'],
      array_keys((array) $units[0])
    );
    $this->assertSame('Depto. 4B', $units[0]->name);
    $this->assertSame('-3393.0000', $units[0]->saldo_actual);
  }

  /**
   * An unpublished unit is not returned, even to its own owner (SPEC 08
   * acceptance criterion).
   */
  public function testFetchUnitsSkipsUnpublishedUnits() {
    $this->seedUnitNodes([
      ['nid' => '46', 'type' => 'vivienda', 'status' => '0', 'name' => 'Depto. 5B'],
    ]);

    $units = myapi_unit_fetch_units(['45', '46']);

    $this->assertSame(['45'], array_column($units, 'nid'));
  }

  /**
   * A node of another type is not returned even if its nid is in the list: the
   * bundle filter is what keeps a condominium out of the units query.
   */
  public function testFetchUnitsSkipsOtherNodeTypes() {
    $this->seedUnitNodes([
      ['nid' => '12', 'type' => 'condominio', 'status' => '1', 'title' => 'Edificio El Sáuco'],
    ]);

    $units = myapi_unit_fetch_units(['45', '12']);

    $this->assertSame(['45'], array_column($units, 'nid'));
  }

  /**
   * A nid outside the requested set is not returned.
   */
  public function testFetchUnitsOnlyReturnsTheRequestedNids() {
    $this->seedUnitNodes([
      ['nid' => '99', 'type' => 'vivienda', 'status' => '1', 'name' => 'Depto. 9A'],
    ]);

    $units = myapi_unit_fetch_units(['45']);

    $this->assertSame(['45'], array_column($units, 'nid'));
  }

  /**
   * A unit with none of the optional fields set comes back with NULLs, not
   * missing keys: that is what makes every LEFT JOIN a LEFT JOIN, and what
   * lets the builder read $unit->owner_uid unconditionally.
   */
  public function testFetchUnitsAnswersNullForEmptyJoinedFields() {
    myapi_test_db_seed([
      'node' => [['nid' => '45', 'type' => 'vivienda', 'status' => '1']],
    ]);

    $unit = myapi_unit_fetch_units(['45'])[0];

    $this->assertNull($unit->name);
    $this->assertNull($unit->category);
    $this->assertNull($unit->area_m2);
    $this->assertNull($unit->condominio_nid);
    $this->assertNull($unit->owner_uid);
    $this->assertNull($unit->saldo_actual);
  }

  /**
   * The query shape, which is where the three specs meet: base table 'node'
   * filtered by bundle, status and nid, and six LEFT JOINs — the five of
   * SPEC 08 plus field_saldo_actual (SPEC 10) — every one of them scoped by
   * entity_type = 'node' and deleted = 0.
   *
   * This is an assertion about the SQL BUILT, not about what a database would
   * answer for it. It is here because those scopes are exactly what a schema
   * change breaks silently.
   */
  public function testFetchUnitsBuildsTheDocumentedQuery() {
    $this->seedUnitNodes();
    myapi_unit_fetch_units(['45']);
    $query = myapi_test_db_queries()[0];

    $this->assertSame('node', $query['table']);
    $this->assertTrue($this->hasCondition($query, 'n.type', 'vivienda'));
    $this->assertTrue($this->hasCondition($query, 'n.status', 1));
    $this->assertTrue($this->hasCondition($query, 'n.nid', ['45'], 'IN'));

    $joined = [
      'field_data_field_nombre_vivienda',
      'field_data_field_categoria',
      'taxonomy_term_data',
      'field_data_field_total_m2',
      'field_data_field_condominio',
      'field_data_field_propietario',
      'field_data_field_saldo_actual',
    ];
    $this->assertSame($joined, array_column($query['joins'], 'table'));

    foreach ($query['joins'] as $join) {
      $this->assertSame('LEFT', $join['type'], $join['table']);
      if ($join['table'] === 'taxonomy_term_data') {
        // The only join that is not over a field table: it resolves the term
        // name and has neither entity_type nor deleted.
        $this->assertStringContainsString('tcat.tid = fc.field_categoria_tid', $join['condition']);
        continue;
      }
      $this->assertStringContainsString("entity_type = 'node'", $join['condition'], $join['table']);
      $this->assertStringContainsString('deleted = 0', $join['condition'], $join['table']);
    }
  }

  /* -------------------------------------------------------------------------
   * myapi_unit_fetch_condominiums() — SPEC 08, step 4.
   * ---------------------------------------------------------------------- */

  /**
   * The map is keyed by nid, which is how the builder looks a condominium up.
   */
  public function testFetchCondominiumsReturnsAMapKeyedByNid() {
    myapi_test_db_seed([
      'node' => [
        ['nid' => '12', 'type' => 'condominio', 'status' => '1', 'title' => 'Edificio El Sáuco', 'payment_information' => 'Banco X'],
        ['nid' => '30', 'type' => 'condominio', 'status' => '1', 'title' => 'Conjunto La Pradera', 'payment_information' => NULL],
      ],
    ]);

    $condominiums = myapi_unit_fetch_condominiums(['12', '30']);

    $this->assertSame(['12', '30'], array_map('strval', array_keys($condominiums)));
    $this->assertSame('Edificio El Sáuco', $condominiums[12]->title);
    $this->assertSame('Banco X', $condominiums[12]->payment_information);
    $this->assertNull($condominiums[30]->payment_information);
  }

  /**
   * An unpublished condominium is absent from the map, which is what makes its
   * units disappear from the response (SPEC 08 acceptance criterion). The
   * absence, not an error, is the documented behaviour.
   */
  public function testFetchCondominiumsSkipsUnpublishedOnes() {
    myapi_test_db_seed([
      'node' => [
        ['nid' => '12', 'type' => 'condominio', 'status' => '1', 'title' => 'Edificio El Sáuco'],
        ['nid' => '30', 'type' => 'condominio', 'status' => '0', 'title' => 'Conjunto La Pradera'],
      ],
    ]);

    $condominiums = myapi_unit_fetch_condominiums(['12', '30']);

    $this->assertArrayHasKey(12, $condominiums);
    $this->assertArrayNotHasKey(30, $condominiums);
  }

  /**
   * A nid that is not a condominium node is not resolved either.
   */
  public function testFetchCondominiumsSkipsOtherNodeTypes() {
    myapi_test_db_seed([
      'node' => [
        ['nid' => '45', 'type' => 'vivienda', 'status' => '1', 'title' => 'Depto. 4B'],
      ],
    ]);

    $this->assertSame([], myapi_unit_fetch_condominiums(['45']));
  }

  /**
   * The empty early return: no nids means no query at all, not a query with an
   * empty IN list (which Drupal turns into invalid SQL).
   */
  public function testFetchCondominiumsSkipsTheQueryForAnEmptyInput() {
    $this->assertSame([], myapi_unit_fetch_condominiums([]));
    $this->assertSame([], myapi_test_db_queries());
  }

  /**
   * The query shape: 'condominio' bundle, published only, and the payment
   * information joined with the same entity_type/deleted scope as the rest.
   */
  public function testFetchCondominiumsBuildsTheDocumentedQuery() {
    myapi_unit_fetch_condominiums(['12']);
    $query = myapi_test_db_queries()[0];

    $this->assertSame('node', $query['table']);
    $this->assertTrue($this->hasCondition($query, 'n.type', 'condominio'));
    $this->assertTrue($this->hasCondition($query, 'n.status', 1));
    $this->assertTrue($this->hasCondition($query, 'n.nid', ['12'], 'IN'));

    $condition = $this->joinCondition($query, 'field_data_field_informacion_pago');
    $this->assertStringContainsString("entity_type = 'node'", $condition);
    $this->assertStringContainsString('deleted = 0', $condition);
  }

  /* -------------------------------------------------------------------------
   * myapi_unit_fetch_occupant_uids() — SPEC 09, the delta rule.
   * ---------------------------------------------------------------------- */

  /**
   * One value in the multi-value field resolves to it.
   */
  public function testOccupantFromASingleCurrentValue() {
    myapi_test_db_seed([
      'field_data_field_ocupantes' => [
        ['entity_id' => '45', 'field_ocupantes_target_id' => '7', 'delta' => '0', 'deleted' => '0'],
      ],
    ]);

    $this->assertSame(['45' => '7'], myapi_unit_fetch_occupant_uids(['45']));
  }

  /**
   * Several values: the highest delta wins — SPEC 09's definition of "the
   * current occupant". The fixture is seeded OUT of delta order on purpose, so
   * the case fails if the ORDER BY is dropped and the loop starts depending on
   * the row order of the table.
   */
  public function testOccupantIsTheHighestDelta() {
    myapi_test_db_seed([
      'field_data_field_ocupantes' => [
        ['entity_id' => '45', 'field_ocupantes_target_id' => '9', 'delta' => '2', 'deleted' => '0'],
        ['entity_id' => '45', 'field_ocupantes_target_id' => '5', 'delta' => '0', 'deleted' => '0'],
        ['entity_id' => '45', 'field_ocupantes_target_id' => '7', 'delta' => '1', 'deleted' => '0'],
      ],
    ]);

    $this->assertSame(['45' => '9'], myapi_unit_fetch_occupant_uids(['45']));
  }

  /**
   * The ORDER BY that makes the loop above correct is really in the query.
   * Asserted separately because the fixture builder applies it, but only a
   * real database proves it over a real index — this pins the intent.
   */
  public function testOccupantQueryOrdersByDeltaAscending() {
    myapi_unit_fetch_occupant_uids(['45']);
    $query = myapi_test_db_queries('field_data_field_ocupantes')[0];

    $this->assertSame([['field' => 'delta', 'direction' => 'ASC']], $query['order']);
  }

  /**
   * No rows in the multi-value field: the legacy single-value field answers
   * (SPEC 09 acceptance criterion).
   */
  public function testOccupantFallsBackToTheLegacyField() {
    myapi_test_db_seed([
      'field_data_field_ocupante' => [
        ['entity_id' => '45', 'field_ocupante_target_id' => '4', 'deleted' => '0'],
      ],
    ]);

    $this->assertSame(['45' => '4'], myapi_unit_fetch_occupant_uids(['45']));
  }

  /**
   * Values in both fields: the current one wins and the legacy one is ignored
   * (SPEC 09's precedence decision).
   */
  public function testCurrentFieldWinsOverTheLegacyOne() {
    myapi_test_db_seed([
      'field_data_field_ocupantes' => [
        ['entity_id' => '45', 'field_ocupantes_target_id' => '7', 'delta' => '0', 'deleted' => '0'],
      ],
      'field_data_field_ocupante' => [
        ['entity_id' => '45', 'field_ocupante_target_id' => '4', 'deleted' => '0'],
      ],
    ]);

    $this->assertSame(['45' => '7'], myapi_unit_fetch_occupant_uids(['45']));
  }

  /**
   * The legacy query asks ONLY for the nids the first query left unresolved.
   * That narrowing is the whole point of the fallback: asking for all of them
   * again would let a legacy row overwrite a current occupant.
   */
  public function testLegacyQueryOnlyAsksForUnresolvedNids() {
    myapi_test_db_seed([
      'field_data_field_ocupantes' => [
        ['entity_id' => '45', 'field_ocupantes_target_id' => '7', 'delta' => '0', 'deleted' => '0'],
      ],
      'field_data_field_ocupante' => [
        ['entity_id' => '46', 'field_ocupante_target_id' => '4', 'deleted' => '0'],
      ],
    ]);

    $occupants = myapi_unit_fetch_occupant_uids(['45', '46', '47']);

    $this->assertSame(['45' => '7', '46' => '4'], $occupants);
    $legacy = myapi_test_db_queries('field_data_field_ocupante')[0];
    $this->assertSame(['46', '47'], $this->conditionValue($legacy, 'entity_id'));
  }

  /**
   * Every nid resolved by the current field means the legacy query is never
   * built — one query saved on the common path.
   */
  public function testLegacyQueryIsSkippedWhenEverythingIsResolved() {
    myapi_test_db_seed([
      'field_data_field_ocupantes' => [
        ['entity_id' => '45', 'field_ocupantes_target_id' => '7', 'delta' => '0', 'deleted' => '0'],
      ],
    ]);

    myapi_unit_fetch_occupant_uids(['45']);

    $this->assertCount(1, myapi_test_db_queries());
    $this->assertSame([], myapi_test_db_queries('field_data_field_ocupante'));
  }

  /**
   * A unit with no occupant in either field is simply absent from the map —
   * the builder turns that absence into occupant_uid: null.
   */
  public function testUnitWithoutAnyOccupantIsAbsentFromTheMap() {
    myapi_test_db_seed([
      'field_data_field_ocupantes' => [
        ['entity_id' => '45', 'field_ocupantes_target_id' => '7', 'delta' => '0', 'deleted' => '0'],
      ],
    ]);

    $occupants = myapi_unit_fetch_occupant_uids(['45', '46']);

    $this->assertArrayNotHasKey('46', $occupants);
    $this->assertArrayNotHasKey(46, $occupants);
  }

  /**
   * No occupants at all: an empty map, and no notice from the array_diff over
   * an empty set of keys.
   */
  public function testNoOccupantsGivesAnEmptyMap() {
    $this->assertSame([], myapi_unit_fetch_occupant_uids(['45', '46']));
  }

  /**
   * The empty early return, same reason as the condominium one.
   */
  public function testOccupantsSkipTheQueryForAnEmptyInput() {
    $this->assertSame([], myapi_unit_fetch_occupant_uids([]));
    $this->assertSame([], myapi_test_db_queries());
  }

  /**
   * Both queries filter deleted rows out — a field value removed from a node
   * stays in the table with deleted = 1 and would otherwise resurrect a former
   * occupant.
   */
  public function testBothOccupantQueriesFilterDeletedRows() {
    myapi_unit_fetch_occupant_uids(['45']);

    foreach (myapi_test_db_queries() as $query) {
      $this->assertTrue($this->hasCondition($query, 'deleted', 0), $query['table']);
    }
  }

  /* -------------------------------------------------------------------------
   * myapi_unit_fetch_user_names() — SPECS 08/09, the name fallback.
   * ---------------------------------------------------------------------- */

  /**
   * Seeds a users fixture out of uid => [first_name, last_name, name].
   */
  private function seedUsers(array $accounts) {
    $rows = [];
    foreach ($accounts as $uid => $account) {
      $rows[] = [
        'uid'        => (string) $uid,
        'name'       => $account['name'],
        'first_name' => array_key_exists('first_name', $account) ? $account['first_name'] : NULL,
        'last_name'  => array_key_exists('last_name', $account) ? $account['last_name'] : NULL,
      ];
    }
    myapi_test_db_seed(['users' => $rows]);
  }

  /**
   * Both profile fields present: "nombre apellidos", with a single space.
   */
  public function testUserNameIsFirstPlusLastWhenBothArePresent() {
    $this->seedUsers([3 => ['first_name' => 'Priscila', 'last_name' => 'Cordero', 'name' => 'pcordero']]);

    $this->assertSame(['3' => 'Priscila Cordero'], myapi_unit_fetch_user_names(['3']));
  }

  /**
   * Either half missing or empty falls back to the FULL users.name — never to
   * a hybrid like "pcordero Cordero" (SPEC 08's decision, and the reason the
   * condition is an AND over four checks).
   */
  public function testEveryIncompleteProfileFallsBackToTheUsername() {
    $cases = [
      'first name NULL'   => ['first_name' => NULL, 'last_name' => 'Cordero'],
      'last name NULL'    => ['first_name' => 'Priscila', 'last_name' => NULL],
      'first name empty'  => ['first_name' => '', 'last_name' => 'Cordero'],
      'last name empty'   => ['first_name' => 'Priscila', 'last_name' => ''],
      'both NULL'         => ['first_name' => NULL, 'last_name' => NULL],
      'both empty'        => ['first_name' => '', 'last_name' => ''],
    ];

    foreach ($cases as $label => $account) {
      $this->seedUsers([3 => $account + ['name' => 'pcordero']]);

      $this->assertSame(['3' => 'pcordero'], myapi_unit_fetch_user_names(['3']), $label);
    }
  }

  /**
   * Pinned, not fixed: emptiness is judged with a strict !== '' and no trim,
   * so a profile holding a single space produces "  Cordero" (two spaces) —
   * the concatenation, not the fallback. Consistent with SPEC 73's finding in
   * myapi_request_require_strings(), where length is measured without trimming
   * too. If this is ever changed, it is a product decision and this case is
   * where it gets rewritten.
   */
  public function testWhitespaceOnlyProfileValueIsNotTreatedAsEmpty() {
    $this->seedUsers([3 => ['first_name' => ' ', 'last_name' => 'Cordero', 'name' => 'pcordero']]);

    $this->assertSame(['3' => '  Cordero'], myapi_unit_fetch_user_names(['3']));
  }

  /**
   * Accents and multi-word surnames are passed through untouched: the name is
   * printed on the app's screen exactly as the profile holds it.
   */
  public function testNamesAreNotTransformed() {
    $this->seedUsers([3 => ['first_name' => 'José Andrés', 'last_name' => 'Núñez de la Vega', 'name' => 'jnunez']]);

    $this->assertSame(['3' => 'José Andrés Núñez de la Vega'], myapi_unit_fetch_user_names(['3']));
  }

  /**
   * Owners and occupants are resolved in ONE query over the union of uids —
   * SPEC 09's stated reason for renaming the function from ..._owner_names().
   * The case also mixes a complete profile with an incomplete one, to show the
   * fallback is decided per row and not per query.
   */
  public function testSeveralUidsAreResolvedInOneQuery() {
    $this->seedUsers([
      3 => ['first_name' => 'Priscila', 'last_name' => 'Cordero', 'name' => 'pcordero'],
      7 => ['first_name' => NULL, 'last_name' => NULL, 'name' => 'jperez'],
    ]);

    $names = myapi_unit_fetch_user_names(['3', '7']);

    $this->assertSame(['3' => 'Priscila Cordero', '7' => 'jperez'], $names);
    $this->assertCount(1, myapi_test_db_queries());
  }

  /**
   * A uid with no row — a deleted account still referenced by the field — is
   * absent from the map, which the builder turns into a NULL name.
   */
  public function testMissingAccountIsAbsentFromTheMap() {
    $this->seedUsers([3 => ['first_name' => 'Priscila', 'last_name' => 'Cordero', 'name' => 'pcordero']]);

    $names = myapi_unit_fetch_user_names(['3', '99']);

    $this->assertArrayHasKey('3', $names);
    $this->assertArrayNotHasKey('99', $names);
  }

  /**
   * The empty early return, same reason as the other two.
   */
  public function testUserNamesSkipTheQueryForAnEmptyInput() {
    $this->assertSame([], myapi_unit_fetch_user_names([]));
    $this->assertSame([], myapi_test_db_queries());
  }

  /**
   * The query shape: users filtered by uid, with both profile fields joined
   * under entity_type = 'user' — the scope that keeps a NODE's field_nombre
   * (there is one) from being read as a person's first name.
   */
  public function testUserNamesQueryScopesTheJoinsToTheUserEntity() {
    myapi_unit_fetch_user_names(['3']);
    $query = myapi_test_db_queries()[0];

    $this->assertSame('users', $query['table']);
    $this->assertTrue($this->hasCondition($query, 'u.uid', ['3'], 'IN'));

    foreach (['field_data_field_nombre', 'field_data_field_apellidos'] as $table) {
      $condition = $this->joinCondition($query, $table);
      $this->assertStringContainsString("entity_type = 'user'", $condition, $table);
      $this->assertStringContainsString('deleted = 0', $condition, $table);
    }
  }

}
