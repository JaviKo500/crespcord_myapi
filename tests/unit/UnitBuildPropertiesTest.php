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
 * Unit tests for myapi_unit_build_properties() (SPECS 08/09/10, covered by
 * SPEC 74).
 *
 * The one function of the units endpoint that is pure: it receives the four
 * result sets already resolved (units, condominiums, occupant uids, user
 * names) and turns them into the exact body GET /api/v1/units answers. Every
 * rule the three specs wrote about the SHAPE of that body lives here and
 * nowhere else — the grouping by condominium, the drop of a unit whose parent
 * condominium is not visible, the NULL for each value that may be missing, and
 * the casts that decide whether the app reads a number or a string.
 *
 * The casts are the reason several cases exist at all. A Drupal result row
 * carries every column as a STRING: area_m2 arrives as "92.00", owner_uid as
 * "3", saldo_actual as "-3393.0000". If a cast is dropped, the API keeps
 * answering 200 with plausible-looking values and the Flutter app starts
 * comparing numbers as text — a failure with no server-side symptom.
 *
 * Deliberately NOT tested here: the four fetchers that produce the inputs
 * (UnitQueriesTest) and the endpoint that chains them (UnitEndpointTest).
 */
class UnitBuildPropertiesTest extends TestCase {

  /**
   * A row shaped like the ones myapi_unit_fetch_units() answers, with every
   * value as the string a database hands over.
   */
  private function unitRow(array $overrides = []) {
    return (object) ($overrides + [
      'nid'            => '45',
      'name'           => 'Depto. 4B',
      'category'       => 'departamento',
      'area_m2'        => '92.00',
      'condominio_nid' => '12',
      'owner_uid'      => '3',
      'saldo_actual'   => '-3393.0000',
    ]);
  }

  /**
   * A row shaped like the ones myapi_unit_fetch_condominiums() maps by nid.
   */
  private function condominium($title = 'Edificio El Sáuco', $payment_information = 'Banco Pichincha 2100...') {
    return (object) [
      'nid'                 => '12',
      'title'               => $title,
      'payment_information' => $payment_information,
    ];
  }

  /* -------------------------------------------------------------------------
   * The documented shape.
   * ---------------------------------------------------------------------- */

  /**
   * The whole body of the example in SPEC 10, compared as one value: this pins
   * the key set, the key ORDER and every cast at once. A field added or
   * renamed in the response breaks this case first.
   */
  public function testProducesTheDocumentedShape() {
    $properties = myapi_unit_build_properties(
      [$this->unitRow()],
      ['12' => $this->condominium()],
      ['45' => '7'],
      ['3' => 'Priscila Cordero', '7' => 'Juan Pérez']
    );

    $this->assertSame([
      [
        'id'                  => 12,
        'name'                => 'Edificio El Sáuco',
        'payment_information' => 'Banco Pichincha 2100...',
        'units'               => [
          [
            'id'              => 45,
            'name'            => 'Depto. 4B',
            'category'        => 'departamento',
            'area_m2'         => 92.0,
            'owner_uid'       => 3,
            'owner_name'      => 'Priscila Cordero',
            'occupant_uid'    => 7,
            'occupant_name'   => 'Juan Pérez',
            'current_balance' => -3393.0,
          ],
        ],
      ],
    ], $properties);
  }

  /**
   * No unit carries an aliquot field of any name — SPEC 08 left it out of
   * scope on purpose, and a later billing spec must not leak it in here by
   * copying the array.
   */
  public function testNoAliquotFieldIsExposed() {
    $properties = myapi_unit_build_properties(
      [$this->unitRow()],
      ['12' => $this->condominium()],
      [],
      []
    );

    $keys = array_keys($properties[0]['units'][0]);
    $this->assertSame(
      ['id', 'name', 'category', 'area_m2', 'owner_uid', 'owner_name', 'occupant_uid', 'occupant_name', 'current_balance'],
      $keys
    );
  }

  /* -------------------------------------------------------------------------
   * Grouping.
   * ---------------------------------------------------------------------- */

  /**
   * Two units of the same condominium come back nested under ONE property, in
   * the order the rows arrived.
   */
  public function testTwoUnitsOfTheSameCondominiumAreGroupedTogether() {
    $units = [
      $this->unitRow(['nid' => '45', 'name' => 'Depto. 4B']),
      $this->unitRow(['nid' => '46', 'name' => 'Depto. 5B']),
    ];

    $properties = myapi_unit_build_properties($units, ['12' => $this->condominium()], [], []);

    $this->assertCount(1, $properties);
    $this->assertSame([45, 46], array_column($properties[0]['units'], 'id'));
  }

  /**
   * Owner in one condominium, occupant in another: both appear, each with only
   * its own units (SPEC 08 acceptance criterion).
   */
  public function testUnitsOfDifferentCondominiumsAreKeptApart() {
    $units = [
      $this->unitRow(['nid' => '45', 'condominio_nid' => '12']),
      $this->unitRow(['nid' => '80', 'condominio_nid' => '30']),
    ];
    $condominiums = [
      '12' => $this->condominium('Edificio El Sáuco'),
      '30' => (object) ['nid' => '30', 'title' => 'Conjunto La Pradera', 'payment_information' => NULL],
    ];

    $properties = myapi_unit_build_properties($units, $condominiums, [], []);

    $this->assertCount(2, $properties);
    $this->assertSame([12, 30], array_column($properties, 'id'));
    $this->assertSame([45], array_column($properties[0]['units'], 'id'));
    $this->assertSame([80], array_column($properties[1]['units'], 'id'));
  }

  /**
   * The list is re-indexed with array_values(), so it encodes as a JSON ARRAY
   * even though it is built keyed by condominium nid. Without it the app would
   * receive an object keyed by "12" and its list parser would fail.
   */
  public function testPropertiesEncodeAsAJsonList() {
    $properties = myapi_unit_build_properties(
      [$this->unitRow(['condominio_nid' => '12'])],
      ['12' => $this->condominium()],
      [],
      []
    );

    $this->assertSame(0, array_keys($properties)[0]);
    $this->assertStringStartsWith('[{', drupal_json_encode($properties));
  }

  /* -------------------------------------------------------------------------
   * Units that must not appear.
   * ---------------------------------------------------------------------- */

  /**
   * A unit whose parent condominium is not in the map — it does not exist, or
   * it is unpublished — is dropped silently (SPEC 08 acceptance criterion).
   */
  public function testUnitWhoseCondominiumIsNotVisibleIsDropped() {
    $units = [
      $this->unitRow(['nid' => '45', 'condominio_nid' => '12']),
      $this->unitRow(['nid' => '99', 'condominio_nid' => '77']),
    ];

    $properties = myapi_unit_build_properties($units, ['12' => $this->condominium()], [], []);

    $this->assertCount(1, $properties);
    $this->assertSame([45], array_column($properties[0]['units'], 'id'));
  }

  /**
   * A unit with no field_condominio at all (LEFT JOIN gave NULL) is dropped
   * too: there is no property to nest it under.
   */
  public function testUnitWithoutCondominiumIsDropped() {
    $properties = myapi_unit_build_properties(
      [$this->unitRow(['condominio_nid' => NULL])],
      ['12' => $this->condominium()],
      [],
      []
    );

    $this->assertSame([], $properties);
  }

  /**
   * Every unit dropped means an empty LIST, not an empty object — the same
   * body a user with no units gets.
   */
  public function testAllUnitsDroppedGivesAnEmptyList() {
    $properties = myapi_unit_build_properties([$this->unitRow()], [], [], []);

    $this->assertSame([], $properties);
    $this->assertSame('[]', drupal_json_encode($properties));
  }

  /**
   * No units at all is the same, and reaches the same early shape without
   * touching the maps.
   */
  public function testNoUnitsGivesAnEmptyList() {
    $this->assertSame([], myapi_unit_build_properties([], ['12' => $this->condominium()], [], []));
  }

  /* -------------------------------------------------------------------------
   * Owner (SPECS 08/09).
   * ---------------------------------------------------------------------- */

  /**
   * A unit with no owner assigned: both owner fields are NULL and nothing
   * fails (SPEC 08 acceptance criterion).
   */
  public function testUnitWithoutOwnerHasNullOwnerFields() {
    $properties = myapi_unit_build_properties(
      [$this->unitRow(['owner_uid' => NULL])],
      ['12' => $this->condominium()],
      [],
      ['3' => 'Priscila Cordero']
    );

    $unit = $properties[0]['units'][0];
    $this->assertNull($unit['owner_uid']);
    $this->assertNull($unit['owner_name']);
  }

  /**
   * An owner uid the name map could not resolve — the account was deleted
   * between the two queries — keeps the uid and answers a NULL name instead of
   * an empty string or a PHP notice.
   */
  public function testUnresolvedOwnerNameIsNullButTheUidStays() {
    $properties = myapi_unit_build_properties(
      [$this->unitRow(['owner_uid' => '3'])],
      ['12' => $this->condominium()],
      [],
      []
    );

    $unit = $properties[0]['units'][0];
    $this->assertSame(3, $unit['owner_uid']);
    $this->assertNull($unit['owner_name']);
  }

  /* -------------------------------------------------------------------------
   * Occupant (SPEC 09).
   * ---------------------------------------------------------------------- */

  /**
   * A nid absent from the occupant map has no occupant: both fields NULL
   * (SPEC 09 acceptance criterion).
   */
  public function testUnitWithoutOccupantHasNullOccupantFields() {
    $properties = myapi_unit_build_properties(
      [$this->unitRow(['nid' => '45'])],
      ['12' => $this->condominium()],
      ['99' => '7'],
      ['7' => 'Juan Pérez']
    );

    $unit = $properties[0]['units'][0];
    $this->assertNull($unit['occupant_uid']);
    $this->assertNull($unit['occupant_name']);
  }

  /**
   * Same rule as the owner on the name side: the uid survives an unresolved
   * name.
   */
  public function testUnresolvedOccupantNameIsNullButTheUidStays() {
    $properties = myapi_unit_build_properties(
      [$this->unitRow()],
      ['12' => $this->condominium()],
      ['45' => '7'],
      []
    );

    $unit = $properties[0]['units'][0];
    $this->assertSame(7, $unit['occupant_uid']);
    $this->assertNull($unit['occupant_name']);
  }

  /**
   * The owner occupying their own unit: one entry of the name map feeds both
   * pairs of fields, which is what SPEC 09 bought by resolving owners and
   * occupants in a single query.
   */
  public function testOwnerWhoIsAlsoTheOccupantResolvesBothFromOneEntry() {
    $properties = myapi_unit_build_properties(
      [$this->unitRow(['owner_uid' => '3'])],
      ['12' => $this->condominium()],
      ['45' => '3'],
      ['3' => 'Priscila Cordero']
    );

    $unit = $properties[0]['units'][0];
    $this->assertSame(3, $unit['owner_uid']);
    $this->assertSame(3, $unit['occupant_uid']);
    $this->assertSame('Priscila Cordero', $unit['owner_name']);
    $this->assertSame('Priscila Cordero', $unit['occupant_name']);
  }

  /**
   * The occupant map is read per unit, not per response: two units of the same
   * condominium can have different occupants, and one of them none.
   */
  public function testEachUnitReadsItsOwnOccupant() {
    $units = [
      $this->unitRow(['nid' => '45']),
      $this->unitRow(['nid' => '46']),
      $this->unitRow(['nid' => '47']),
    ];

    $properties = myapi_unit_build_properties(
      $units,
      ['12' => $this->condominium()],
      ['45' => '7', '46' => '9'],
      ['7' => 'Juan Pérez', '9' => 'Ana Ruiz']
    );

    $this->assertSame([7, 9, NULL], array_column($properties[0]['units'], 'occupant_uid'));
    $this->assertSame(['Juan Pérez', 'Ana Ruiz', NULL], array_column($properties[0]['units'], 'occupant_name'));
  }

  /* -------------------------------------------------------------------------
   * area_m2 and current_balance (SPECS 08/10).
   * ---------------------------------------------------------------------- */

  /**
   * A unit with no field_total_m2 answers NULL, not 0.0 — "unknown area" and
   * "zero square metres" are different facts.
   */
  public function testMissingAreaIsNullAndNotZero() {
    $properties = myapi_unit_build_properties(
      [$this->unitRow(['area_m2' => NULL])],
      ['12' => $this->condominium()],
      [],
      []
    );

    $this->assertNull($properties[0]['units'][0]['area_m2']);
  }

  /**
   * A unit with no row in field_saldo_actual answers NULL (SPEC 10 acceptance
   * criterion).
   */
  public function testMissingBalanceIsNull() {
    $properties = myapi_unit_build_properties(
      [$this->unitRow(['saldo_actual' => NULL])],
      ['12' => $this->condominium()],
      [],
      []
    );

    $this->assertNull($properties[0]['units'][0]['current_balance']);
  }

  /**
   * The distinction the NULL check protects: a balance of exactly zero is a
   * real value and must NOT come back as NULL. A truthiness check instead of
   * the `!== NULL` would turn a settled account into "no balance".
   */
  public function testZeroBalanceIsZeroAndNotNull() {
    $properties = myapi_unit_build_properties(
      [$this->unitRow(['saldo_actual' => '0.0000'])],
      ['12' => $this->condominium()],
      [],
      []
    );

    $this->assertSame(0.0, $properties[0]['units'][0]['current_balance']);
  }

  /**
   * Same distinction for an area of zero.
   */
  public function testZeroAreaIsZeroAndNotNull() {
    $properties = myapi_unit_build_properties(
      [$this->unitRow(['area_m2' => '0.00'])],
      ['12' => $this->condominium()],
      [],
      []
    );

    $this->assertSame(0.0, $properties[0]['units'][0]['area_m2']);
  }

  /**
   * The sign is exposed as stored, with no normalisation (SPEC 10 decision:
   * no business meaning is assumed for it).
   */
  public function testBalanceSignIsExposedAsStored() {
    $cases = ['-3393.0000' => -3393.0, '1250.5000' => 1250.5];

    foreach ($cases as $stored => $expected) {
      $properties = myapi_unit_build_properties(
        [$this->unitRow(['saldo_actual' => (string) $stored])],
        ['12' => $this->condominium()],
        [],
        []
      );

      $this->assertSame($expected, $properties[0]['units'][0]['current_balance'], (string) $stored);
    }
  }

  /**
   * Decimals survive the float cast on both numeric fields: the app shows
   * 92.75 m² and a balance with cents, not truncated integers.
   */
  public function testDecimalsSurviveTheFloatCast() {
    $properties = myapi_unit_build_properties(
      [$this->unitRow(['area_m2' => '92.75', 'saldo_actual' => '-120.4500'])],
      ['12' => $this->condominium()],
      [],
      []
    );

    $unit = $properties[0]['units'][0];
    $this->assertSame(92.75, $unit['area_m2']);
    $this->assertSame(-120.45, $unit['current_balance']);
  }

  /* -------------------------------------------------------------------------
   * Condominium values and remaining passthroughs.
   * ---------------------------------------------------------------------- */

  /**
   * A condominium with no field_informacion_pago answers NULL for it, and the
   * property is still built.
   */
  public function testCondominiumWithoutPaymentInformationIsStillBuilt() {
    $properties = myapi_unit_build_properties(
      [$this->unitRow()],
      ['12' => (object) ['nid' => '12', 'title' => 'Edificio El Sáuco', 'payment_information' => NULL]],
      [],
      []
    );

    $this->assertSame('Edificio El Sáuco', $properties[0]['name']);
    $this->assertNull($properties[0]['payment_information']);
  }

  /**
   * The unit name and the category are passed through untouched — SPEC 08
   * decided the taxonomy term name is exposed verbatim, with no slug or
   * lowercasing.
   */
  public function testNameAndCategoryArePassedThroughVerbatim() {
    $properties = myapi_unit_build_properties(
      [$this->unitRow(['name' => 'Local Comercial N° 3', 'category' => 'Departamento'])],
      ['12' => $this->condominium()],
      [],
      []
    );

    $unit = $properties[0]['units'][0];
    $this->assertSame('Local Comercial N° 3', $unit['name']);
    $this->assertSame('Departamento', $unit['category']);
  }

  /**
   * A unit with neither name nor category (both LEFT JOINs empty) still comes
   * back, with NULLs: the endpoint never hides a unit for having an incomplete
   * node.
   */
  public function testUnitWithoutNameOrCategoryIsStillListed() {
    $properties = myapi_unit_build_properties(
      [$this->unitRow(['name' => NULL, 'category' => NULL])],
      ['12' => $this->condominium()],
      [],
      []
    );

    $unit = $properties[0]['units'][0];
    $this->assertSame(45, $unit['id']);
    $this->assertNull($unit['name']);
    $this->assertNull($unit['category']);
  }

  /**
   * Both ids are integers in the JSON, taken from the string columns of the
   * result row. The app keys its "change unit" screen on them.
   */
  public function testIdsAreIntegers() {
    $properties = myapi_unit_build_properties(
      [$this->unitRow(['nid' => '45', 'condominio_nid' => '12'])],
      ['12' => $this->condominium()],
      [],
      []
    );

    $this->assertIsInt($properties[0]['id']);
    $this->assertIsInt($properties[0]['units'][0]['id']);
    $this->assertIsInt($properties[0]['units'][0]['owner_uid']);
  }

}
