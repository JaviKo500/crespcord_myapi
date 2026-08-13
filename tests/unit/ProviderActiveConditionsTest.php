<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.provider_query.inc';

/**
 * The SQL half of "an active provider", on its own (SPEC 83).
 *
 * myapi_provider_apply_active_conditions() is the ONE place the rule is written
 * in SQL, and both consumers — the category counts of SPEC 79 and the
 * marketplace listing of SPEC 83 — get it from here. Its own suite because a
 * shared function tested only through its callers is a function nobody notices
 * has changed until one of them starts answering different numbers.
 *
 * Two kinds of case:
 *
 *  - the SHAPE it adds to a query (the join, the two conditions, the alias it
 *    respects and everything it deliberately does NOT add), and
 *  - the ROWS it lets through, which is the rule itself: published, unexpired,
 *    and the >= boundary that decides whether a licence expiring today is still
 *    valid.
 *
 * The rows half is the twin of myapi_services_provider_is_active(), whose own
 * cases live in tests/unit/ServicesInstallTest.php. If these two ever disagree,
 * that is the divergence this include exists to prevent.
 */
class ProviderActiveConditionsTest extends TestCase {

  protected function setUp(): void {
    myapi_test_db_seed();
  }

  protected function tearDown(): void {
    myapi_test_db_seed();
  }

  /**
   * A published provider whose licence expires tomorrow.
   */
  private function provider($nid, array $overrides = []) {
    return $overrides + [
      'nid'                        => (string) $nid,
      'type'                       => MYAPI_SERVICES_PROVIDER_TYPE,
      'status'                     => '1',
      'field_license_expiry_value' => (string) (REQUEST_TIME + 86400),
    ];
  }

  /**
   * Runs a bare node query through the helper and answers the recorded query.
   */
  private function applied($node_alias = 'n') {
    $query = db_select('node', $node_alias);
    myapi_provider_apply_active_conditions($query, $node_alias);
    $query->execute();

    $queries = myapi_test_db_queries();

    return end($queries);
  }

  /**
   * The nids the helper lets through, over the seeded rows.
   */
  private function survivors(array $rows) {
    myapi_test_db_seed(['node' => $rows]);

    $query = db_select('node', 'n');
    $query->fields('n', ['nid']);
    $query->condition('n.type', MYAPI_SERVICES_PROVIDER_TYPE);
    myapi_provider_apply_active_conditions($query, 'n');

    $nids = [];
    foreach ($query->execute() as $row) {
      $nids[] = (int) $row->nid;
    }

    return $nids;
  }

  /* -------------------------------------------------------------------------
   * The shape it adds.
   * ---------------------------------------------------------------------- */

  /**
   * One INNER JOIN with the licence table — INNER and not LEFT, which is half
   * the rule: a provider with no licence row is dropped by the join, the same
   * answer myapi_services_provider_is_active() gives to a NULL expiry.
   */
  public function testItInnerJoinsTheLicenceTable() {
    $query = $this->applied();

    $this->assertSame(['field_data_field_license_expiry'], array_column($query['joins'], 'table'));
    $this->assertSame(['INNER'], array_column($query['joins'], 'type'));
    $this->assertSame('l', $query['joins'][0]['alias']);
    $this->assertSame(
      "l.entity_type = 'node' AND l.entity_id = n.nid AND l.deleted = 0",
      $query['joins'][0]['condition']
    );
  }

  /**
   * The two conditions, and the operator of each: published exactly, and the
   * licence compared with >= so it is valid THROUGHOUT its expiry timestamp.
   */
  public function testItAddsTheTwoActiveConditions() {
    $query = $this->applied();

    $conditions = [];
    foreach ($query['conditions'] as $condition) {
      $conditions[$condition['field']] = [$condition['value'], $condition['operator']];
    }

    $this->assertCount(2, $query['conditions']);
    $this->assertSame([1, '='], $conditions['n.status']);
    $this->assertSame([REQUEST_TIME, '>='], $conditions['l.field_license_expiry_value']);
  }

  /**
   * The comparison is >= and NOT >. Pinned on its own because a one-character
   * change here would make a provider vanish from the marketplace a day early
   * in one endpoint and not in the other, with nothing else failing.
   */
  public function testTheLicenceComparisonIsGreaterOrEqual() {
    $query = $this->applied();

    foreach ($query['conditions'] as $condition) {
      if ($condition['field'] === 'l.field_license_expiry_value') {
        $this->assertSame('>=', $condition['operator']);
        $this->assertNotSame('>', $condition['operator']);
      }
    }
  }

  /**
   * The node alias is a PARAMETER: with 'p', both the condition and the
   * correlation inside the join condition follow it. A shared function that
   * dictated how its caller names tables would break the second time somebody
   * used it.
   */
  public function testItRespectsTheNodeAliasItIsGiven() {
    $query = $this->applied('p');

    $this->assertSame(
      "l.entity_type = 'node' AND l.entity_id = p.nid AND l.deleted = 0",
      $query['joins'][0]['condition']
    );

    $fields = array_column($query['conditions'], 'field');
    $this->assertContains('p.status', $fields);
    $this->assertNotContains('n.status', $fields);
  }

  /**
   * And with no alias given at all, it defaults to 'n' — the alias every
   * consumer of the module happens to use.
   */
  public function testTheAliasDefaultsToN() {
    $query = db_select('node', 'n');
    myapi_provider_apply_active_conditions($query);
    $query->execute();

    $queries = myapi_test_db_queries();
    $recorded = end($queries);

    $this->assertContains('n.status', array_column($recorded['conditions'], 'field'));
  }

  /**
   * WHAT IT DOES NOT ADD: the bundle. "Active" is a rule about a provider's
   * standing, not about which nodes are providers, and each consumer states
   * `n.type` itself — the category counts reach the node table through
   * field_categories, the listing through node itself.
   */
  public function testItDoesNotDecideWhichNodesAreProviders() {
    $query = $this->applied();

    $this->assertNotContains('n.type', array_column($query['conditions'], 'field'));
    $this->assertSame([], $query['fields'], 'it selects nothing either');
    $this->assertSame([], $query['order']);
    $this->assertNull($query['range']);
  }

  /* -------------------------------------------------------------------------
   * The rows it lets through — the rule itself.
   * ---------------------------------------------------------------------- */

  /**
   * A published provider with a valid licence passes.
   */
  public function testAnActiveProviderPasses() {
    $this->assertSame([41], $this->survivors([$this->provider(41)]));
  }

  /**
   * An unpublished one does not: the operator suspended it by hand, and the
   * marketplace has to respect that.
   */
  public function testAnUnpublishedProviderIsExcluded() {
    $this->assertSame(
      [41],
      $this->survivors([$this->provider(41), $this->provider(42, ['status' => '0'])])
    );
  }

  /**
   * Neither does one whose licence expired.
   */
  public function testAnExpiredLicenceIsExcluded() {
    $this->assertSame(
      [41],
      $this->survivors([
        $this->provider(41),
        $this->provider(42, ['field_license_expiry_value' => (string) (REQUEST_TIME - 1)]),
      ])
    );
  }

  /**
   * The boundary, in both directions around it: expiring one second ago is
   * out, expiring exactly now is IN, expiring in a second is in. The >= drawn
   * as a line.
   */
  public function testTheBoundaryOfTheLicenceComparison() {
    $survivors = $this->survivors([
      $this->provider(41, ['field_license_expiry_value' => (string) (REQUEST_TIME - 1)]),
      $this->provider(42, ['field_license_expiry_value' => (string) REQUEST_TIME]),
      $this->provider(43, ['field_license_expiry_value' => (string) (REQUEST_TIME + 1)]),
    ]);

    $this->assertSame([42, 43], $survivors);
  }

  /**
   * Both halves must hold: a provider that is published but expired, and one
   * unexpired but unpublished, are both out. Only the one that is both passes.
   */
  public function testBothHalvesMustHold() {
    $survivors = $this->survivors([
      $this->provider(41, ['status' => '0', 'field_license_expiry_value' => (string) (REQUEST_TIME - 1)]),
      $this->provider(42, ['status' => '0']),
      $this->provider(43, ['field_license_expiry_value' => (string) (REQUEST_TIME - 1)]),
      $this->provider(44),
    ]);

    $this->assertSame([44], $survivors);
  }

  /**
   * A provider with NO licence value is out too. Production drops it with the
   * INNER JOIN; the fixture models the same fact as the NULL a LEFT JOIN would
   * answer, which no comparison accepts either — and which is the very case
   * myapi_services_provider_is_active() answers FALSE to.
   */
  public function testAProviderWithNoLicenceValueIsExcluded() {
    $this->assertSame(
      [41],
      $this->survivors([
        $this->provider(41),
        $this->provider(42, ['field_license_expiry_value' => NULL]),
      ])
    );
  }

  /**
   * And the two halves agree with the PHP half over the same values: whatever
   * the SQL lets through, myapi_services_provider_is_active() answers TRUE for.
   * The assertion that would fail the day one of them is changed alone.
   */
  public function testItAgreesWithThePhpHalfOverTheSameValues() {
    $cases = [
      [1, REQUEST_TIME + 86400],
      [1, REQUEST_TIME],
      [1, REQUEST_TIME - 1],
      [0, REQUEST_TIME + 86400],
      [0, REQUEST_TIME - 1],
      [1, NULL],
    ];

    foreach ($cases as $index => $case) {
      list($status, $expiry) = $case;

      $survivors = $this->survivors([
        $this->provider(41, [
          'status'                     => (string) $status,
          'field_license_expiry_value' => $expiry === NULL ? NULL : (string) $expiry,
        ]),
      ]);

      $this->assertSame(
        myapi_services_provider_is_active($status, $expiry, REQUEST_TIME),
        $survivors === [41],
        'case ' . $index . ': ' . json_encode($case)
      );
    }
  }

}
