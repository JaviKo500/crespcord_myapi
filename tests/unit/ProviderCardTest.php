<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.text.inc';
require_once __DIR__ . '/../../includes/myapi.provider_card.inc';

/**
 * The provider CARD on its own (SPECS 83 and 89).
 *
 * includes/myapi.provider_card.inc is where myapi_provider_build_item() and
 * myapi_provider_categories_by_nid() live since a THIRD reader appeared for
 * them: the detail of a service request answers `assigned_provider` as a whole
 * card, and Rule 5 of CLAUDE.md forbids it reaching into
 * resources/provider.resource.inc for the builder. Its own suite because a
 * shared function tested only through its callers is a function nobody notices
 * has changed until one of them starts answering a different shape.
 *
 * The two moved functions keep their coverage where it already was —
 * ProviderListEndpointTest, ProviderDetailEndpointTest and
 * ProviderMineEndpointTest exercise them through the three endpoints that were
 * already building cards with them, and every one of those suites passed the
 * move with no assertion touched, which is the proof the move carried no
 * behaviour. What is pinned HERE is the third function, the one the move made
 * possible: myapi_provider_card_by_nid(), the loader for a caller that holds a
 * nid and nothing else.
 *
 * Its whole surface is four decisions:
 *
 *  - the eight keys come out of myapi_provider_build_item() and are never
 *    rebuilt, which is what keeps `assigned_provider` and an item of
 *    GET /api/v1/providers from ever drifting apart;
 *  - the categories cost a SECOND query and cannot ride in the first
 *    (field_categories is unlimited and would multiply the row);
 *  - the bundle and the published flag are conditions, and
 *  - the LICENCE IS NOT. That last one is the only rule of the file that is not
 *    inherited from SPEC 83, and it is the reason this loader exists instead of
 *    myapi_provider_fetch() being reused: an awarded provider whose licence
 *    lapsed is still the provider doing the job.
 */
class ProviderCardTest extends TestCase {

  const NID = 41;

  protected function setUp(): void {
    myapi_test_db_seed();
  }

  protected function tearDown(): void {
    myapi_test_db_seed();
  }

  /**
   * A published provider with everything a card is made of filled in.
   */
  private function provider(array $overrides = []) {
    return $overrides + [
      'nid'               => (string) self::NID,
      'type'              => MYAPI_SERVICES_PROVIDER_TYPE,
      'status'            => '1',
      'title'             => 'Plomería Rivas',
      'rating_avg'        => '4.80',
      'rating_count'      => '31',
      'short_description' => 'Plomería y gas, 24 h.',
      'hourly_rate'       => '25.50',
      'logo_uri'          => 'public://logos/rivas.png',
      // Read by NO query of this file: the licence is the marketplace's rule,
      // and a card is not the marketplace. Seeded so a case can expire it and
      // prove exactly that.
      'license_expiry'    => (string) (REQUEST_TIME + 86400),
    ];
  }

  /**
   * One row of field_data_field_categories, joined to its term — the same
   * fixture shape the provider listing's suite uses, and for the same reason:
   * 'fc.entity_id' is qualified because the query projects that column under
   * the alias `nid`.
   */
  private function categoryRow($tid, $name, $code = 'plumbing', $delta = 0) {
    return [
      'fc.entity_id'         => (string) self::NID,
      'entity_type'          => 'node',
      'deleted'              => '0',
      'delta'                => (string) $delta,
      'field_categories_tid' => (string) $tid,
      'tid'                  => (string) $tid,
      'name'                 => $name,
      'code'                 => $code,
    ];
  }

  private function seed(array $providers, array $categories = []) {
    myapi_test_db_seed([
      'node'                        => $providers,
      'field_data_field_categories' => $categories,
    ]);
  }

  /* -------------------------------------------------------------------------
   * The card itself.
   * ---------------------------------------------------------------------- */

  /**
   * THE EIGHT KEYS OF THE LISTING, IN THE LISTING'S ORDER AND WITH ITS TYPING:
   * the logo an absolute public URL, `rating_avg` a float, `rating_count` an
   * int, `hourly_rate` a float, the categories {id, code, name} in delta order.
   */
  public function testTheCardIsTheListingsItem() {
    $this->seed([$this->provider()], [
      $this->categoryRow(12, 'Plomería', 'plumbing', 0),
      $this->categoryRow(15, 'Gas', 'gas', 1),
    ]);

    $this->assertSame([
      'id'                => self::NID,
      'logo'              => file_create_url('public://logos/rivas.png'),
      'title'             => 'Plomería Rivas',
      'categories'        => [
        ['id' => 12, 'code' => 'plumbing', 'name' => 'Plomería'],
        ['id' => 15, 'code' => 'gas', 'name' => 'Gas'],
      ],
      'rating_avg'        => 4.8,
      'rating_count'      => 31,
      'short_description' => 'Plomería y gas, 24 h.',
      'hourly_rate'       => 25.5,
    ], myapi_provider_card_by_nid(self::NID));
  }

  /**
   * A provider with nothing optional filled in still has a card, and it is the
   * documented empty one: nulls where there is no value, `rating_count` 0 and
   * never null, and an empty LIST of categories.
   */
  public function testAProviderWithNothingOptionalStillHasACard() {
    $this->seed([$this->provider([
      'rating_avg'        => NULL,
      'rating_count'      => NULL,
      'short_description' => NULL,
      'hourly_rate'       => NULL,
      'logo_uri'          => NULL,
    ])]);

    $this->assertSame([
      'id'                => self::NID,
      'logo'              => NULL,
      'title'             => 'Plomería Rivas',
      'categories'        => [],
      'rating_avg'        => NULL,
      'rating_count'      => 0,
      'short_description' => '',
      'hourly_rate'       => NULL,
    ], myapi_provider_card_by_nid(self::NID));
  }

  /**
   * THE LICENCE IS NEVER LOOKED AT, and that is the decision this loader
   * exists for. A provider awarded a job last month whose licence lapsed last
   * week is still the provider doing that job: answering NULL would tell the
   * resident nobody was awarded, which is a lie about the state of their
   * request. The marketplace rule — only active providers are LISTED — is about
   * who may be hired, and the two must be able to diverge.
   */
  public function testALapsedLicenceStillAnswersACard() {
    $this->seed([$this->provider(['license_expiry' => (string) (REQUEST_TIME - 86400)])]);

    $card = myapi_provider_card_by_nid(self::NID);

    $this->assertSame(self::NID, $card['id']);

    foreach (myapi_test_db_queries() as $query) {
      $this->assertNotContains(
        'field_data_field_license_expiry',
        array_column($query['joins'], 'table'),
        'the licence table is never joined'
      );
    }
  }

  /**
   * A provider with NO licence row at all answers a card too — the same case
   * from the other side, and the one myapi_provider_apply_active_conditions()
   * drops with an INNER JOIN.
   */
  public function testAProviderWithNoLicenceAtAllStillAnswersACard() {
    $this->seed([$this->provider(['license_expiry' => NULL])]);

    $this->assertSame(self::NID, myapi_provider_card_by_nid(self::NID)['id']);
  }

  /* -------------------------------------------------------------------------
   * What answers NULL.
   * ---------------------------------------------------------------------- */

  /**
   * Unpublished, another bundle, or simply not there: one answer for the three,
   * and it is NULL and never half a card. `status = 1` matches the join the
   * caller already made — myapi_service_request_detail_row() resolves the award
   * through a published provider node — so the two agree if either is ever
   * called alone.
   */
  public function testUnpublishedForeignAndMissingAllAnswerNull() {
    $cases = [
      'unpublished'   => $this->provider(['status' => '0']),
      'another bundle' => $this->provider(['type' => MYAPI_SERVICES_REQUEST_TYPE]),
    ];

    foreach ($cases as $label => $row) {
      $this->seed([$row]);
      $this->assertNull(myapi_provider_card_by_nid(self::NID), $label);
    }

    $this->seed([]);
    $this->assertNull(myapi_provider_card_by_nid(self::NID), 'no such node');
  }

  /**
   * Anything that is not a positive integer answers NULL WITH NO QUERY AT ALL —
   * the guard every loader of this module opens with.
   */
  public function testANonPositiveIdCostsNoQuery() {
    $this->seed([$this->provider()]);

    foreach ([0, -3, '', 'abc', NULL, []] as $bad) {
      $this->assertNull(myapi_provider_card_by_nid($bad), var_export($bad, TRUE));
    }

    $this->assertSame([], myapi_test_db_queries());
  }

  /* -------------------------------------------------------------------------
   * The cost.
   * ---------------------------------------------------------------------- */

  /**
   * TWO QUERIES AND NOT ONE PER CATEGORY: the provider's row, then its
   * categories. The second cannot ride in the first — field_categories is
   * unlimited and the join would multiply the row — which is exactly why the
   * card of a whole PAGE is a different function (myapi_provider_fetch() plus
   * one grouped read) and why no listing may call this one.
   */
  public function testTheCardCostsExactlyTwoQueries() {
    $this->seed([$this->provider()], [
      $this->categoryRow(12, 'Plomería'),
      $this->categoryRow(15, 'Gas', 'gas', 1),
    ]);

    myapi_provider_card_by_nid(self::NID);

    $this->assertSame(
      ['node', 'field_data_field_categories'],
      array_column(myapi_test_db_queries(), 'table')
    );
  }

}
