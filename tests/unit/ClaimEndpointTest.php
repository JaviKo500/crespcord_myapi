<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../includes/myapi.claims_common.inc';
require_once __DIR__ . '/../../resources/claim.resource.inc';

/**
 * End-to-end unit tests for GET /api/v1/claims and GET /api/v1/claims/%
 * (SPECS 64/69, covered by SPEC 77).
 *
 * myapi_claim_dispatch() is called the way hook_menu() calls it, over fixture
 * tables and a fixture Authorization header, and what gets asserted is the JSON
 * body the module prints and the status code it sets.
 *
 * The class exists for ONE rule above all: the visibility condition of
 * myapi_claim_base_query() — a claim is readable when its condominium is one of
 * the reader's AND (it is public OR the reader filed it). That single db_or()
 * is what stands between a resident and the private claims of their
 * neighbours, it is written in exactly one place, and until this spec it had no
 * test in any layer. Every one of its four combinations has a case below, and
 * so does the consequence the spec states out loud: node.uid — whoever typed
 * the form — plays no part in it.
 *
 * What this canNOT prove is the SQL. The fixture query builder applies the
 * conditions over the columns a row carries and records the joins without
 * resolving them (see the SPEC 74/77 blocks in bootstrap.php), so "the INNER
 * JOIN on field_visibility drops a claim with no visibility row" is asserted
 * here as behaviour of the fixture, not of MySQL. The queries this class pins —
 * their number, their order, their tables and their tags — are the seam where
 * this layer hands over to tests/integration.
 */
class ClaimEndpointTest extends TestCase {

  const TOKEN = 'a-valid-access-token';

  /**
   * The reader of every fixture request, and their condominium.
   */
  const UID = 3;
  const CONDO = 12;

  protected function setUp(): void {
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');

    myapi_test_db_seed();
    $GLOBALS['myapi_test_users'] = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $_GET = [];
  }

  protected function tearDown(): void {
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $GLOBALS['myapi_test_users'] = [];
    myapi_test_db_seed();
    $_GET = [];
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  private function tokenRow(array $overrides = []) {
    return $overrides + [
      'id'                => '1',
      'uid'               => (string) self::UID,
      'access_token_hash' => myapi_token_hash(self::TOKEN),
      'revoked'           => '0',
      'access_expires_at' => REQUEST_TIME + 1800,
    ];
  }

  /**
   * One claim row, in the shape the fetch projects it.
   *
   * Three keys are written QUALIFIED ('n.nid', 'fs.field_status_value',
   * 'frd.field_reception_date_value') because their projection alias collides
   * with a real column of `node`: the query selects field_data_field_status as
   * 'status' while n.status is the published flag, and a flat fixture row
   * cannot hold both. See the projection rules in bootstrap.php.
   */
  private function claim(array $values = []) {
    return $values + [
      // node itself.
      'n.nid'       => 140,
      'type'        => 'reclamo',
      'status'      => 1,
      'created'     => mktime(16, 45, 0, 8, 4, 2026),
      'subject'     => 'Fuga en el pasillo',
      'description' => 'Hay agua desde el martes.',
      // Access: condominium + visibility + requester.
      'field_condominium_target_id' => self::CONDO,
      'condominium_id'              => self::CONDO,
      'condominium_name'            => 'Edificio El Sáuco',
      'field_visibility_value'      => 'public',
      'visibility'                  => 'public',
      'field_requester_target_id'   => self::UID,
      'requester_id'                => self::UID,
      // Catalogue columns.
      'fs.field_status_value'          => 'received',
      'field_claim_type_value'         => 'claim',
      'claim_type'                     => 'claim',
      'frd.field_reception_date_value' => '2026-08-04 16:45:00',
      // The attachment, joined from file_managed.
      'attachment_fid'      => NULL,
      'attachment_filename' => NULL,
    ];
  }

  /**
   * Seeds the reader's unit and condominium, the token, and the given claims.
   */
  private function seed(array $claims = [], array $tables = [], $uid = self::UID, array $condos = [self::CONDO]) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][$uid] = ['uid' => $uid, 'name' => 'user' . $uid, 'status' => 1];

    $condominium_rows = [];
    foreach ($condos as $condo) {
      $condominium_rows[] = [
        'entity_id' => '45', 'entity_type' => 'node', 'deleted' => '0',
        'field_condominio_target_id' => (string) $condo,
      ];
    }

    myapi_test_db_seed([
      'my_api_tokens' => [$this->tokenRow(['uid' => (string) $uid])],
      'field_data_field_propietario' => [
        ['entity_id' => '45', 'field_propietario_target_id' => (string) $uid, 'deleted' => '0'],
      ],
      'field_data_field_condominio' => $condominium_rows,
      'node' => $claims,
    ] + $tables);
  }

  /**
   * A reader with a token but no unit at all.
   */
  private function seedReaderWithoutUnits() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'user3', 'status' => 1];

    myapi_test_db_seed(['my_api_tokens' => [$this->tokenRow()]]);
  }

  private function request($id = NULL) {
    return myapi_test_capture(function () use ($id) {
      myapi_claim_dispatch($id);
    });
  }

  private function claims(array $result) {
    return $result['json']['data']['claims'];
  }

  private function ids(array $result) {
    return array_column($this->claims($result), 'id');
  }

  /* -------------------------------------------------------------------------
   * Method routing (SPEC 64/66/67).
   * ---------------------------------------------------------------------- */

  /**
   * PUT and DELETE are 405 on both routes, and — the part worth asserting —
   * before any authentication: the method is wrong whoever is asking, which is
   * what that docblock promises.
   */
  public function testPutAndDeleteAre405WithoutATokenOnBothRoutes() {
    foreach (['PUT', 'DELETE'] as $method) {
      foreach ([NULL, '140'] as $id) {
        $_SERVER['REQUEST_METHOD'] = $method;

        $result = $this->request($id);

        $this->assertSame(405, $result['status'], $method);
        $this->assertSame('method_not_allowed', $result['json']['error_code'], $method);
        $this->assertSame('Método no permitido.', $result['json']['error'], $method);
        $this->assertSame([], myapi_test_db_queries(), $method . ': no query');
      }
    }
  }

  /**
   * GET with no id is the list, GET with an id is the detail: proven by the
   * different error each one answers for the same unit-less reader — an empty
   * 200 list versus a 404.
   */
  public function testGetRoutesToListOrDetailByThePresenceOfAnId() {
    $this->seedReaderWithoutUnits();
    $list = $this->request();

    $this->seedReaderWithoutUnits();
    $detail = $this->request('140');

    $this->assertSame(200, $list['status']);
    $this->assertArrayHasKey('claims', $list['json']['data']);
    $this->assertSame(404, $detail['status']);
    $this->assertSame('claim_not_found', $detail['json']['error_code']);
  }

  /**
   * A lowercase verb is still a GET (myapi_request_method() upper-cases it).
   */
  public function testLowercaseGetIsAccepted() {
    $_SERVER['REQUEST_METHOD'] = 'get';
    $this->seed([$this->claim()]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
  }

  /* -------------------------------------------------------------------------
   * The access token guard.
   * ---------------------------------------------------------------------- */

  public function testMissingAuthorizationHeaderIs401AndTouchesNoTable() {
    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
    $this->assertSame([], myapi_test_db_queries());
  }

  public function testMalformedAuthorizationHeaderIs401() {
    foreach (['Token abc', 'Bearer', 'Bearer ', 'abc', 'Bearer a b'] as $header) {
      $_SERVER['HTTP_AUTHORIZATION'] = $header;

      $result = $this->request();

      $this->assertSame(401, $result['status'], $header);
      $this->assertSame('missing_authorization', $result['json']['error_code'], $header);
    }
  }

  public function testUnknownRevokedAndExpiredTokensAre401() {
    $cases = [
      'unknown' => function () {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer some-other-token';
        myapi_test_db_seed(['my_api_tokens' => [$this->tokenRow()]]);
      },
      'revoked' => function () {
        $this->seed([], [], self::UID);
        myapi_test_db_seed(['my_api_tokens' => [$this->tokenRow(['revoked' => '1'])]]);
      },
      'expired' => function () {
        $this->seed([], [], self::UID);
        myapi_test_db_seed(['my_api_tokens' => [$this->tokenRow(['access_expires_at' => REQUEST_TIME - 1])]]);
      },
    ];

    foreach ($cases as $name => $seed) {
      $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
      $seed();

      $result = $this->request();

      $this->assertSame(401, $result['status'], $name);
      $this->assertSame('invalid_token', $result['json']['error_code'], $name);
    }
  }

  public function testTokenOfADeletedOrBlockedUserIs401() {
    $this->seed([$this->claim()]);
    $GLOBALS['myapi_test_users'] = [];
    $deleted = $this->request();

    $this->seed([$this->claim()]);
    $GLOBALS['myapi_test_users'][self::UID]['status'] = 0;
    $blocked = $this->request();

    $this->assertSame(401, $deleted['status']);
    $this->assertSame('invalid_token', $deleted['json']['error_code']);
    $this->assertSame(401, $blocked['status']);
    $this->assertSame('invalid_token', $blocked['json']['error_code']);
  }

  /**
   * The detail refuses a token exactly like the list: not one of its 404s is
   * reachable without one.
   */
  public function testDetailAlsoRequiresAToken() {
    $result = $this->request('140');

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
  }

  /* -------------------------------------------------------------------------
   * THE VISIBILITY RULE (SPEC 64) — the reason this class exists.
   * ---------------------------------------------------------------------- */

  /**
   * A PUBLIC claim of a neighbour, in the reader's condominium: visible. The
   * neighbour filed it, the reader did not, and it still comes back — this is
   * the 'public' half of the OR.
   */
  public function testAPublicClaimOfANeighbourIsVisible() {
    $this->seed([$this->claim([
      'field_visibility_value'    => 'public',
      'visibility'                => 'public',
      'field_requester_target_id' => 99,
      'requester_id'              => 99,
    ])]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame([140], $this->ids($result));
  }

  /**
   * A PRIVATE claim of a neighbour: invisible. The single most important case
   * of the endpoint — this is what the db_or() is for, and the one whose
   * failure would publish everybody's private claims to their whole building.
   */
  public function testAPrivateClaimOfANeighbourIsInvisible() {
    $this->seed([$this->claim([
      'field_visibility_value'    => 'private',
      'visibility'                => 'private',
      'field_requester_target_id' => 99,
      'requester_id'              => 99,
    ])]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $this->claims($result));
    $this->assertSame(0, $result['json']['data']['pagination']['total']);
  }

  /**
   * The reader's OWN private claim: visible. The other half of the OR.
   */
  public function testTheReadersOwnPrivateClaimIsVisible() {
    $this->seed([$this->claim([
      'field_visibility_value'    => 'private',
      'visibility'                => 'private',
      'field_requester_target_id' => self::UID,
      'requester_id'              => self::UID,
    ])]);

    $result = $this->request();

    $this->assertSame([140], $this->ids($result));
  }

  /**
   * A claim of ANOTHER condominium is invisible even when it is public: the
   * condominium filter comes first and applies to everything.
   */
  public function testAPublicClaimOfAnotherCondominiumIsInvisible() {
    $this->seed([$this->claim([
      'field_condominium_target_id' => 30,
      'condominium_id'              => 30,
    ])]);

    $result = $this->request();

    $this->assertSame([], $this->claims($result));
  }

  /**
   * And so is the reader's OWN claim once it hangs from a condominium they no
   * longer belong to — the consequence SPEC 64 states out loud: a resident who
   * leaves stops seeing even the private claims they filed there.
   */
  public function testTheReadersOwnClaimInAForeignCondominiumIsInvisible() {
    $this->seed([$this->claim([
      'field_condominium_target_id' => 30,
      'condominium_id'              => 30,
      'field_visibility_value'      => 'private',
      'visibility'                  => 'private',
    ])]);

    $result = $this->request();

    $this->assertSame([], $this->claims($result));
  }

  /**
   * node.uid is NOT part of the rule: a private claim TYPED by the reader (they
   * are its author) but filed FOR somebody else (field_requester is the
   * neighbour's) stays invisible to them. The scenario is the administrator
   * filing on a resident's behalf, and it is the one case a naive "my claims"
   * implementation gets wrong.
   */
  public function testAuthorshipDoesNotGrantVisibility() {
    $this->seed([$this->claim([
      'uid'                       => self::UID,
      'field_visibility_value'    => 'private',
      'visibility'                => 'private',
      'field_requester_target_id' => 99,
      'requester_id'              => 99,
    ])]);

    $result = $this->request();

    $this->assertSame([], $this->claims($result));
  }

  /**
   * An unpublished claim never travels, whoever filed it.
   */
  public function testAnUnpublishedClaimIsInvisible() {
    $this->seed([$this->claim(['status' => 0])]);

    $result = $this->request();

    $this->assertSame([], $this->claims($result));
  }

  /**
   * Another bundle sharing the same field tables is not a claim.
   */
  public function testANodeOfAnotherTypeIsNotListed() {
    $this->seed([$this->claim(['type' => 'boletin'])]);

    $result = $this->request();

    $this->assertSame([], $this->claims($result));
  }

  /**
   * The rule is written ONCE: the detail answers 404 for the very claim the
   * list refuses to show, without a permission check of its own.
   */
  public function testTheDetailAppliesTheSameRuleAndAnswers404() {
    $private_of_a_neighbour = $this->claim([
      'field_visibility_value'    => 'private',
      'visibility'                => 'private',
      'field_requester_target_id' => 99,
      'requester_id'              => 99,
    ]);

    $this->seed([$private_of_a_neighbour]);

    $result = $this->request('140');

    $this->assertSame(404, $result['status']);
    $this->assertSame('claim_not_found', $result['json']['error_code']);
  }

  /**
   * And the count is built from the same query as the page: a claim the reader
   * may not see is not counted either, so 'total' can never announce rows the
   * list does not carry.
   */
  public function testTheTotalCountsOnlyVisibleClaims() {
    $this->seed([
      $this->claim(['n.nid' => 140, 'id' => 140]),
      $this->claim([
        'n.nid' => 141, 'id' => 141,
        'field_visibility_value' => 'private', 'visibility' => 'private',
        'field_requester_target_id' => 99, 'requester_id' => 99,
      ]),
    ]);

    $result = $this->request();

    $this->assertSame(1, $result['json']['data']['pagination']['total']);
    $this->assertSame([140], $this->ids($result));
  }

  /**
   * The visibility rule is built as ONE db_or() group, not as two independent
   * conditions — which would AND them and hide every public claim of a
   * neighbour. Asserted on the recorded query shape, because this is the one
   * structural fact of the endpoint that a behavioural test could also satisfy
   * by accident.
   */
  public function testTheVisibilityRuleIsASingleOrGroup() {
    $this->seed([$this->claim()]);

    $this->request();

    $node_query = myapi_test_db_queries('node')[0];
    $groups = array_values(array_filter($node_query['conditions'], function ($condition) {
      return $condition['operator'] === 'GROUP';
    }));

    $this->assertCount(1, $groups, 'exactly one condition group');
    $this->assertSame('OR', $groups[0]['group']->conjunction());
    $this->assertSame(
      ['fv.field_visibility_value', 'fr.field_requester_target_id'],
      array_column($groups[0]['group']->conditions(), 'field')
    );
  }

  /**
   * The claims query carries NO ->addTag('node_access'), which is what the
   * @file docblock says and what makes this endpoint's rule the explicit one
   * above rather than Drupal's grants. Pinned so a future spec adding the tag
   * has to change this test on purpose.
   */
  public function testTheClaimsQueryCarriesNoNodeAccessTag() {
    $this->seed([$this->claim()]);

    $this->request();

    foreach (myapi_test_db_queries('node') as $query) {
      $this->assertSame([], $query['tags']);
    }
  }

  /* -------------------------------------------------------------------------
   * The empty answers.
   * ---------------------------------------------------------------------- */

  /**
   * A reader with no unit gets 200 with an empty list and total 0 — not a 403.
   * "You have nothing" is not "you may not".
   */
  public function testAReaderWithoutUnitsGetsAnEmptyList() {
    $this->seedReaderWithoutUnits();

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame([
      'success' => TRUE,
      'data'    => [
        'claims'     => [],
        'pagination' => ['total' => 0, 'page' => 1, 'limit' => 20, 'total_pages' => 0],
      ],
    ], $result['json']);
  }

  /**
   * And it short-circuits BEFORE the claims query: with no condominium there is
   * no "IN ()" to build, which is invalid SQL in Drupal 7 — the reason the
   * early return exists at all.
   */
  public function testAReaderWithoutUnitsRunsNoClaimQuery() {
    $this->seedReaderWithoutUnits();

    $this->request();

    $this->assertSame([
      'my_api_tokens',
      'field_data_field_propietario',
      'field_data_field_ocupante',
      'field_data_field_ocupantes',
    ], array_column(myapi_test_db_queries(), 'table'));
  }

  /**
   * Same short-circuit on the detail, where it answers the uniform 404.
   */
  public function testTheDetailOfAReaderWithoutUnitsIs404() {
    $this->seedReaderWithoutUnits();

    $result = $this->request('140');

    $this->assertSame(404, $result['status']);
    $this->assertSame('claim_not_found', $result['json']['error_code']);
    $this->assertSame([], myapi_test_db_queries('node'));
  }

  /**
   * A reader with a condominium but no claims: 200, empty list, and no images
   * or transactions query — both short-circuit on an empty nid list for the
   * same "IN ()" reason.
   */
  public function testAnEmptyPageLoadsNeitherImagesNorTransactions() {
    $this->seed([]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $this->claims($result));
    $this->assertSame([], myapi_test_db_queries('field_data_field_images'));
  }

  /* -------------------------------------------------------------------------
   * The item (SPEC 64 — the serialised shape).
   * ---------------------------------------------------------------------- */

  /**
   * The full item, compared whole and with types: the contract the app codes
   * against. Ids are ints, transactions are collapsed to ids by default, and
   * images is an array and never null.
   */
  public function testTheListItemHasTheDocumentedShape() {
    $this->seed([$this->claim()], [
      'field_data_field_images' => [
        ['entity_id' => 140, 'entity_type' => 'node', 'deleted' => 0, 'delta' => 0, 'fid' => '7', 'filename' => 'fuga.jpg'],
      ],
    ]);

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame([
      'id'               => 140,
      'subject'          => 'Fuga en el pasillo',
      'description'      => 'Hay agua desde el martes.',
      'status'           => 'received',
      'claim_type'       => 'claim',
      'visibility'       => 'public',
      'reception_date'   => '2026-08-04T16:45:00',
      'created'          => '2026-08-04T16:45:00',
      'condominium_id'   => 12,
      'condominium_name' => 'Edificio El Sáuco',
      'requester_id'     => 3,
      'images'           => [[
        'id'       => 7,
        'url'      => 'https://crespcord.example.com/api/v1/claims/140/files/7',
        'filename' => 'fuga.jpg',
      ]],
      'attachment'       => NULL,
      'transactions'     => [],
    ], $this->claims($result)[0]);
  }

  /**
   * reception_date travels as the STORED naive local time with a 'T' in it —
   * never through strtotime(). field_reception_date has tz_handling 'none', so
   * converting it would answer an hour nobody typed.
   */
  public function testReceptionDateIsTheStoredValueWithATAndNoConversion() {
    $this->seed([$this->claim(['frd.field_reception_date_value' => '2026-01-31 23:50:00'])]);

    $result = $this->request();

    $this->assertSame('2026-01-31T23:50:00', $this->claims($result)[0]['reception_date']);
  }

  /**
   * A claim received before SPEC 63 stores a midnight time and comes out with
   * it — correct, not a bug.
   */
  public function testAPreSpec63ClaimKeepsItsMidnightTime() {
    $this->seed([$this->claim(['frd.field_reception_date_value' => '2026-01-05 00:00:00'])]);

    $result = $this->request();

    $this->assertSame('2026-01-05T00:00:00', $this->claims($result)[0]['reception_date']);
  }

  /**
   * A claim with no reception date answers null, not the epoch.
   */
  public function testAClaimWithNoReceptionDateAnswersNull() {
    $this->seed([$this->claim(['frd.field_reception_date_value' => NULL])]);

    $result = $this->request();

    $this->assertNull($this->claims($result)[0]['reception_date']);
  }

  /**
   * The attachment is a file object with the authenticated URL of SPEC 65 —
   * the endpoint's own route, never the file's location on disk.
   */
  public function testTheAttachmentCarriesTheAuthenticatedUrl() {
    $this->seed([$this->claim([
      'attachment_fid'      => '31',
      'attachment_filename' => 'presupuesto.pdf',
    ])]);

    $result = $this->request();

    $this->assertSame([
      'id'       => 31,
      'url'      => 'https://crespcord.example.com/api/v1/claims/140/files/31',
      'filename' => 'presupuesto.pdf',
    ], $this->claims($result)[0]['attachment']);
    $this->assertStringNotContainsString('sites/default/files', $result['output']);
  }

  /**
   * Every id in the payload is a JSON integer, not the string the database
   * hands back: a Dart client comparing 140 to "140" fails silently.
   */
  public function testIdsTravelAsIntegers() {
    $this->seed([$this->claim([
      'n.nid' => '140', 'id' => '140',
      'condominium_id' => '12', 'requester_id' => '3',
      'attachment_fid' => '31', 'attachment_filename' => 'p.pdf',
    ])]);

    $result = $this->request();
    $claim = $this->claims($result)[0];

    $this->assertSame(140, $claim['id']);
    $this->assertSame(12, $claim['condominium_id']);
    $this->assertSame(3, $claim['requester_id']);
    $this->assertSame(31, $claim['attachment']['id']);
  }

  /**
   * A claim with no requester answers null and not 0 — the back office may
   * legitimately leave field_requester empty.
   */
  public function testAClaimWithNoRequesterAnswersNull() {
    $this->seed([$this->claim([
      'field_visibility_value'    => 'public',
      'field_requester_target_id' => NULL,
      'requester_id'              => NULL,
    ])]);

    $result = $this->request();

    $this->assertNull($this->claims($result)[0]['requester_id']);
  }

  /* -------------------------------------------------------------------------
   * Transactions (collapsed / expanded).
   * ---------------------------------------------------------------------- */

  /**
   * Seeds two transactions of claim 140, oldest last, so the ASC ordering has
   * to move them.
   */
  private function transactionRows() {
    return [
      [
        // Ids as the database hands them back — strings — so the casts of
        // myapi_claim_load_transactions() are exercised and not assumed.
        'id' => '15', 'type' => 'claim_transaction', 'status' => 1, 'created' => mktime(9, 30, 0, 8, 5, 2026),
        'field_claim_target_id' => '140', 'claim_id' => '140',
        'fsd.field_status_date_value' => '2026-08-05 09:30:00',
        'fs.field_status_value' => 'in_progress',
        'comment' => 'Se coordinó la visita',
        'attachment_fid' => NULL, 'attachment_filename' => NULL,
      ],
      [
        'id' => '12', 'type' => 'claim_transaction', 'status' => 1, 'created' => mktime(16, 46, 0, 8, 4, 2026),
        'field_claim_target_id' => '140', 'claim_id' => '140',
        'fsd.field_status_date_value' => '2026-08-04 16:46:00',
        'fs.field_status_value' => 'received',
        'comment' => 'Recibimos tu reclamo.',
        'attachment_fid' => NULL, 'attachment_filename' => NULL,
      ],
    ];
  }

  /**
   * By default the list collapses transactions to plain ints — and they come
   * out oldest-first, which is the timeline order, not the order the fixture
   * wrote them in.
   */
  public function testTheListCollapsesTransactionsToIdsInTimelineOrder() {
    $this->seed(array_merge([$this->claim()], $this->transactionRows()));

    $result = $this->request();

    $this->assertSame([12, 15], $this->claims($result)[0]['transactions']);
  }

  /**
   * '?include=transactions' expands them into full objects, with the same key
   * name — the client never has to look at which key arrived.
   */
  public function testIncludeTransactionsExpandsThem() {
    $this->seed(array_merge([$this->claim()], $this->transactionRows()));
    $_GET['include'] = 'transactions';

    $result = $this->request();

    $this->assertSame([
      [
        'id'          => 12,
        'status'      => 'received',
        'status_date' => '2026-08-04T16:46:00',
        'comment'     => 'Recibimos tu reclamo.',
        'created'     => '2026-08-04T16:46:00',
        'images'      => [],
        'attachment'  => NULL,
      ],
      [
        'id'          => 15,
        'status'      => 'in_progress',
        'status_date' => '2026-08-05T09:30:00',
        'comment'     => 'Se coordinó la visita',
        'created'     => '2026-08-05T09:30:00',
        'images'      => [],
        'attachment'  => NULL,
      ],
    ], $this->claims($result)[0]['transactions']);
  }

  /**
   * Anything other than the exact value leaves them collapsed, with no 422 —
   * including the plural-looking 'transactions,images' a future spec may
   * define but this one does not.
   */
  public function testAnyOtherIncludeValueLeavesThemCollapsed() {
    foreach (['', 'Transactions', 'transaction', 'transactions,images', 'images', '1'] as $value) {
      $this->seed(array_merge([$this->claim()], $this->transactionRows()));
      $_GET['include'] = $value;

      $result = $this->request();

      $this->assertSame(200, $result['status'], $value);
      $this->assertSame([12, 15], $this->claims($result)[0]['transactions'], $value);
    }
  }

  /**
   * The author of a transaction is deliberately NOT exposed: it is back-office
   * information. The expanded object has exactly seven keys and 'uid' is not
   * one of them.
   */
  public function testTheExpandedTransactionNeverExposesItsAuthor() {
    $rows = $this->transactionRows();
    $rows[0]['uid'] = 41;
    $rows[1]['uid'] = 41;
    $this->seed(array_merge([$this->claim()], $rows));
    $_GET['include'] = 'transactions';

    $result = $this->request();

    foreach ($this->claims($result)[0]['transactions'] as $transaction) {
      $this->assertSame(
        ['id', 'status', 'status_date', 'comment', 'created', 'images', 'attachment'],
        array_keys($transaction)
      );
    }
  }

  /**
   * An unpublished transaction is not in the timeline, exactly like the back
   * office's own.
   */
  public function testAnUnpublishedTransactionIsNotListed() {
    $rows = $this->transactionRows();
    $rows[0]['status'] = 0;
    $this->seed(array_merge([$this->claim()], $rows));

    $result = $this->request();

    $this->assertSame([12], $this->claims($result)[0]['transactions']);
  }

  /**
   * A transaction of ANOTHER claim never leaks into this one's timeline.
   */
  public function testTransactionsOfAnotherClaimAreNotListed() {
    $rows = $this->transactionRows();
    $rows[0]['field_claim_target_id'] = 999;
    $rows[0]['claim_id'] = 999;
    $this->seed(array_merge([$this->claim()], $rows));

    $result = $this->request();

    $this->assertSame([12], $this->claims($result)[0]['transactions']);
  }

  /**
   * The images of a TRANSACTION carry the CLAIM's nid in their URL, not the
   * transaction's: the claim is the unit of access, and /claims/15/files/9
   * would answer 404 for a file that is perfectly readable at
   * /claims/140/files/9.
   */
  public function testATransactionImageUrlCarriesTheClaimNid() {
    $this->seed(array_merge([$this->claim()], $this->transactionRows()), [
      'field_data_field_images' => [
        ['entity_id' => 15, 'entity_type' => 'node', 'deleted' => 0, 'delta' => 0, 'fid' => '9', 'filename' => 'visita.jpg'],
      ],
    ]);
    $_GET['include'] = 'transactions';

    $result = $this->request();
    $transactions = $this->claims($result)[0]['transactions'];

    $this->assertSame([], $transactions[0]['images']);
    $this->assertSame([[
      'id'       => 9,
      'url'      => 'https://crespcord.example.com/api/v1/claims/140/files/9',
      'filename' => 'visita.jpg',
    ]], $transactions[1]['images']);
  }

  /**
   * The detail ALWAYS expands, with no '?include' at all — the asymmetry with
   * the list is the point of the endpoint.
   */
  public function testTheDetailAlwaysExpandsTransactions() {
    $this->seed(array_merge([$this->claim()], $this->transactionRows()));

    $result = $this->request('140');

    $this->assertSame(200, $result['status']);
    $transactions = $result['json']['data']['claim']['transactions'];
    $this->assertCount(2, $transactions);
    $this->assertSame(12, $transactions[0]['id']);
    $this->assertSame('received', $transactions[0]['status']);
  }

  /* -------------------------------------------------------------------------
   * Filters.
   * ---------------------------------------------------------------------- */

  /**
   * Two claims to filter between: 140 (received, claim, 04/08) and 141
   * (resolved, requirement, 06/08).
   */
  private function seedTwoClaims() {
    $this->seed([
      $this->claim(),
      $this->claim([
        'n.nid' => 141, 'id' => 141,
        'fs.field_status_value' => 'resolved',
        'field_claim_type_value' => 'requirement', 'claim_type' => 'requirement',
        'frd.field_reception_date_value' => '2026-08-06 10:00:00',
      ]),
    ]);
  }

  public function testStatusFilterNarrowsTheList() {
    $this->seedTwoClaims();
    $_GET['status'] = 'resolved';

    $result = $this->request();

    $this->assertSame([141], $this->ids($result));
  }

  /**
   * '?status' is multi-value in this endpoint (SPEC 69): a comma-separated
   * list is an IN, not a literal.
   */
  public function testStatusAcceptsACommaSeparatedList() {
    $this->seedTwoClaims();
    $_GET['status'] = 'received,resolved';

    $result = $this->request();

    $this->assertSame([141, 140], $this->ids($result));
  }

  /**
   * A list with one invalid item keeps the valid ones and drops the rest —
   * the whitelist is applied item by item, never to the string as a whole.
   */
  public function testAnInvalidItemOfTheStatusListIsDropped() {
    $this->seedTwoClaims();
    $_GET['status'] = 'resolved,duplicated';

    $result = $this->request();

    $this->assertSame([141], $this->ids($result));
  }

  /**
   * A '?status' nobody recognises falls back to NO filter, never to an empty
   * list and never to a 422: a client with a bookmark from before SPEC 62
   * ('duplicated') still gets their claims.
   */
  public function testAnUnknownStatusFallsBackToNoFilter() {
    foreach (['duplicated', 'inventado', '', 'Received'] as $value) {
      $this->seedTwoClaims();
      $_GET['status'] = $value;

      $result = $this->request();

      $this->assertSame(200, $result['status'], $value);
      $this->assertSame([141, 140], $this->ids($result), $value);
    }
  }

  public function testClaimTypeFilterNarrowsTheList() {
    $this->seedTwoClaims();
    $_GET['claim_type'] = 'requirement';

    $result = $this->request();

    $this->assertSame([141], $this->ids($result));
  }

  public function testAnUnknownClaimTypeFallsBackToNoFilter() {
    $this->seedTwoClaims();
    $_GET['claim_type'] = 'queja';

    $result = $this->request();

    $this->assertSame([141, 140], $this->ids($result));
  }

  /**
   * Both date bounds are inclusive and compared by DAY: a claim received on
   * date_to at 10:00 is inside the range, which is what the SUBSTR is for.
   */
  public function testTheDateRangeIsInclusiveOnBothEnds() {
    $this->seedTwoClaims();
    $_GET['date_from'] = '2026-08-04';
    $_GET['date_to'] = '2026-08-06';

    $result = $this->request();

    $this->assertSame([141, 140], $this->ids($result));
  }

  public function testDateFromExcludesEarlierClaims() {
    $this->seedTwoClaims();
    $_GET['date_from'] = '2026-08-05';

    $result = $this->request();

    $this->assertSame([141], $this->ids($result));
  }

  public function testDateToExcludesLaterClaims() {
    $this->seedTwoClaims();
    $_GET['date_to'] = '2026-08-05';

    $result = $this->request();

    $this->assertSame([140], $this->ids($result));
  }

  /**
   * An impossible calendar date is not a filter: '2026-02-30' matches the
   * pattern and is not a date, so it is dropped and the list comes back whole.
   */
  public function testAnImpossibleDateIsIgnored() {
    $this->seedTwoClaims();
    $_GET['date_from'] = '2026-02-30';

    $result = $this->request();

    $this->assertSame([141, 140], $this->ids($result));
  }

  /**
   * An inverted range drops BOTH bounds — the endpoint answers as if no range
   * had been given rather than as if nothing matched.
   */
  public function testAnInvertedRangeDropsBothBounds() {
    $this->seedTwoClaims();
    $_GET['date_from'] = '2026-08-06';
    $_GET['date_to'] = '2026-08-04';

    $result = $this->request();

    $this->assertSame([141, 140], $this->ids($result));
  }

  /**
   * A date filter also drops a claim with NO reception date: the bound is
   * applied on the left-joined column, which is the desired reading of "show
   * me what came in during this range".
   */
  public function testADateFilterDropsAClaimWithoutAReceptionDate() {
    $this->seed([$this->claim(['frd.field_reception_date_value' => NULL])]);
    $_GET['date_from'] = '2026-01-01';

    $result = $this->request();

    $this->assertSame([], $this->claims($result));
  }

  /**
   * '?condominium_id' narrows within the reader's own set.
   */
  public function testCondominiumIdNarrowsWithinTheReadersSet() {
    $this->seed([
      $this->claim(),
      $this->claim([
        'n.nid' => 141, 'id' => 141,
        'field_condominium_target_id' => 30, 'condominium_id' => 30,
      ]),
    ], [], self::UID, [12, 30]);
    $_GET['condominium_id'] = '30';

    $result = $this->request();

    $this->assertSame([141], $this->ids($result));
  }

  /**
   * A condominium the reader does not belong to is the ONE place the lax
   * parsing stops: 403, and the request ends there — no claim query runs.
   */
  public function testAForeignCondominiumIdIs403() {
    $this->seed([$this->claim()]);
    $_GET['condominium_id'] = '77';

    $result = $this->request();

    $this->assertSame(403, $result['status']);
    $this->assertSame('condominium_access_denied', $result['json']['error_code']);
    $this->assertSame([], myapi_test_db_queries('node'));
  }

  /**
   * A non-existent condominium answers the same 403 as a foreign one, so the
   * response never confirms whether the nid exists.
   */
  public function testANonExistentCondominiumIdAnswersTheSame403() {
    $this->seed([$this->claim()]);
    $_GET['condominium_id'] = '999999';

    $result = $this->request();

    $this->assertSame(403, $result['status']);
    $this->assertSame('condominium_access_denied', $result['json']['error_code']);
  }

  /**
   * A malformed condominium_id is a client bug, not an access attempt: NO
   * filter and no 403 — the deliberate divergence from the bulletins endpoint.
   */
  public function testAMalformedCondominiumIdIsIgnoredWithoutA403() {
    foreach (['abc', '0', '-3', '1.5', ''] as $value) {
      $this->seed([$this->claim()]);
      $_GET['condominium_id'] = $value;

      $result = $this->request();

      $this->assertSame(200, $result['status'], var_export($value, TRUE));
      $this->assertSame([140], $this->ids($result), var_export($value, TRUE));
    }
  }

  /**
   * The detail ignores the query string entirely: a filter that would exclude
   * the claim does not turn its 200 into a 404.
   */
  public function testTheDetailIgnoresTheQueryString() {
    $this->seed([$this->claim()]);
    $_GET['status'] = 'resolved';
    $_GET['claim_type'] = 'requirement';
    $_GET['date_from'] = '2027-01-01';

    $result = $this->request('140');

    $this->assertSame(200, $result['status']);
    $this->assertSame(140, $result['json']['data']['claim']['id']);
  }

  /* -------------------------------------------------------------------------
   * Pagination and ordering.
   * ---------------------------------------------------------------------- */

  /**
   * Seeds $count claims, ids 140..140+$count-1, one per day from 01/08/2026.
   */
  private function seedManyClaims($count) {
    $claims = [];
    for ($i = 0; $i < $count; $i++) {
      $claims[] = $this->claim([
        'n.nid' => 140 + $i,
        'id'    => 140 + $i,
        'frd.field_reception_date_value' => sprintf('2026-08-%02d 10:00:00', $i + 1),
      ]);
    }
    $this->seed($claims);
  }

  /**
   * The default page is 20 rows, newest first.
   */
  public function testTheDefaultPageIsTwentyRowsNewestFirst() {
    $this->seedManyClaims(25);

    $result = $this->request();

    $pagination = $result['json']['data']['pagination'];
    $this->assertSame(25, $pagination['total']);
    $this->assertSame(1, $pagination['page']);
    $this->assertSame(20, $pagination['limit']);
    $this->assertSame(2, $pagination['total_pages']);
    $this->assertCount(20, $this->claims($result));
    $this->assertSame(164, $this->ids($result)[0], 'newest first');
  }

  public function testTheSecondPageCarriesTheRemainder() {
    $this->seedManyClaims(25);
    $_GET['page'] = '2';

    $result = $this->request();

    $this->assertCount(5, $this->claims($result));
    $this->assertSame(2, $result['json']['data']['pagination']['page']);
    $this->assertSame(144, $this->ids($result)[0]);
  }

  /**
   * A page past the end is 200 with an empty list, never a 404.
   */
  public function testAPagePastTheEndIsAnEmptyList() {
    $this->seedManyClaims(3);
    $_GET['page'] = '9';

    $result = $this->request();

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $this->claims($result));
    $this->assertSame(3, $result['json']['data']['pagination']['total']);
  }

  /**
   * '?limit=-1' returns everything on one page (SPEC 15), and forces page 1.
   */
  public function testLimitMinusOneReturnsEverythingOnOnePage() {
    $this->seedManyClaims(25);
    $_GET['limit'] = '-1';
    $_GET['page'] = '3';

    $result = $this->request();

    $this->assertCount(25, $this->claims($result));
    $this->assertSame(-1, $result['json']['data']['pagination']['limit']);
    $this->assertSame(1, $result['json']['data']['pagination']['page']);
    $this->assertSame(1, $result['json']['data']['pagination']['total_pages']);
  }

  /**
   * And with no rows at all, total_pages stays 0 rather than becoming 1.
   */
  public function testLimitMinusOneWithNoRowsAnswersZeroPages() {
    $this->seed([]);
    $_GET['limit'] = '-1';

    $result = $this->request();

    $this->assertSame(0, $result['json']['data']['pagination']['total_pages']);
  }

  /**
   * limit is clamped to 50 and floored at 1; garbage falls back to 20.
   */
  public function testLimitIsClampedAndGarbageFallsBackToTwenty() {
    $cases = ['80' => 50, '1' => 1, '0' => 20, 'abc' => 20, '-5' => 20, '' => 20];

    foreach ($cases as $sent => $expected) {
      $this->seed([$this->claim()]);
      $_GET['limit'] = (string) $sent;

      $result = $this->request();

      $this->assertSame($expected, $result['json']['data']['pagination']['limit'], (string) $sent);
    }
  }

  /**
   * A garbage page falls back to 1 instead of erroring.
   */
  public function testAGarbagePageFallsBackToOne() {
    foreach (['abc', '0', '-2', ''] as $value) {
      $this->seedManyClaims(3);
      $_GET['page'] = $value;

      $result = $this->request();

      $this->assertSame(1, $result['json']['data']['pagination']['page'], $value);
      $this->assertCount(3, $this->claims($result), $value);
    }
  }

  /**
   * '?sort=asc' flips the order to oldest-first; anything else is 'desc'.
   */
  public function testSortAscReversesTheOrderAndGarbageFallsBackToDesc() {
    $this->seedManyClaims(3);
    $_GET['sort'] = 'asc';
    $asc = $this->ids($this->request());

    $this->seedManyClaims(3);
    $_GET['sort'] = 'ASC';
    $garbage = $this->ids($this->request());

    $this->assertSame([140, 141, 142], $asc);
    $this->assertSame([142, 141, 140], $garbage, 'anything but the exact value is desc');
  }

  /**
   * Claims sharing a reception date are tie-broken by nid in the SAME
   * direction, which is what makes paging stable: without it a row can appear
   * on two pages or on none.
   */
  public function testTiedReceptionDatesAreBrokenByNidInTheSameDirection() {
    $tied = '2026-08-04 16:45:00';
    $this->seed([
      $this->claim(['n.nid' => 140, 'id' => 140, 'frd.field_reception_date_value' => $tied]),
      $this->claim(['n.nid' => 142, 'id' => 142, 'frd.field_reception_date_value' => $tied]),
      $this->claim(['n.nid' => 141, 'id' => 141, 'frd.field_reception_date_value' => $tied]),
    ]);

    $desc = $this->ids($this->request());

    $this->seed([
      $this->claim(['n.nid' => 140, 'id' => 140, 'frd.field_reception_date_value' => $tied]),
      $this->claim(['n.nid' => 142, 'id' => 142, 'frd.field_reception_date_value' => $tied]),
      $this->claim(['n.nid' => 141, 'id' => 141, 'frd.field_reception_date_value' => $tied]),
    ]);
    $_GET['sort'] = 'asc';
    $asc = $this->ids($this->request());

    $this->assertSame([142, 141, 140], $desc);
    $this->assertSame([140, 141, 142], $asc);
  }

  /* -------------------------------------------------------------------------
   * The detail's 404s and the query budget.
   * ---------------------------------------------------------------------- */

  /**
   * Every way of not being allowed to see a claim answers the same
   * 404 claim_not_found — a non-numeric id, a missing nid, an unpublished one,
   * one of another condominium and a private one of a neighbour are
   * indistinguishable in the response.
   */
  public function testEveryWayOfNotSeeingAClaimAnswersTheSame404() {
    $cases = [
      'non numeric' => ['id' => 'abc', 'claims' => [$this->claim()]],
      'zero'        => ['id' => '0', 'claims' => [$this->claim()]],
      'negative'    => ['id' => '-4', 'claims' => [$this->claim()]],
      'missing'     => ['id' => '999', 'claims' => [$this->claim()]],
      'unpublished' => ['id' => '140', 'claims' => [$this->claim(['status' => 0])]],
      'foreign condo' => ['id' => '140', 'claims' => [$this->claim(['field_condominium_target_id' => 30, 'condominium_id' => 30])]],
      'private of a neighbour' => ['id' => '140', 'claims' => [$this->claim([
        'field_visibility_value' => 'private', 'visibility' => 'private',
        'field_requester_target_id' => 99, 'requester_id' => 99,
      ])]],
    ];

    foreach ($cases as $name => $case) {
      $this->seed($case['claims']);

      $result = $this->request($case['id']);

      $this->assertSame(404, $result['status'], $name);
      $this->assertSame('claim_not_found', $result['json']['error_code'], $name);
      $this->assertSame('Reclamo no encontrado.', $result['json']['error'], $name);
    }
  }

  /**
   * A non-numeric id never reaches a query: it is rejected before the
   * condominium resolution, so no table is touched on its behalf.
   */
  public function testANonNumericDetailIdNeverReachesAQuery() {
    $this->seed([$this->claim()]);

    $this->request('abc');

    $this->assertSame(['my_api_tokens'], array_column(myapi_test_db_queries(), 'table'));
  }

  /**
   * The list costs a fixed number of queries whatever the page size: the token,
   * three for the reader's units, one for the condominiums, the count, the
   * page, the images and the transactions. None of them grows per row — the
   * N+1 the endpoint was written to avoid.
   */
  public function testTheListCostsTheSameNumberOfQueriesForOneRowAsForMany() {
    $this->seedManyClaims(1);
    $this->request();
    $one = array_column(myapi_test_db_queries(), 'table');

    $this->seedManyClaims(20);
    $this->request();
    $many = array_column(myapi_test_db_queries(), 'table');

    $this->assertSame($one, $many);
    $this->assertSame([
      'my_api_tokens',
      'field_data_field_propietario',
      'field_data_field_ocupante',
      'field_data_field_ocupantes',
      'field_data_field_condominio',
      'node',
      'node',
      'field_data_field_images',
      'node',
    ], $many);
  }

  /**
   * Expanding the transactions adds exactly ONE query — the images of the
   * transactions — and not one per transaction.
   */
  public function testExpandingTransactionsAddsExactlyOneQuery() {
    $this->seed(array_merge([$this->claim()], $this->transactionRows()));
    $this->request();
    $collapsed = count(myapi_test_db_queries());

    $this->seed(array_merge([$this->claim()], $this->transactionRows()));
    $_GET['include'] = 'transactions';
    $this->request();
    $expanded = count(myapi_test_db_queries());

    $this->assertSame($collapsed + 1, $expanded);
  }

  /**
   * The count query is the same set as the page: it drops the range, so
   * 'total' describes the filtered set and not the page being fetched.
   */
  public function testTheCountIgnoresPagination() {
    $this->seedManyClaims(25);
    $_GET['page'] = '2';

    $result = $this->request();

    $this->assertSame(25, $result['json']['data']['pagination']['total']);
    $count_query = array_values(array_filter(myapi_test_db_queries('node'), function ($query) {
      return $query['count'];
    }))[0];
    $this->assertNull($count_query['range']);
  }

}
