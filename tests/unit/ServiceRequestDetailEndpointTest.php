<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.provider_role.inc';
require_once __DIR__ . '/../../includes/myapi.building_admin.inc';
require_once __DIR__ . '/../../includes/myapi.service_request_files.inc';
require_once __DIR__ . '/../../includes/myapi.service_offer.inc';
require_once __DIR__ . '/../../includes/myapi.service_request_query.inc';
require_once __DIR__ . '/../../includes/myapi.service_request_detail.inc';
require_once __DIR__ . '/../../resources/service_request.resource.inc';
require_once __DIR__ . '/../../myapi.module';

/**
 * End-to-end unit tests for the service request detail (SPEC 89):
 * GET /api/v1/service-requests/% and GET /api/v1/service-requests/%/files/%,
 * plus the ownership and back-office rules of
 * includes/myapi.service_request_files.inc.
 *
 * The two dispatchers are called the way hook_menu() calls them, over a fixture
 * `node` table, fixture field tables, a fixture my_api_tokens row and a fixture
 * Authorization header. What gets asserted is the JSON body the module prints
 * and the status code it sets — the same bytes the Flutter app receives.
 *
 * WHY THIS SUITE IS WORTH ITS LENGTH: this is the first endpoint of the module
 * whose ANSWER DEPENDS ON WHO ASKS, and the first whose access rule has three
 * independent ways in. A wrong answer here is not a broken screen — it is the
 * flat number of a resident handed to every provider of a category, or a
 * competitor's price handed to the provider bidding against them, or (through
 * the file route) any private file of the site handed to anyone holding a token
 * and the patience to walk fids.
 *
 * THE FIXTURE ROWS ARE THE JOINED ROWS, the same rule as
 * ServiceRequestListEndpointTest: the query builder of tests/unit records joins
 * and never resolves them, so a request is seeded flat — its own node columns
 * plus the value each JOIN would have brought, under the ALIAS the query gives
 * it. Three columns are seeded QUALIFIED, and that is not decoration: `status`
 * (the request's, off field_data_field_request_status) would collide with
 * n.status, the published flag; the offer's own `status` collides with the
 * offer node's published flag the same way; and a flat row cannot carry the
 * same column twice.
 *
 * What this suite therefore does NOT prove, both being the database's half:
 *
 *  - that the INNER JOIN on taxonomy_term_data really drops an orphan tid, nor
 *    that a LEFT JOIN cannot multiply the row. Those are arguments about the
 *    schema and about SQL.
 *  - that the bytes reach the app: file_transfer() is a recorder that ends the
 *    request, so a green case says "these headers were asked for over this
 *    uri", never "the file was streamed".
 *
 * What it DOES prove is everything the module decides: the three access rules
 * and every one of their negatives, the 404/403 split, the provider's trim, the
 * ordering and its tie-break, the typing of every key, the query budget, and
 * the two structural guards SPEC 89 asked for by name.
 */
class ServiceRequestDetailEndpointTest extends TestCase {

  /**
   * The plaintext token every fixture request sends.
   */
  const TOKEN = 'a-valid-access-token';

  /**
   * The resident who created the request: field_requester, and the 'requester'
   * viewer of almost every case.
   */
  const UID = 42;

  /**
   * A provider operator: a user a 'provider' node points at through
   * field_provider_users. Holds no role in these fixtures on purpose — see
   * testTheRoleIsNotWhatMakesSomebodyAProvider().
   */
  const PROVIDER_UID = 99;

  /**
   * The 'provider' node PROVIDER_UID operates, and the one that bids.
   */
  const PROVIDER_NID = 9;

  /**
   * A second provider operator, with no provider node behind them.
   */
  const STRANGER_UID = 7;

  /**
   * The request under test, and its category.
   */
  const NID = 128;
  const CATEGORY = 12;

  /**
   * A fixed instant, so no date assertion depends on the clock.
   */
  const CREATED = 1786633953;

  protected function setUp(): void {
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');

    myapi_test_db_seed();
    myapi_test_node_seed();
    myapi_test_file_seed();
    // The provider_role helpers cache per uid; without this a case would read
    // the previous one's providers.
    myapi_test_static_reset();
    $GLOBALS['myapi_test_users'] = [];
    $GLOBALS['myapi_test_file_transfers'] = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  protected function tearDown(): void {
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $GLOBALS['myapi_test_users'] = [];
    $GLOBALS['myapi_test_file_transfers'] = [];
    myapi_test_db_seed();
    myapi_test_static_reset();
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  /**
   * A my_api_tokens row for the plaintext token above.
   */
  private function tokenRow($uid) {
    return [
      'id'                => '1',
      'uid'               => (string) $uid,
      'access_token_hash' => myapi_token_hash(self::TOKEN),
      'revoked'           => '0',
      'access_expires_at' => REQUEST_TIME + 1800,
    ];
  }

  /**
   * One service request, as every JOIN of the detail query delivers it.
   *
   * Published, of the right bundle, requested by UID, 'open', with a unit, a
   * condominium, an attachment and no award — so every case that is not about
   * one of those reads without noise.
   */
  private function request(array $overrides = []) {
    return $overrides + [
      'nid'                            => (string) self::NID,
      'type'                           => MYAPI_SERVICES_REQUEST_TYPE,
      // The node's published flag. The request's own status is the qualified
      // key below — see the class docblock.
      'status'                         => '1',
      'title'                          => 'Fuga en el calentador',
      'created'                        => (string) self::CREATED,
      // The technical author: an administrator who loaded it from the back
      // office. No query reads it — the criterion is field_requester.
      'uid'                            => '1',
      'fr.field_requester_target_id'   => (string) self::UID,
      'requester_uid'                  => (string) self::UID,
      'fcat.field_category_tid'        => (string) self::CATEGORY,
      'category_code'                  => 'plumbing',
      'category_name'                  => 'Plomería',
      'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_OPEN,
      'description'                    => "El calentador gotea.\nDesde el lunes.",
      'desired_start'                  => (string) (self::CREATED + 86400),
      'closed_at'                      => NULL,
      'unit_id'                        => '55',
      'unit_name'                      => 'A-301',
      'condominium_id'                 => '7',
      'condominium_name'               => 'Torres del Este',
      'attachment_fid'                 => '92',
      'attachment_filename'            => 'presupuesto.pdf',
      'assigned_offer_id'              => NULL,
      'assigned_offer_status'          => NULL,
      'assigned_provider_id'           => NULL,
      'assigned_provider_name'         => NULL,
      // The RAW target_ids the access rule reads. Empty by default: nothing
      // fills the award fields today.
      'assigned_offer_raw'             => NULL,
      'assigned_provider_raw'          => NULL,
    ];
  }

  /**
   * One offer of the request, as the offers query delivers it.
   *
   * The node it hangs from is a 'service_offer' by default; seeding it as a
   * 'service_transaction' is how the timeline cases reproduce the silent bug
   * the bundle condition exists to prevent (field_request is shared by the two
   * bundles since SPEC 77).
   */
  private function offer($nid, $created, $provider_nid, array $overrides = []) {
    return $overrides + [
      'fq.field_request_target_id'      => (string) self::NID,
      // The same column under its bare name, for the OTHER reader of this
      // table: myapi_provider_role_offered_request_ids() aliases
      // field_data_field_request as 'fr' — the "already offered" rule — while
      // the offers query and the count alias it as 'fq'. One fixture row, two
      // consumers, and a flat row carries the qualified form of one and the
      // bare form of the other.
      'field_request_target_id'         => (string) self::NID,
      'entity_type'                     => 'node',
      'deleted'                         => '0',
      // What the INNER JOIN to the offer node brings.
      'nid'                             => (string) $nid,
      'created'                         => (string) $created,
      'no.type'                         => MYAPI_SERVICES_OFFER_TYPE,
      'no.status'                       => '1',
      // The reference the provider's trim filters on, and the joined provider.
      'field_provider_target_id'        => (string) $provider_nid,
      'provider_id'                     => (string) $provider_nid,
      'provider_name'                   => 'Servicios Díaz',
      'provider_logo_uri'               => NULL,
      'amount'                          => NULL,
      'message'                         => 'Puedo pasar el jueves.',
      // Qualified: 'status' alone is the offer node's published flag.
      'fost.field_offer_status_value'   => 'sent',
    ];
  }

  /**
   * One 'service_transaction' of the request, as the timeline query delivers
   * it (SPEC 93).
   *
   * SEEDED INTO THE `node` TABLE, not into a field table: the timeline query is
   * the one read path of this resource whose BASE table is node — the bundle
   * condition is the first thing it says — and the fixture table is always the
   * base one. The request itself lives in that same table and is told apart by
   * exactly what tells them apart in SQL: `type`. That is not an accident of
   * the fixture, it is the guard under test.
   *
   * Qualified where an alias collides, bare where it does not, the same rule
   * the offer fixture follows: `status` alone is the node's published flag, so
   * the transaction's own status travels as 'frs.field_request_status_value';
   * `status_date`, `comment` and `created` collide with nothing and travel
   * under the alias the query gives them. 'n.nid' is seeded next to 'nid'
   * because the query projects it as `id` off the qualified source.
   *
   * @param int    $nid          The transaction node.
   * @param string $status_date  field_status_date as stored: 'Y-m-d H:i:s',
   *                             naive local time, no zone.
   */
  private function transaction($nid, $status_date, array $overrides = []) {
    return $overrides + [
      'n.nid'                          => (string) $nid,
      'nid'                            => (string) $nid,
      'type'                           => MYAPI_SERVICES_TRANSACTION_TYPE,
      // The node's published flag, not the transaction's status.
      'status'                         => '1',
      'created'                        => (string) self::CREATED,
      'fr.field_request_target_id'     => (string) self::NID,
      'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_OPEN,
      'status_date'                    => $status_date,
      'comment'                        => 'Hemos recibido su solicitud.',
    ];
  }

  /**
   * The recorded queries that are the timeline's, recognised by the very
   * condition that makes the timeline correct: the bundle.
   *
   * Counting queries over the `node` table would not do — the detail reads
   * that table for the request itself, and a provider's read reads it again
   * for the provider node.
   */
  private function timelineQueries() {
    return array_values(array_filter(myapi_test_db_queries(), function ($query) {
      if ($query['table'] !== 'node') {
        return FALSE;
      }
      foreach ($query['conditions'] as $condition) {
        if ($condition['field'] === 'n.type' && $condition['value'] === MYAPI_SERVICES_TRANSACTION_TYPE) {
          return TRUE;
        }
      }

      return FALSE;
    }));
  }

  /**
   * One row of field_data_field_images, joined to its file_managed row.
   */
  private function image($fid, $filename, $delta, $nid = self::NID) {
    return [
      'entity_id'   => (string) $nid,
      'entity_type' => 'node',
      'deleted'     => '0',
      'delta'       => (string) $delta,
      'fid'         => (string) $fid,
      'filename'    => $filename,
    ];
  }

  /**
   * One row of a file field, as myapi_service_request_file_request_nid() reads
   * it: the field row plus what its INNER JOIN to node brings.
   */
  private function fileOwnership($field, $fid, $nid, $type = MYAPI_SERVICES_REQUEST_TYPE) {
    return [
      'entity_id'     => (string) $nid,
      'entity_type'   => 'node',
      'deleted'       => '0',
      $field . '_fid' => (string) $fid,
      'nid'           => (string) $nid,
      'type'          => $type,
    ];
  }

  /**
   * The users row myapi_user_display_names() reads.
   */
  private function userRow($uid, $name, $first = NULL, $last = NULL) {
    return [
      'uid'        => (string) $uid,
      'name'       => $name,
      'first_name' => $first,
      'last_name'  => $last,
    ];
  }

  /**
   * The two rows that make PROVIDER_UID the operator of a provider node.
   *
   * @param int   $provider_nid  The provider node.
   * @param array $categories    Its field_categories tids.
   * @param int   $uid           The operator.
   */
  private function providerTables($provider_nid, array $categories, $uid = self::PROVIDER_UID) {
    return [
      'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [[
        'entity_id'   => (string) $provider_nid,
        'entity_type' => 'node',
        'deleted'     => '0',
        MYAPI_PROVIDER_USERS_FIELD . '_target_id' => (string) $uid,
      ]],
      'field_data_field_categories' => array_map(function ($tid) use ($provider_nid) {
        return [
          'entity_id'            => (string) $provider_nid,
          'entity_type'          => 'node',
          'deleted'              => '0',
          'field_categories_tid' => (string) $tid,
        ];
      }, $categories),
    ];
  }

  /**
   * The node row myapi_provider_role_any_provider_active() reads: published,
   * with a licence that has not expired.
   */
  private function providerNode($nid = self::PROVIDER_NID, $status = '1', $expiry = NULL) {
    return [
      'nid'            => (string) $nid,
      // The bundle, which no query of the provider_role include reads — it
      // filters by nid — but which the timeline query DOES: the fixture engine
      // skips a condition over a column the row does not carry, so a provider
      // node with no `type` would walk into `transactions` (SPEC 93).
      'type'           => MYAPI_SERVICES_PROVIDER_TYPE,
      'status'         => $status,
      'license_expiry' => $expiry === NULL ? (string) (REQUEST_TIME + 86400) : $expiry,
    ];
  }

  /**
   * Seeds a whole scenario in one call: every myapi_test_db_seed() replaces the
   * entire fixture, so nothing can be added afterwards.
   */
  private function seed(array $tables, $uid) {
    $GLOBALS['myapi_test_users'][$uid] = ['uid' => $uid, 'name' => 'user' . $uid, 'status' => 1];

    $tables += [
      'my_api_tokens' => [$this->tokenRow($uid)],
      'users'         => [$this->userRow(self::UID, 'aperez', 'Ana', 'Pérez')],
    ];

    myapi_test_db_seed($tables);
    myapi_test_static_reset();
  }

  private function authenticate() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
  }

  /**
   * Runs the detail the way hook_menu() does.
   */
  private function dispatch($nid = self::NID) {
    return myapi_test_capture(function () use ($nid) {
      myapi_service_request_item_dispatch($nid);
    });
  }

  /**
   * Runs the file route the way hook_menu() does.
   */
  private function dispatchFile($nid, $fid) {
    return myapi_test_capture(function () use ($nid, $fid) {
      myapi_service_request_file_dispatch($nid, $fid);
    });
  }

  /**
   * Authenticates, seeds and runs — what almost every case needs.
   */
  private function detailFor($uid, array $tables = []) {
    $this->authenticate();
    $this->seed($tables + ['node' => [$this->request()]], $uid);

    return $this->dispatch();
  }

  private function item(array $result) {
    return $result['json']['data']['service_request'];
  }

  private function queriedTables() {
    return array_column(myapi_test_db_queries(), 'table');
  }

  /**
   * The resource file with every comment stripped, so a structural guard can
   * assert what the CODE says without tripping over the docblocks that explain
   * it. Same helper, same reason, as ServiceRequestListEndpointTest: this
   * resource says 'node_access' several times on purpose.
   */
  private function codeWithoutComments($path = '/../../resources/service_request.resource.inc') {
    $source = file_get_contents(__DIR__ . $path);
    $code = '';

    foreach (token_get_all($source) as $token) {
      if (is_array($token)) {
        if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
          continue;
        }
        $code .= $token[1];
      }
      else {
        $code .= $token;
      }
    }

    return $code;
  }

  /**
   * One file split into one entry per top-level function, comments already
   * stripped. Lets a structural guard say "no READ path does X" instead of
   * "this file never says X", which is what SPEC 95 forced apart: the write
   * paths it added legitimately load entities, and the read paths still must
   * not.
   *
   * TAKES A PATH SINCE SPEC 106, which moved the six loaders and serialisers of
   * this detail out to includes/myapi.service_request_detail.inc. The guard
   * below reads BOTH files: leaving it pointed at the resource alone would have
   * kept it green while silently dropping the six functions it was written to
   * watch, which is the one way an extraction can move something without a
   * single assertion failing.
   *
   * @param string $path  Relative to this directory, like codeWithoutComments().
   *
   * @return array  Function name => its source, from its signature to the one
   *                before it.
   */
  private function functionBodies($path = '/../../resources/service_request.resource.inc') {
    $code = $this->codeWithoutComments($path);
    $parts = preg_split(
      '/^function\s+([a-z0-9_]+)\s*\(/mi',
      $code,
      -1,
      PREG_SPLIT_DELIM_CAPTURE
    );

    $bodies = [];
    // $parts[0] is whatever precedes the first function; the rest come in
    // (name, body) pairs.
    for ($i = 1; $i < count($parts); $i += 2) {
      $bodies[$parts[$i]] = $parts[$i + 1];
    }

    return $bodies;
  }

  /* -------------------------------------------------------------------------
   * Method routing, the nid, and authentication.
   * ---------------------------------------------------------------------- */

  /**
   * Everything that is not GET is 405 on BOTH routes, and the method is checked
   * BEFORE the token and before any query: a PUT with a valid token is still
   * 405, and one with no token at all is 405 too — never 401.
   *
   * POST ON THE DETAIL ROUTE IS THE ONE EXCEPTION, AND ONLY SINCE SPEC 96,
   * which made it the edit endpoint (myapi_service_request_update()) — POST and
   * not PUT because PHP fills neither $_POST nor $_FILES on a PUT and an edit
   * carries files. It is asserted by the test right below instead of here. On
   * the FILE route POST is still 405, like every other verb: that route only
   * ever serves a download.
   */
  public function testEveryMethodOtherThanGetIs405OnBothRoutes() {
    $methods = [
      'detail' => ['PUT', 'DELETE', 'PATCH'],
      'file'   => ['POST', 'PUT', 'DELETE', 'PATCH'],
    ];

    foreach ($methods as $route => $route_methods) {
      foreach ($route_methods as $method) {
        $this->seed(['node' => [$this->request()]], self::UID);
        $_SERVER['REQUEST_METHOD'] = $method;

        $result = $route === 'detail'
          ? $this->dispatch()
          : $this->dispatchFile(self::NID, 91);

        $label = $method . ' ' . $route;
        $this->assertSame(405, $result['status'], $label);
        $this->assertSame('method_not_allowed', $result['json']['error_code'], $label);
        $this->assertSame([], myapi_test_db_queries(), $label . ' costs no query');
      }
    }
  }

  /**
   * POST on the detail route is NOT 405 any more (SPEC 96): it reaches
   * myapi_service_request_update(), which authenticates like every write. With
   * no Authorization header that is a 401 and not a 405, which is what tells
   * the two apart from the outside — the read tests of this class care that the
   * verb stopped being rejected, not what the edit then does with it.
   */
  public function testPostOnTheDetailRouteIsNoLongerRejectedByTheMethod() {
    $this->seed(['node' => [$this->request()]], self::UID);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    unset($_SERVER['HTTP_AUTHORIZATION']);

    $result = $this->dispatch();

    $this->assertNotSame(405, $result['status']);
    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
  }

  /**
   * A wildcard that is not a positive integer is 404 and COSTS NO QUERY — not
   * even the token's. It is answered before authentication because the shape of
   * the URL is wrong whoever is asking, and saying so leaks nothing about what
   * exists.
   */
  public function testAMalformedNidIs404WithoutAnyQuery() {
    foreach (['abc', '0', '-3', '1.5', '', '12abc'] as $value) {
      $this->authenticate();
      $this->seed(['node' => [$this->request()]], self::UID);

      $result = $this->dispatch($value);

      $this->assertSame(404, $result['status'], var_export($value, TRUE));
      $this->assertSame('not_found', $result['json']['error_code'], var_export($value, TRUE));
      $this->assertSame([], myapi_test_db_queries(), var_export($value, TRUE) . ' costs no query');
    }
  }

  /**
   * No Authorization header is 401 missing_authorization on both routes, and an
   * unknown token is 401 invalid_token.
   */
  public function testTheTokenIsRequiredOnBothRoutes() {
    $this->seed(['node' => [$this->request()]], self::UID);

    $this->assertSame(401, $this->dispatch()['status']);
    $this->assertSame('missing_authorization', $this->dispatch()['json']['error_code']);
    $this->assertSame(401, $this->dispatchFile(self::NID, 91)['status']);
    $this->assertSame('missing_authorization', $this->dispatchFile(self::NID, 91)['json']['error_code']);

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-the-token';

    $this->assertSame('invalid_token', $this->dispatch()['json']['error_code']);
    $this->assertSame('invalid_token', $this->dispatchFile(self::NID, 91)['json']['error_code']);
  }

  /* -------------------------------------------------------------------------
   * 404 and 403 mean different things.
   * ---------------------------------------------------------------------- */

  /**
   * A nid that matches no row is 404: it does not exist, it is unpublished, or
   * it is of another bundle. The reader is told none of the three apart.
   */
  public function testAnUnknownRequestIs404() {
    $this->authenticate();
    $this->seed(['node' => []], self::UID);

    $result = $this->dispatch(999);

    $this->assertSame(404, $result['status']);
    $this->assertSame('not_found', $result['json']['error_code']);
  }

  /**
   * The same 404 for an unpublished request and for a node of another bundle,
   * even when the reader IS its requester: the two conditions live in the
   * shared base query, so the detail cannot answer what the listing hides.
   */
  public function testUnpublishedAndForeignBundlesAre404() {
    foreach ([['status' => '0'], ['type' => MYAPI_SERVICES_PROVIDER_TYPE], ['type' => 'reclamo']] as $override) {
      $this->authenticate();
      $this->seed(['node' => [$this->request($override)]], self::UID);

      $result = $this->dispatch();

      $this->assertSame(404, $result['status'], json_encode($override));
      $this->assertSame('not_found', $result['json']['error_code'], json_encode($override));
    }
  }

  /**
   * A request that EXISTS and that the reader may not read is 403, never 404 —
   * the whole point of the split. The nid is not a secret: a provider reached
   * it from a listing that handed it to them, and "this request no longer takes
   * offers" is actionable where "it does not exist" is a lie.
   */
  public function testAnExistingRequestTheReaderMayNotSeeIs403() {
    $result = $this->detailFor(self::STRANGER_UID);

    $this->assertSame(403, $result['status']);
    $this->assertSame('forbidden', $result['json']['error_code']);
    $this->assertFalse($result['json']['success']);
  }

  /* -------------------------------------------------------------------------
   * The shape of the response.
   * ---------------------------------------------------------------------- */

  /**
   * The full body of the requester's answer, compared whole and with types:
   * the contract the app codes against.
   */
  public function testTheRequesterGetsTheDocumentedShape() {
    $result = $this->detailFor(self::UID, [
      'field_data_field_images'  => [$this->image(91, 'fuga.jpg', 0)],
      'field_data_field_request' => [
        $this->offer(46, self::CREATED + 20, self::PROVIDER_NID, ['amount' => '95.50']),
        $this->offer(45, self::CREATED + 10, 7, [
          'provider_id'   => '7',
          'provider_name' => 'Plomería Rivas',
          'message'       => "Necesito ver la instalación.\nLuego doy precio.",
        ]),
      ],
    ]);

    $this->assertSame(200, $result['status']);
    $this->assertTrue($result['json']['success']);

    $expected = [
      'id'                => 128,
      'title'             => 'Fuga en el calentador',
      'description'       => "El calentador gotea.\nDesde el lunes.",
      'status'            => 'open',
      'category'          => ['id' => 12, 'code' => 'plumbing', 'name' => 'Plomería'],
      'unit'              => ['id' => 55, 'name' => 'A-301'],
      'offers_count'      => 2,
      'assigned_offer'    => NULL,
      'assigned_provider' => NULL,
      'created'           => format_date(self::CREATED, 'custom', 'Y-m-d\TH:i:s'),
      'desired_start'     => format_date(self::CREATED + 86400, 'custom', 'Y-m-d\TH:i:s'),
      'viewer'            => 'requester',
      'requester'         => ['id' => 42, 'name' => 'Ana Pérez'],
      'condominium'       => ['id' => 7, 'name' => 'Torres del Este'],
      'images'            => [[
        'id'       => 91,
        'url'      => url('api/v1/service-requests/128/files/91', ['absolute' => TRUE]),
        'filename' => 'fuga.jpg',
      ]],
      'attachment'        => [
        'id'       => 92,
        'url'      => url('api/v1/service-requests/128/files/92', ['absolute' => TRUE]),
        'filename' => 'presupuesto.pdf',
      ],
      'closed_at'         => NULL,
      // Fifteen keys per offer since SPEC 100, and the SIX FIRST ARE SPEC 89's,
      // unchanged and in their original order. This fixture seeds no quote
      // column, so the nine new keys answer exactly what an offer stored before
      // myapi_update_7035() answers: null everywhere, and false for
      // requires_visit — which is never null.
      'offers'            => [
        [
          'id'             => 46,
          'provider'       => ['id' => 9, 'name' => 'Servicios Díaz', 'logo' => NULL],
          'amount'         => 95.5,
          'message'        => 'Puedo pasar el jueves.',
          'status'         => 'sent',
          'created'        => format_date(self::CREATED + 20, 'custom', 'Y-m-d\TH:i:s'),
          'amount_type'    => NULL,
          'valid_until'    => NULL,
          'available_from' => NULL,
          'duration'       => NULL,
          'includes'       => NULL,
          'excludes'       => NULL,
          'tax_included'   => NULL,
          'warranty_days'  => NULL,
          'requires_visit' => FALSE,
        ],
        [
          'id'             => 45,
          'provider'       => ['id' => 7, 'name' => 'Plomería Rivas', 'logo' => NULL],
          'amount'         => NULL,
          'message'        => "Necesito ver la instalación.\nLuego doy precio.",
          'status'         => 'sent',
          'created'        => format_date(self::CREATED + 10, 'custom', 'Y-m-d\TH:i:s'),
          'amount_type'    => NULL,
          'valid_until'    => NULL,
          'available_from' => NULL,
          'duration'       => NULL,
          'includes'       => NULL,
          'excludes'       => NULL,
          'tax_included'   => NULL,
          'warranty_days'  => NULL,
          'requires_visit' => FALSE,
        ],
      ],
      // Empty here on purpose: this fixture seeds no transaction, and the key
      // is present anyway. The timeline has its own section below.
      'transactions' => [],
    ];

    $this->assertSame($expected, $this->item($result));
  }

  /**
   * The object under 'service_request' is an OBJECT and never a list, and it
   * carries exactly the nineteen documented keys IN ORDER — the listing's
   * eleven and then the eight the detail adds. Nothing appears or disappears
   * with the data: a request with nothing filled in answers the same nineteen.
   *
   * (SPEC 89's prose says "seventeen" in two places and then enumerates eight
   * new keys next to the listing's ten; SPEC 93 counts "eighteen" from that
   * same prose and adds exactly one, `transactions`. The enumeration is the
   * unambiguous half in both cases, and this is what the code answers.)
   */
  public function testTheNineteenKeysAreAlwaysThereAndInOrder() {
    $keys = [
      'id', 'title', 'description', 'status', 'category', 'unit',
      'offers_count', 'assigned_offer', 'assigned_provider', 'created',
      'desired_start', 'viewer', 'requester', 'condominium', 'images',
      'attachment', 'closed_at', 'offers', 'transactions',
    ];

    $full = $this->detailFor(self::UID, [
      'field_data_field_images'  => [$this->image(91, 'fuga.jpg', 0)],
      'field_data_field_request' => [$this->offer(46, self::CREATED, self::PROVIDER_NID)],
    ]);
    $this->assertSame($keys, array_keys($this->item($full)));

    // The same request stripped of everything optional.
    $this->authenticate();
    $this->seed(['node' => [$this->request([
      'unit_id'             => NULL,
      'unit_name'           => NULL,
      'attachment_fid'      => NULL,
      'attachment_filename' => NULL,
      'desired_start'       => NULL,
    ])]], self::UID);
    $bare = $this->dispatch();

    $this->assertSame($keys, array_keys($this->item($bare)));
    $this->assertStringContainsString('"service_request":{', $bare['output'], 'an object, not a list');
  }

  /**
   * THE ELEVEN FIRST KEYS ARE BYTE FOR BYTE THE LISTING'S, for the same
   * request. Not "equivalent": the detail calls
   * myapi_service_request_build_item(), so the assertion compares the detail's
   * own output with the listing's own output over the same fixture.
   *
   * They were ten until SPEC 91 moved `unit` into the shared serialiser. The
   * slice below is what pins that the two endpoints answer that key with the
   * same bytes — the reason the detail no longer resolves it on its own.
   */
  public function testTheElevenFirstKeysAreTheListingsByteForByte() {
    $tables = [
      'node'                     => [$this->request()],
      'field_data_field_request' => [$this->offer(46, self::CREATED, self::PROVIDER_NID)],
    ];

    $this->authenticate();
    $this->seed($tables, self::UID);
    $detail = $this->item($this->dispatch());

    $this->authenticate();
    $this->seed($tables, self::UID);
    $listing = myapi_test_capture('myapi_service_request_dispatch');
    $listed = $listing['json']['data']['service_requests'][0];

    $this->assertSame($listed, array_slice($detail, 0, 11, TRUE));
  }

  /**
   * The category carries the stable code beside the tid, for BOTH readers —
   * the provider's trim takes the unit and the offers, never the category, and
   * deciding whether to bid is exactly what a provider needs the category for.
   * A term with no field_category_code answers `code: ""` and keeps its
   * request, the same criterion the listing applies.
   */
  public function testTheCategoryCarriesTheStableCodeForBothReaders() {
    $requester = $this->item($this->detailFor(self::UID));
    $this->assertSame(
      ['id' => self::CATEGORY, 'code' => 'plumbing', 'name' => 'Plomería'],
      $requester['category']
    );

    $this->authenticate();
    $this->seed($this->providerScenario(), self::PROVIDER_UID);
    $provider = $this->item($this->dispatch());
    $this->assertSame('provider', $provider['viewer']);
    $this->assertSame($requester['category'], $provider['category']);

    $this->authenticate();
    $this->seed(['node' => [$this->request(['category_code' => NULL])]], self::UID);
    $no_code = $this->item($this->dispatch());
    $this->assertSame('', $no_code['category']['code']);
    $this->assertSame(self::CATEGORY, $no_code['category']['id']);
  }

  /**
   * Every id travels as a JSON integer and never as the string the database
   * hands back: a Flutter client comparing 128 to "128" fails silently.
   */
  public function testEveryIdIsAnInteger() {
    $item = $this->item($this->detailFor(self::UID, [
      'field_data_field_images'  => [$this->image(91, 'fuga.jpg', 0)],
      'field_data_field_request' => [$this->offer(46, self::CREATED, self::PROVIDER_NID)],
    ]));

    $this->assertIsInt($item['id']);
    $this->assertIsInt($item['requester']['id']);
    $this->assertIsInt($item['unit']['id']);
    $this->assertIsInt($item['condominium']['id']);
    $this->assertIsInt($item['category']['id']);
    $this->assertIsInt($item['offers_count']);
    $this->assertIsInt($item['images'][0]['id']);
    $this->assertIsInt($item['attachment']['id']);
    $this->assertIsInt($item['offers'][0]['id']);
    $this->assertIsInt($item['offers'][0]['provider']['id']);
  }

  /**
   * `images` and `offers` are ALWAYS arrays — empty, never null — while
   * `attachment`, `closed_at` and `unit` are whole nulls. A client that can
   * iterate the first two has nothing to branch on; a null in the last three IS
   * the answer.
   */
  public function testTheEmptyShapesAreArraysAndNulls() {
    $this->authenticate();
    $this->seed(['node' => [$this->request([
      'unit_id'             => NULL,
      'unit_name'           => NULL,
      'attachment_fid'      => NULL,
      'attachment_filename' => NULL,
    ])]], self::UID);

    $item = $this->item($this->dispatch());

    $this->assertSame([], $item['images']);
    $this->assertSame([], $item['offers']);
    $this->assertSame(0, $item['offers_count']);
    $this->assertNull($item['attachment']);
    $this->assertNull($item['closed_at']);
    $this->assertNull($item['unit']);
    $this->assertStringContainsString('"images":[]', $this->dispatch()['output']);
  }

  /**
   * closed_at is 'Y-m-d\TH:i:s' on a closed request and null on every other:
   * field_closed_at is a datestamp, like desired_start, so it gets format_date()
   * and never the raw column nor a 1970 nobody typed.
   */
  public function testClosedAtIsFormattedOnlyWhenItIsThere() {
    $this->authenticate();
    $this->seed(['node' => [$this->request([
      'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_CLOSED,
      'closed_at'                      => (string) (self::CREATED + 3600),
    ])]], self::UID);

    $this->assertSame(
      format_date(self::CREATED + 3600, 'custom', 'Y-m-d\TH:i:s'),
      $this->item($this->dispatch())['closed_at']
    );

    $this->assertNull($this->item($this->detailFor(self::UID))['closed_at']);
  }

  /**
   * unit.name is field_nombre_vivienda and NOT the 'vivienda' node title: the
   * title is an internal label, and the field is the name the resident knows
   * their flat by — the same value GET /api/v1/units answers as `name`.
   */
  public function testTheUnitNameIsTheFieldAndNotTheNodeTitle() {
    $this->assertStringContainsString(
      'field_data_field_nombre_vivienda',
      file_get_contents(__DIR__ . '/../../resources/service_request.resource.inc'),
      'the unit name is read from its own field'
    );

    $item = $this->item($this->detailFor(self::UID, [
      'node' => [$this->request(['unit_name' => 'A-301', 'title' => 'Vivienda interna 55'])],
    ]));

    $this->assertSame('A-301', $item['unit']['name']);
  }

  /**
   * requester.name is "nombre apellidos" when both are there and users.name
   * when either is missing — never a hybrid like "Ana" alone. The rule is
   * myapi_user_display_names()'s, shared with GET /api/v1/units.
   */
  public function testTheRequesterNameFollowsTheSharedRule() {
    $cases = [
      ['Ana', 'Pérez', 'Ana Pérez'],
      ['Ana', NULL, 'aperez'],
      [NULL, 'Pérez', 'aperez'],
      ['', 'Pérez', 'aperez'],
      [NULL, NULL, 'aperez'],
    ];

    foreach ($cases as $case) {
      list($first, $last, $expected) = $case;
      $this->authenticate();
      $this->seed([
        'node'  => [$this->request()],
        'users' => [$this->userRow(self::UID, 'aperez', $first, $last)],
      ], self::UID);

      $this->assertSame(
        ['id' => 42, 'name' => $expected],
        $this->item($this->dispatch())['requester'],
        json_encode($case)
      );
    }
  }

  /**
   * `requester` carries id and name and NOTHING else, for either reader: no
   * phone, no email, no dni. myapi_user_fetch_profile_fields() is never called
   * from this resource.
   */
  public function testTheRequesterCarriesNoContactDetails() {
    $item = $this->item($this->detailFor(self::UID));

    $this->assertSame(['id', 'name'], array_keys($item['requester']));
    $this->assertStringNotContainsString(
      'myapi_user_fetch_profile_fields',
      $this->codeWithoutComments()
    );
  }

  /* -------------------------------------------------------------------------
   * Access — the requester.
   * ---------------------------------------------------------------------- */

  /**
   * The creator reads their request in EVERY status, terminals included.
   */
  public function testTheRequesterReadsEveryStatus() {
    foreach (array_keys(myapi_services_request_statuses()) as $status) {
      $this->authenticate();
      $this->seed(['node' => [$this->request(['frs.field_request_status_value' => $status])]], self::UID);

      $result = $this->dispatch();

      $this->assertSame(200, $result['status'], $status);
      $this->assertSame('requester', $this->item($result)['viewer'], $status);
    }
  }

  /**
   * ...and reads it even while holding the 'proveedor' role over a provider of
   * ANOTHER category. The role narrows nothing here, which is the whole reason
   * no query of this file carries a node_access tag: the tag's alter is a
   * whitelist by category and would hide a resident's own request from them.
   */
  public function testTheRequesterIsNotNarrowedByBeingAProvider() {
    $tables = $this->providerTables(self::PROVIDER_NID, [77], self::UID) + [
      'node' => [$this->request(), $this->providerNode()],
    ];

    $result = $this->detailFor(self::UID, $tables);

    $this->assertSame(200, $result['status']);
    $this->assertSame('requester', $this->item($result)['viewer']);
    $this->assertSame(['id' => 55, 'name' => 'A-301'], $this->item($result)['unit']);
  }

  /**
   * The criterion is field_requester and NOT node.uid: a request an
   * administrator loaded from the back office, with field_requester pointing at
   * the reader, is theirs.
   */
  public function testAnAdministratorAuthoredRequestIsTheRequestersOwn() {
    $result = $this->detailFor(self::UID, [
      'node' => [$this->request(['uid' => '1'])],
    ]);

    $this->assertSame('requester', $this->item($result)['viewer']);
  }

  /* -------------------------------------------------------------------------
   * Access — the provider.
   * ---------------------------------------------------------------------- */

  /**
   * The scenario every provider case starts from: PROVIDER_UID operates
   * PROVIDER_NID, which is published, licensed and of the request's category.
   */
  private function providerScenario(array $requestOverrides = [], array $extra = [], array $categories = [self::CATEGORY], $providerNode = NULL) {
    $node = $providerNode === NULL ? $this->providerNode() : $providerNode;

    return $this->providerTables(self::PROVIDER_NID, $categories) + $extra + [
      'node' => [$this->request($requestOverrides), $node],
    ];
  }

  /**
   * An active provider of the matching category reads an 'open' request, and an
   * 'offered' one too: a request with offers is still awardable, so closing it
   * to newcomers the moment the first bid lands would reward whoever was fastest
   * and impoverish the round.
   */
  public function testAnEligibleProviderReadsOpenAndOfferedRequests() {
    foreach ([MYAPI_SERVICES_REQUEST_STATUS_OPEN, MYAPI_SERVICES_REQUEST_STATUS_OFFERED] as $status) {
      $result = $this->detailFor(self::PROVIDER_UID, $this->providerScenario([
        'frs.field_request_status_value' => $status,
      ]));

      $this->assertSame(200, $result['status'], $status);
      $this->assertSame('provider', $this->item($result)['viewer'], $status);
    }
  }

  /**
   * 'assigned', 'closed' and 'cancelled' are 403 even with a matching category:
   * there is nothing left for a new provider to do with them.
   */
  public function testAnEligibleProviderIsRefusedOnTerminalStatuses() {
    foreach ([
      MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,
      MYAPI_SERVICES_REQUEST_STATUS_CLOSED,
      MYAPI_SERVICES_REQUEST_STATUS_CANCELLED,
    ] as $status) {
      $result = $this->detailFor(self::PROVIDER_UID, $this->providerScenario([
        'frs.field_request_status_value' => $status,
      ]));

      $this->assertSame(403, $result['status'], $status);
    }
  }

  /**
   * 'direct' is 403 TOO, and this is the case that keeps the two policies apart:
   * myapi_provider_role_broadcast_statuses() — what the back office hides from a
   * provider — includes 'direct', and this rule must not. A request born with a
   * provider already chosen is exactly what "unawarded" excludes. Equalising the
   * two lists breaks this test, which is the point of it.
   */
  public function testDirectRequestsAreRefusedEvenThoughTheBackOfficeBroadcastsThem() {
    $this->assertContains(
      MYAPI_SERVICES_REQUEST_STATUS_DIRECT,
      myapi_provider_role_broadcast_statuses(),
      'precondition: the back office does broadcast direct requests'
    );

    $result = $this->detailFor(self::PROVIDER_UID, $this->providerScenario([
      'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_DIRECT,
    ]));

    $this->assertSame(403, $result['status']);
  }

  /**
   * An 'open' request whose award field is already filled in — an incoherent
   * datum nothing prevents today — is 403: the STATUS and BOTH keys are checked,
   * and they are read RAW, so an award pointing at an unpublished offer still
   * closes the request instead of reopening it to everyone.
   */
  public function testAnIncoherentlyAwardedRequestIsRefused() {
    // The award points at a FOREIGN provider, and since SPEC 98 that is not a
    // detail of the fixture: rule 2b reads this very column, so an award to
    // self::PROVIDER_NID would now be a legitimate 200 — "my job, in any
    // status" — and would stop testing what this test is about, which is that
    // an incoherent award still CLOSES a request to everybody else.
    foreach (['assigned_offer_raw' => '46', 'assigned_provider_raw' => '4242'] as $column => $value) {
      $result = $this->detailFor(self::PROVIDER_UID, $this->providerScenario([
        $column => $value,
        // The joined node is NULL: the reference points at something
        // unpublished or deleted. The rule must still read it as awarded.
        'assigned_offer_id'    => NULL,
        'assigned_provider_id' => NULL,
      ]));

      $this->assertSame(403, $result['status'], $column);
    }
  }

  /**
   * A provider of another category is 403.
   */
  public function testAProviderOfAnotherCategoryIsRefused() {
    $result = $this->detailFor(self::PROVIDER_UID, $this->providerScenario([], [], [77]));

    $this->assertSame(403, $result['status']);
  }

  /**
   * An unpublished provider node, or one whose licence expired, is 403:
   * myapi_services_provider_is_active() is applied for real. Suspending a
   * provider is exactly what taking them out of the marketplace means.
   */
  public function testASuspendedProviderIsRefused() {
    $cases = [
      'unpublished' => $this->providerNode(self::PROVIDER_NID, '0'),
      'expired'     => $this->providerNode(self::PROVIDER_NID, '1', (string) (REQUEST_TIME - 86400)),
    ];

    foreach ($cases as $label => $node) {
      $result = $this->detailFor(self::PROVIDER_UID, $this->providerScenario([], [], [self::CATEGORY], $node));

      $this->assertSame(403, $result['status'], $label);
    }
  }

  /**
   * A user holding the 'proveedor' ROLE with no provider node pointing at them
   * is 403, and does not blow up: what makes somebody a provider here is
   * field_provider_users, never the label on their account.
   */
  public function testTheRoleIsNotWhatMakesSomebodyAProvider() {
    $account = ['uid' => self::STRANGER_UID, 'name' => 'user7', 'status' => 1, 'roles' => [3 => MYAPI_PROVIDER_ROLE]];
    $GLOBALS['myapi_test_users'][self::STRANGER_UID] = $account;

    $result = $this->detailFor(self::STRANGER_UID, [
      'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [],
      'node' => [$this->request()],
    ]);

    $this->assertSame(403, $result['status']);
  }

  /**
   * A plain authenticated user who is neither the requester nor a provider is
   * 403.
   */
  public function testAnUnrelatedUserIsRefused() {
    $this->assertSame(403, $this->detailFor(self::STRANGER_UID)['status']);
  }

  /**
   * WHOEVER ALREADY OFFERED KEEPS THE DETAIL — in 'assigned', in 'closed', in
   * 'cancelled', with the award filled in, and even after the request's category
   * changed to one they do not attend. An offer with a 403 behind it is an offer
   * with no explanation.
   */
  public function testWhoeverAlreadyOfferedKeepsAccessWhateverHappens() {
    $statuses = [
      MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,
      MYAPI_SERVICES_REQUEST_STATUS_CLOSED,
      MYAPI_SERVICES_REQUEST_STATUS_CANCELLED,
    ];

    foreach ($statuses as $status) {
      $result = $this->detailFor(self::PROVIDER_UID, $this->providerScenario(
        [
          'frs.field_request_status_value' => $status,
          'assigned_offer_raw'             => '46',
          'assigned_provider_raw'          => '9',
        ],
        // The offer that grants the access, and the row the list reads.
        ['field_data_field_request' => [$this->offer(46, self::CREATED, self::PROVIDER_NID)]],
        // A category they do NOT attend, and an EXPIRED licence: rule 2 answers
        // before either is looked at.
        [77],
        $this->providerNode(self::PROVIDER_NID, '1', (string) (REQUEST_TIME - 86400))
      ));

      $this->assertSame(200, $result['status'], $status);
      $this->assertSame('provider', $this->item($result)['viewer'], $status);
    }
  }

  /* -------------------------------------------------------------------------
   * The provider's trim.
   * ---------------------------------------------------------------------- */

  /**
   * The provider gets `viewer: provider`, `unit: null` and every other key with
   * the same content the requester sees — including the whole `condominium` and
   * `requester {id, name}`.
   */
  public function testTheProviderTrimIsExactlyTwoKeys() {
    $tables = $this->providerScenario([], [
      'field_data_field_images'  => [$this->image(91, 'fuga.jpg', 0)],
      'field_data_field_request' => [$this->offer(46, self::CREATED, self::PROVIDER_NID)],
    ]);

    $this->authenticate();
    $this->seed($tables, self::PROVIDER_UID);
    $provider = $this->item($this->dispatch());

    $this->authenticate();
    $this->seed($tables, self::UID);
    $requester = $this->item($this->dispatch());

    $this->assertSame(array_keys($requester), array_keys($provider));
    $this->assertSame('provider', $provider['viewer']);
    $this->assertNull($provider['unit']);

    // Everything else, key by key.
    foreach (array_keys($requester) as $key) {
      if ($key === 'viewer' || $key === 'unit') {
        continue;
      }
      $this->assertSame($requester[$key], $provider[$key], $key);
    }
  }

  /**
   * `offers` holds ONLY the reader's own — and `offers_count` is still the
   * TOTAL. Three offers from three providers: the one who bid sees
   * offers_count 3 and a list of one.
   */
  public function testTheProviderSeesOnlyTheirOfferAndTheFullCount() {
    $result = $this->detailFor(self::PROVIDER_UID, $this->providerScenario([], [
      'field_data_field_request' => [
        $this->offer(46, self::CREATED + 30, self::PROVIDER_NID, ['amount' => '95.50']),
        $this->offer(45, self::CREATED + 20, 7, ['provider_id' => '7', 'amount' => '120.00']),
        $this->offer(44, self::CREATED + 10, 8, ['provider_id' => '8', 'amount' => '80.00']),
      ],
    ]));

    $item = $this->item($result);

    $this->assertSame(3, $item['offers_count']);
    $this->assertCount(1, $item['offers']);
    $this->assertSame(46, $item['offers'][0]['id']);
    // The competitors' prices never travelled.
    $this->assertStringNotContainsString('120', $result['output']);
    $this->assertStringNotContainsString('80', $result['output']);
  }

  /**
   * A reader who operates TWO provider nodes that both bid sees BOTH offers:
   * field_provider_users has unlimited cardinality and nothing enforces one
   * account per provider.
   */
  public function testAReaderOperatingTwoProvidersSeesBothOffers() {
    $tables = [
      'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [
        [
          'entity_id'   => (string) self::PROVIDER_NID,
          'entity_type' => 'node',
          'deleted'     => '0',
          MYAPI_PROVIDER_USERS_FIELD . '_target_id' => (string) self::PROVIDER_UID,
        ],
        [
          'entity_id'   => '10',
          'entity_type' => 'node',
          'deleted'     => '0',
          MYAPI_PROVIDER_USERS_FIELD . '_target_id' => (string) self::PROVIDER_UID,
        ],
      ],
      'field_data_field_categories' => [[
        'entity_id'            => (string) self::PROVIDER_NID,
        'entity_type'          => 'node',
        'deleted'              => '0',
        'field_categories_tid' => (string) self::CATEGORY,
      ]],
      'field_data_field_request' => [
        $this->offer(46, self::CREATED + 20, self::PROVIDER_NID),
        $this->offer(45, self::CREATED + 10, 10, ['provider_id' => '10']),
        $this->offer(44, self::CREATED, 7, ['provider_id' => '7']),
      ],
      'node' => [$this->request(), $this->providerNode(), $this->providerNode(10)],
    ];

    $item = $this->item($this->detailFor(self::PROVIDER_UID, $tables));

    $this->assertSame([46, 45], array_column($item['offers'], 'id'));
    $this->assertSame(3, $item['offers_count']);
  }

  /* -------------------------------------------------------------------------
   * The offers.
   * ---------------------------------------------------------------------- */

  /**
   * The requester sees every PUBLISHED offer whatever its status — 'rejected'
   * and 'withdrawn' included — which is exactly what offers_count counts, so the
   * number and the list cannot contradict each other.
   */
  public function testEveryPublishedOfferTravelsWhateverItsStatus() {
    $statuses = array_keys(myapi_services_offer_statuses());
    $offers = [];
    $nid = 50;
    foreach ($statuses as $index => $status) {
      $offers[] = $this->offer($nid + $index, self::CREATED + $index, self::PROVIDER_NID, [
        'fost.field_offer_status_value' => $status,
      ]);
    }

    $item = $this->item($this->detailFor(self::UID, ['field_data_field_request' => $offers]));

    $this->assertSame(count($statuses), $item['offers_count']);
    $this->assertSame(count($statuses), count($item['offers']));
    $this->assertSame(array_reverse($statuses), array_column($item['offers'], 'status'));
  }

  /**
   * An UNPUBLISHED offer is neither listed nor counted: the two functions make
   * that call identically, which is what keeps them from disagreeing.
   */
  public function testAnUnpublishedOfferNeitherShowsNorCounts() {
    $item = $this->item($this->detailFor(self::UID, [
      'field_data_field_request' => [
        $this->offer(46, self::CREATED + 10, self::PROVIDER_NID),
        $this->offer(45, self::CREATED, self::PROVIDER_NID, ['no.status' => '0']),
      ],
    ]));

    $this->assertSame([46], array_column($item['offers'], 'id'));
    $this->assertSame(1, $item['offers_count']);
  }

  /**
   * For the requester, count(offers) is ALWAYS offers_count — the invariant the
   * aggregate exists to keep, checked over a handful of shapes.
   */
  public function testForTheRequesterTheListAndTheCountAlwaysAgree() {
    $sets = [
      [],
      [$this->offer(46, self::CREATED, self::PROVIDER_NID)],
      [
        $this->offer(46, self::CREATED + 10, self::PROVIDER_NID),
        $this->offer(45, self::CREATED, 7, ['provider_id' => '7']),
      ],
    ];

    foreach ($sets as $index => $offers) {
      $item = $this->item($this->detailFor(self::UID, ['field_data_field_request' => $offers]));

      $this->assertSame(count($item['offers']), $item['offers_count'], 'set ' . $index);
    }
  }

  /**
   * The order is created DESC, and the tie-break by nid DESC is not decoration:
   * two offers of the same second would otherwise swap places between two reads
   * of the same screen.
   */
  public function testTheOrderIsCreatedDescendingWithANidTieBreak() {
    $item = $this->item($this->detailFor(self::UID, [
      'field_data_field_request' => [
        $this->offer(44, self::CREATED, self::PROVIDER_NID),
        $this->offer(47, self::CREATED + 100, self::PROVIDER_NID),
        // Same second as 44, higher nid: it must come first.
        $this->offer(45, self::CREATED, self::PROVIDER_NID),
        $this->offer(46, self::CREATED + 50, self::PROVIDER_NID),
      ],
    ]));

    $this->assertSame([47, 46, 45, 44], array_column($item['offers'], 'id'));
  }

  /**
   * An offer whose field_provider points at an unpublished or deleted node
   * answers provider: null AND STAYS IN THE LIST — dropping it would make the
   * list disagree with offers_count, which counts it.
   */
  public function testAnOfferOfAnUnpublishedProviderKeepsItsPlace() {
    $item = $this->item($this->detailFor(self::UID, [
      'field_data_field_request' => [
        $this->offer(46, self::CREATED + 10, self::PROVIDER_NID),
        $this->offer(45, self::CREATED, 7, [
          // What the LEFT JOIN restricted to published providers answers.
          'provider_id'       => NULL,
          'provider_name'     => NULL,
          'provider_logo_uri' => NULL,
        ]),
      ],
    ]));

    $this->assertSame([46, 45], array_column($item['offers'], 'id'));
    $this->assertNull($item['offers'][1]['provider']);
    $this->assertSame(2, $item['offers_count']);
  }

  /**
   * `amount` is a float or null, never the "95.50" the database hands back and
   * never 0 for a missing price: the field is optional by SPEC 77's decision —
   * the price can be settled in the chat — and 0 is a price somebody offered.
   */
  public function testTheAmountIsAFloatOrNullAndNeverZero() {
    $result = $this->detailFor(self::UID, [
      'field_data_field_request' => [
        $this->offer(46, self::CREATED + 20, self::PROVIDER_NID, ['amount' => '95.50']),
        $this->offer(45, self::CREATED + 10, self::PROVIDER_NID, ['amount' => NULL]),
        $this->offer(44, self::CREATED, self::PROVIDER_NID, ['amount' => '0.00']),
      ],
    ]);
    $item = $this->item($result);

    $this->assertSame(95.5, $item['offers'][0]['amount']);
    $this->assertIsFloat($item['offers'][0]['amount']);
    $this->assertStringContainsString('"amount":95.5', $result['output'] ?? '');
    $this->assertNull($item['offers'][1]['amount']);
    // A zero price IS a price: the key is 0 and not null, which is the whole
    // distinction. It arrives as an int because JSON prints a float 0.0 as "0"
    // — the wire has one number type, and what matters is that it is not null.
    $this->assertNotNull($item['offers'][2]['amount']);
    $this->assertEquals(0, $item['offers'][2]['amount']);
  }

  /**
   * `provider.logo` is an ABSOLUTE, DIRECT url to the file, or null — never an
   * api/v1/... route: field_logo is public:// (SPEC 85), unlike the request's
   * own images.
   */
  public function testTheProviderLogoIsADirectUrlOrNull() {
    $item = $this->item($this->detailFor(self::UID, [
      'field_data_field_request' => [
        $this->offer(46, self::CREATED + 10, self::PROVIDER_NID, ['provider_logo_uri' => 'public://logo-diaz.png']),
        $this->offer(45, self::CREATED, 7, ['provider_id' => '7', 'provider_logo_uri' => NULL]),
      ],
    ]));

    $this->assertSame(file_create_url('public://logo-diaz.png'), $item['offers'][0]['provider']['logo']);
    $this->assertStringNotContainsString('api/v1', $item['offers'][0]['provider']['logo']);
    $this->assertNull($item['offers'][1]['provider']['logo']);
  }

  /**
   * `message` keeps the line breaks the provider typed, exactly like
   * `description`: myapi_text_to_plain() would collapse them.
   */
  public function testTheOfferMessageKeepsItsLineBreaks() {
    $message = "Puedo pasar el jueves.\n\nLlevo repuesto.";

    $item = $this->item($this->detailFor(self::UID, [
      'field_data_field_request' => [$this->offer(46, self::CREATED, self::PROVIDER_NID, ['message' => $message])],
    ]));

    $this->assertSame($message, $item['offers'][0]['message']);
  }

  /**
   * A request with no offers answers offers: [] and offers_count: 0 — every
   * 'direct' request among them.
   */
  public function testARequestWithNoOffersAnswersAnEmptyListAndZero() {
    $item = $this->item($this->detailFor(self::UID, ['field_data_field_request' => []]));

    $this->assertSame([], $item['offers']);
    $this->assertSame(0, $item['offers_count']);
  }

  /**
   * A 'service_transaction' of the request appears NEITHER in `offers` NOR in
   * `offers_count`. field_request is shared by the two bundles (SPEC 77), so
   * without the bundle condition the timeline would be listed as bids and the
   * count would grow with every status change — a silent failure, since the
   * number stays plausible.
   */
  public function testTimelineEntriesAreNeitherListedNorCounted() {
    $item = $this->item($this->detailFor(self::UID, [
      'field_data_field_request' => [
        $this->offer(46, self::CREATED + 10, self::PROVIDER_NID),
        $this->offer(70, self::CREATED, self::PROVIDER_NID, ['no.type' => MYAPI_SERVICES_TRANSACTION_TYPE]),
      ],
    ]));

    $this->assertSame([46], array_column($item['offers'], 'id'));
    $this->assertSame(1, $item['offers_count']);
  }

  /* -------------------------------------------------------------------------
   * The timeline (SPEC 93).
   * ---------------------------------------------------------------------- */

  /**
   * A transaction travels with EXACTLY FIVE KEYS, in order, and with the types
   * the doc promises: `id` an int, `status` the raw catalogue key, the two
   * dates as strings, `comment` as stored.
   *
   * NEITHER `images` NOR `attachment` APPEARS, and that is the point of the
   * assertion on the key list rather than on the values: 'service_transaction'
   * has no instance of either field — it never had — so serving them as a fixed
   * `[]` and `null` would be two keys that always lie. A key that can never
   * have content teaches the client to trust a hole.
   */
  public function testATransactionTravelsWithExactlyItsFiveKeys() {
    $result = $this->detailFor(self::UID, [
      'node' => [
        $this->request(),
        $this->transaction(512, '2026-08-19 14:30:00'),
      ],
    ]);

    $item = $this->item($result);
    $this->assertCount(1, $item['transactions']);

    $this->assertSame(
      ['id', 'status', 'status_date', 'comment', 'created'],
      array_keys($item['transactions'][0])
    );

    $this->assertSame([
      'id'          => 512,
      'status'      => MYAPI_SERVICES_REQUEST_STATUS_OPEN,
      'status_date' => '2026-08-19T14:30:00',
      'comment'     => 'Hemos recibido su solicitud.',
      'created'     => format_date(self::CREATED, 'custom', 'Y-m-d\TH:i:s'),
    ], $item['transactions'][0]);

    $this->assertIsInt($item['transactions'][0]['id'], 'an int, not the string the column holds');
  }

  /**
   * `status` IS THE RAW CATALOGUE KEY, whichever it is, and never a translated
   * label. The listing and the detail already serve `status` raw; serving the
   * label here and not there would make the client ask which of the two
   * sources to read.
   */
  public function testTheStatusTravelsRaw() {
    foreach (array_keys(myapi_services_request_statuses()) as $status) {
      $result = $this->detailFor(self::UID, [
        'node' => [
          $this->request(),
          $this->transaction(512, '2026-08-19 14:30:00', ['frs.field_request_status_value' => $status]),
        ],
      ]);

      $this->assertSame($status, $this->item($result)['transactions'][0]['status'], $status);
    }
  }

  /**
   * status_date IS THE STORED VALUE WITH A 'T' IN IT, with no timezone
   * conversion whatsoever — the site's zone does not move it by one hour.
   *
   * field_status_date is the same field shared with 'claim_transaction' and was
   * created with tz_handling = 'none' (SPEC 55): what is stored is a naive local
   * time. Running it through strtotime() would shift it by the server's zone and
   * answer an hour nobody typed, which is the bug SPEC 58 already paid for once.
   *
   * `created` IS a real timestamp and does go through format_date(), which is
   * why the two are asserted together: two columns, two rules, on purpose.
   */
  public function testStatusDateIsNotConvertedAndCreatedIs() {
    $original = date_default_timezone_get();

    foreach (['UTC', 'America/Guayaquil', 'Asia/Tokyo'] as $zone) {
      date_default_timezone_set($zone);

      $result = $this->detailFor(self::UID, [
        'node' => [
          $this->request(),
          $this->transaction(512, '2026-08-19 14:30:00'),
        ],
      ]);

      $transaction = $this->item($result)['transactions'][0];
      $this->assertSame('2026-08-19T14:30:00', $transaction['status_date'], $zone);
      $this->assertSame(
        format_date(self::CREATED, 'custom', 'Y-m-d\TH:i:s'),
        $transaction['created'],
        $zone
      );
    }

    date_default_timezone_set($original);
  }

  /**
   * A transaction with no comment answers NULL and never "": the field row does
   * not exist, and an empty string would say "somebody wrote nothing" where the
   * truth is "nobody wrote".
   */
  public function testACommentlessTransactionAnswersNull() {
    $result = $this->detailFor(self::UID, [
      'node' => [
        $this->request(),
        $this->transaction(512, '2026-08-19 14:30:00', ['comment' => NULL]),
      ],
    ]);

    $this->assertNull($this->item($result)['transactions'][0]['comment']);
  }

  /**
   * THE ORDER IS THE TIMELINE'S: oldest first, and two transactions of the very
   * same minute — which happen, when an operator registers two changes in a row
   * — come out by ascending id and not in whatever order the fixture was
   * written in.
   */
  public function testTheOrderIsChronologicalAndTiesBreakByAscendingId() {
    $result = $this->detailFor(self::UID, [
      'node' => [
        $this->request(),
        // Written newest-first and with the tie deliberately inverted.
        $this->transaction(514, '2026-08-20 09:00:00'),
        $this->transaction(513, '2026-08-19 14:30:00'),
        $this->transaction(512, '2026-08-19 14:30:00'),
      ],
    ]);

    $this->assertSame([512, 513, 514], array_column($this->item($result)['transactions'], 'id'));
  }

  /**
   * An UNPUBLISHED transaction does not appear. Unpublishing one from the back
   * office is what makes it disappear from the app — that is what unpublishing
   * means — and the timeline is left with a gap nobody explains, which is
   * written down in docs/service-request.md for whoever presses the button.
   */
  public function testAnUnpublishedTransactionDoesNotAppear() {
    $result = $this->detailFor(self::UID, [
      'node' => [
        $this->request(),
        $this->transaction(512, '2026-08-19 14:30:00'),
        $this->transaction(513, '2026-08-19 15:00:00', ['status' => '0']),
      ],
    ]);

    $this->assertSame([512], array_column($this->item($result)['transactions'], 'id'));
  }

  /**
   * THE GUARD OF THE WHOLE KEY: an OFFER of the same request does not appear in
   * `transactions`.
   *
   * field_request is SHARED by 'service_offer' and 'service_transaction' since
   * SPEC 77, so a query that forgets `n.type` lists the request's offers as if
   * they were timeline entries — each with status, status_date and comment at
   * null, which reads like a broken transaction and not like a wrong query. It
   * is the same risk SPEC 92 wrote down for the opposite direction
   * (offers_count counting transactions), checked here from this side.
   */
  public function testAnOfferOfTheSameRequestIsNotATransaction() {
    $result = $this->detailFor(self::UID, [
      'node' => [
        $this->request(),
        $this->transaction(512, '2026-08-19 14:30:00'),
        // An offer node pointing at the very same request, seeded into the same
        // table the timeline query reads.
        $this->transaction(600, '2026-08-19 16:00:00', ['type' => MYAPI_SERVICES_OFFER_TYPE]),
      ],
      'field_data_field_request' => [$this->offer(600, self::CREATED, self::PROVIDER_NID)],
    ]);

    $item = $this->item($result);
    $this->assertSame([512], array_column($item['transactions'], 'id'));
    // And it is still an offer, counted and listed as one.
    $this->assertSame([600], array_column($item['offers'], 'id'));
    $this->assertSame(1, $item['offers_count']);
  }

  /**
   * A transaction of ANOTHER request does not appear, however published and
   * however well-formed it is.
   */
  public function testATransactionOfAnotherRequestDoesNotAppear() {
    $result = $this->detailFor(self::UID, [
      'node' => [
        $this->request(),
        $this->transaction(512, '2026-08-19 14:30:00'),
        $this->transaction(513, '2026-08-19 15:00:00', ['fr.field_request_target_id' => '999']),
      ],
    ]);

    $this->assertSame([512], array_column($this->item($result)['transactions'], 'id'));
  }

  /**
   * A request with no transaction at all — every request born before SPEC 92 —
   * answers an EMPTY ARRAY. Never null, never an absent key, and no row is
   * invented for it: there is no backfill, because a made-up entry would carry
   * an acknowledgement nobody issued and the CURRENT status rather than the one
   * the request was born with.
   */
  public function testARequestWithNoTransactionsAnswersAnEmptyArray() {
    $result = $this->detailFor(self::UID);

    $item = $this->item($result);
    $this->assertArrayHasKey('transactions', $item);
    $this->assertSame([], $item['transactions']);
    $this->assertStringContainsString('"transactions":[]', $result['output'], 'a list, not an object');
  }

  /**
   * BOTH READERS GET THE SAME TIMELINE, entire and with the same comments. The
   * provider of the category is not trimmed here: the comments that exist today
   * are written by SPEC 92 and are addressed to the resident, and cutting them
   * back would be inventing a confidentiality rule no field marks.
   */
  public function testBothReadersGetTheSameTimeline() {
    $tables = $this->providerScenario([], [], [self::CATEGORY]);
    $tables['node'][] = $this->transaction(512, '2026-08-19 14:30:00');
    $tables['node'][] = $this->transaction(513, '2026-08-19 15:00:00', ['comment' => 'Un proveedor le envió una oferta.']);

    $this->authenticate();
    $this->seed($tables, self::PROVIDER_UID);
    $provider = $this->item($this->dispatch());

    $this->authenticate();
    $this->seed($tables, self::UID);
    $requester = $this->item($this->dispatch());

    $this->assertSame('provider', $provider['viewer']);
    $this->assertSame('requester', $requester['viewer']);
    $this->assertSame($requester['transactions'], $provider['transactions']);
    $this->assertSame([512, 513], array_column($provider['transactions'], 'id'));
  }

  /**
   * A reader who fits no rule of myapi_service_request_viewer() gets their 403
   * WITHOUT the timeline query ever running: the load sits after the access
   * check, so a refused read pays for nothing it will not be shown.
   */
  public function testAForbiddenReaderNeverRunsTheTimelineQuery() {
    $this->authenticate();
    $this->seed(['node' => [
      $this->request(),
      $this->transaction(512, '2026-08-19 14:30:00'),
    ]], self::STRANGER_UID);

    $result = $this->dispatch();

    $this->assertSame(403, $result['status']);
    $this->assertSame([], $this->timelineQueries());
  }

  /**
   * ONE QUERY, WHATEVER THE NUMBER OF TRANSACTIONS: a request with twenty costs
   * the same as one with a single one, and the same as one with none. There is
   * no per-transaction read because there is nothing to load apart — no images,
   * no attachment — which is exactly what forces a second query in claims.
   */
  public function testTheTimelineCostsOneQueryWhateverItsLength() {
    foreach ([0, 1, 20] as $count) {
      $nodes = [$this->request()];
      for ($i = 0; $i < $count; $i++) {
        $nodes[] = $this->transaction(512 + $i, '2026-08-19 14:30:0' . ($i % 10));
      }

      $this->detailFor(self::UID, ['node' => $nodes]);

      $this->assertCount(1, $this->timelineQueries(), $count . ' transactions');
      $this->assertCount($count, $this->item($this->dispatch())['transactions'], $count . ' transactions');
    }
  }

  /**
   * The loader refuses an impossible nid BEFORE any query, the same guard every
   * read path of this module opens with: hook_node_insert() and the back office
   * can call it with whatever the caller has.
   */
  public function testTheLoaderRefusesAnImpossibleNidWithoutAnyQuery() {
    foreach ([0, -1, '', 'abc', NULL, []] as $nid) {
      myapi_test_db_seed(['node' => [$this->transaction(512, '2026-08-19 14:30:00')]]);

      $this->assertSame([], myapi_service_request_load_transactions($nid), var_export($nid, TRUE));
      $this->assertSame([], myapi_test_db_queries(), var_export($nid, TRUE));
    }
  }
  /* -------------------------------------------------------------------------
   * The files.
   * ---------------------------------------------------------------------- */

  /**
   * Every file url points at this module's authenticated route, absolute, and
   * never at system/files/... — which the app could not open with a token.
   */
  public function testTheFileUrlsPointAtTheAuthenticatedRoute() {
    $item = $this->item($this->detailFor(self::UID, [
      'field_data_field_images' => [$this->image(91, 'fuga.jpg', 0)],
    ]));

    $expected = url('api/v1/service-requests/128/files/91', ['absolute' => TRUE]);
    $this->assertSame($expected, $item['images'][0]['url']);
    $this->assertStringStartsWith('http', $item['images'][0]['url']);
    $this->assertStringNotContainsString('system/files', $item['images'][0]['url']);
    $this->assertStringContainsString('/api/v1/service-requests/128/files/92', $item['attachment']['url']);
  }

  /**
   * The images come out in the delta order the operator uploaded them in, which
   * is the order the app paints. Nothing else would be stable.
   */
  public function testTheImagesKeepTheirDeltaOrder() {
    $item = $this->item($this->detailFor(self::UID, [
      'field_data_field_images' => [
        $this->image(93, 'tercera.jpg', 2),
        $this->image(91, 'primera.jpg', 0),
        $this->image(92, 'segunda.jpg', 1),
      ],
    ]));

    $this->assertSame(
      ['primera.jpg', 'segunda.jpg', 'tercera.jpg'],
      array_column($item['images'], 'filename')
    );
  }

  /**
   * The requester downloads their own image: 200, the bytes of the right uri,
   * and the headers the app needs — Content-Type, Content-Length and inline.
   */
  public function testTheRequesterDownloadsAnImage() {
    $this->authenticate();
    $this->seed([
      'node'                    => [$this->request()],
      'field_data_field_images' => [$this->fileOwnership('field_images', 91, self::NID)],
    ], self::UID);
    myapi_test_file_seed([91 => (object) [
      'fid'      => 91,
      // An existing path, so file_exists() is true without touching the disk.
      'uri'      => __FILE__,
      'filename' => 'fuga.jpg',
      'filemime' => 'image/jpeg',
      'filesize' => 2048,
    ]]);

    $this->dispatchFile(self::NID, 91);

    $transfers = myapi_test_file_transfers();
    $this->assertCount(1, $transfers);
    $this->assertSame(__FILE__, $transfers[0]['uri']);
    $this->assertSame('image/jpeg', $transfers[0]['headers']['Content-Type']);
    $this->assertSame(2048, $transfers[0]['headers']['Content-Length']);
    $this->assertSame('inline; filename="fuga.jpg"', $transfers[0]['headers']['Content-Disposition']);
    $this->assertSame('private, no-store', $transfers[0]['headers']['Cache-Control']);
  }

  /**
   * A provider who may read the detail downloads the same files with the same
   * 200: the images are what they decide whether to bid on.
   */
  public function testTheProviderDownloadsTheSameFiles() {
    $this->authenticate();
    $this->seed($this->providerScenario([], [
      'field_data_field_attachment' => [$this->fileOwnership('field_attachment', 92, self::NID)],
    ]), self::PROVIDER_UID);
    myapi_test_file_seed([92 => (object) [
      'fid' => 92, 'uri' => __FILE__, 'filename' => 'p.pdf', 'filemime' => 'application/pdf', 'filesize' => 10,
    ]]);

    $this->dispatchFile(self::NID, 92);

    $this->assertCount(1, myapi_test_file_transfers());
  }

  /**
   * WHOEVER CANNOT READ THE DETAIL CANNOT DOWNLOAD ITS FILES, and it is the SAME
   * 403 — the same function, not a copy. This case asks both routes with the
   * same token in both directions and demands they agree, which is what stops
   * the two rules from drifting into "403 on the detail, bytes on the file".
   */
  public function testBothRoutesAlwaysAgreeOnAccess() {
    $scenarios = [
      'requester'          => [self::UID, [], 200],
      'eligible provider'  => [self::PROVIDER_UID, $this->providerScenario(), 200],
      'stranger'           => [self::STRANGER_UID, [], 403],
      'wrong category'     => [self::PROVIDER_UID, $this->providerScenario([], [], [77]), 403],
      'suspended provider' => [self::PROVIDER_UID, $this->providerScenario([], [], [self::CATEGORY], $this->providerNode(self::PROVIDER_NID, '0')), 403],
      'terminal status'    => [self::PROVIDER_UID, $this->providerScenario(['frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_CLOSED]), 403],
    ];

    foreach ($scenarios as $label => $scenario) {
      list($uid, $tables, $expected) = $scenario;
      $tables += ['node' => [$this->request()]];
      $tables['field_data_field_images'] = [$this->fileOwnership('field_images', 91, self::NID)];

      $this->authenticate();
      $this->seed($tables, $uid);
      $detail = $this->dispatch();

      $this->authenticate();
      $this->seed($tables, $uid);
      myapi_test_file_seed([91 => (object) [
        'fid' => 91, 'uri' => __FILE__, 'filename' => 'fuga.jpg', 'filemime' => 'image/jpeg', 'filesize' => 1,
      ]]);
      $GLOBALS['myapi_test_file_transfers'] = [];
      $file = $this->dispatchFile(self::NID, 91);

      $fileStatus = myapi_test_file_transfers() ? 200 : $file['status'];

      $this->assertSame($expected, $detail['status'], $label . ' — detail');
      $this->assertSame($expected, $fileStatus, $label . ' — file');
    }
  }

  /**
   * THE GUARD THAT MAKES THE ROUTE SAFE: a fid that exists but belongs to
   * ANOTHER request is 404 and not the bytes. Without the membership check,
   * access to one 'open' request of your category — which every active provider
   * has — would serve every private file of the site by walking fids.
   */
  public function testAFidOfAnotherRequestIs404() {
    $this->authenticate();
    $this->seed([
      'node'                    => [$this->request()],
      'field_data_field_images' => [$this->fileOwnership('field_images', 91, 500)],
    ], self::UID);
    myapi_test_file_seed([91 => (object) [
      'fid' => 91, 'uri' => __FILE__, 'filename' => 'ajena.jpg', 'filemime' => 'image/jpeg', 'filesize' => 1,
    ]]);

    $result = $this->dispatchFile(self::NID, 91);

    $this->assertSame(404, $result['status']);
    $this->assertSame('not_found', $result['json']['error_code']);
    $this->assertSame([], myapi_test_file_transfers(), 'nothing was served');
  }

  /**
   * A fid of a payment receipt, of a claim or of a provider gallery — files this
   * bundle does not own — is 404 as well. The bundle condition in the ownership
   * query is what makes the two shared field names (field_images and
   * field_attachment live on 'reclamo' too) safe.
   */
  public function testAFidOfAnotherFamilyOfFilesIs404() {
    $cases = [
      'a claim image'    => [$this->fileOwnership('field_images', 91, self::NID, 'reclamo')],
      'no owner at all'  => [],
    ];

    foreach ($cases as $label => $rows) {
      $this->authenticate();
      $this->seed([
        'node'                    => [$this->request()],
        'field_data_field_images' => $rows,
      ], self::UID);
      myapi_test_file_seed([91 => (object) [
        'fid' => 91, 'uri' => __FILE__, 'filename' => 'x.jpg', 'filemime' => 'image/jpeg', 'filesize' => 1,
      ]]);

      $result = $this->dispatchFile(self::NID, 91);

      $this->assertSame(404, $result['status'], $label);
      $this->assertSame([], myapi_test_file_transfers(), $label);
    }
  }

  /**
   * A file_managed row whose file is not on disk is 404 too — not a 200 of zero
   * bytes nor a PHP warning out of fopen().
   */
  public function testAFileMissingFromDiskIs404() {
    $this->authenticate();
    $this->seed([
      'node'                    => [$this->request()],
      'field_data_field_images' => [$this->fileOwnership('field_images', 91, self::NID)],
    ], self::UID);
    myapi_test_file_seed([91 => (object) [
      'fid' => 91, 'uri' => '/tmp/myapi-nothing-here-89.jpg', 'filename' => 'x.jpg', 'filemime' => 'image/jpeg', 'filesize' => 1,
    ]]);

    $this->assertSame(404, $this->dispatchFile(self::NID, 91)['status']);
    $this->assertSame([], myapi_test_file_transfers());
  }

  /* -------------------------------------------------------------------------
   * includes/myapi.service_request_files.inc — the two consumers' shared half.
   * ---------------------------------------------------------------------- */

  /**
   * The ownership resolution: an image and an attachment of a request resolve to
   * it, images are asked about first, and everything else answers NULL.
   */
  public function testTheOwnershipResolution() {
    myapi_test_db_seed(['field_data_field_images' => [$this->fileOwnership('field_images', 91, self::NID)]]);
    $this->assertSame(self::NID, myapi_service_request_file_request_nid(91));

    myapi_test_db_seed(['field_data_field_attachment' => [$this->fileOwnership('field_attachment', 92, self::NID)]]);
    $this->assertSame(self::NID, myapi_service_request_file_request_nid(92));
    $this->assertSame(
      ['field_data_field_images', 'field_data_field_attachment'],
      $this->queriedTables(),
      'images first, attachment second'
    );

    // A claim's image, through the very same field name.
    myapi_test_db_seed(['field_data_field_images' => [$this->fileOwnership('field_images', 91, self::NID, 'reclamo')]]);
    $this->assertNull(myapi_service_request_file_request_nid(91));
  }

  /**
   * A fid that is not a positive integer costs NO query: the guard is the first
   * line, and hook_file_download() fires for every private file of the site.
   */
  public function testAnImpossibleFidCostsNoQuery() {
    foreach ([0, -1, 'abc', NULL, ''] as $fid) {
      myapi_test_db_seed([]);

      $this->assertNull(myapi_service_request_file_request_nid($fid), var_export($fid, TRUE));
      $this->assertSame([], myapi_test_db_queries(), var_export($fid, TRUE));
    }
  }

  /**
   * The BACK-OFFICE rule, which is not the app's: the three administrative
   * roles, with 'administrador edificio' scoped to its assigned condominiums.
   * A resident, a provider and an anonymous visitor are all refused — they have
   * no back office at all.
   */
  public function testTheBackOfficeFileAccessRule() {
    myapi_test_node_seed([
      self::NID => (object) [
        'nid'  => self::NID,
        'type' => MYAPI_SERVICES_REQUEST_TYPE,
        'field_condominium' => ['und' => [['target_id' => 7]]],
      ],
      500 => (object) [
        'nid'  => 500,
        'type' => MYAPI_SERVICES_REQUEST_TYPE,
        'field_condominium' => ['und' => [['target_id' => 99]]],
      ],
    ]);

    $account = function (array $roles, $condominiums = NULL) {
      $account = (object) ['uid' => 5, 'roles' => $roles];
      if ($condominiums !== NULL) {
        $account->{MYAPI_BUILDING_ADMIN_CONDO_FIELD} = ['und' => array_map(function ($nid) {
          return ['target_id' => $nid];
        }, $condominiums)];
      }

      return $account;
    };

    $this->assertTrue(myapi_service_request_file_access(self::NID, (object) ['uid' => 1, 'roles' => []]), 'uid 1');
    $this->assertTrue(myapi_service_request_file_access(self::NID, $account(['administrator'])));
    $this->assertTrue(myapi_service_request_file_access(self::NID, $account(['backend'])));
    $this->assertFalse(myapi_service_request_file_access(self::NID, $account(['authenticated user'])));
    $this->assertFalse(myapi_service_request_file_access(self::NID, $account([MYAPI_PROVIDER_ROLE])));
    $this->assertFalse(myapi_service_request_file_access(self::NID, NULL), 'anonymous');

    // The building admin, scoped. Each call resets the statics the resolution
    // caches per account.
    myapi_test_static_reset();
    $this->assertTrue(myapi_service_request_file_access(self::NID, $account([MYAPI_BUILDING_ADMIN_ROLE], [7])), 'own condominium');
    myapi_test_static_reset();
    $this->assertFalse(myapi_service_request_file_access(500, $account([MYAPI_BUILDING_ADMIN_ROLE], [7])), 'another condominium');
    myapi_test_static_reset();
    $this->assertFalse(myapi_service_request_file_access(self::NID, $account([MYAPI_BUILDING_ADMIN_ROLE], [])), 'nothing assigned');
  }

  /**
   * hook_file_download() answers headers for an operator, -1 for somebody who
   * may not read the request, and NULL for a file this bundle does not own —
   * the last one being what keeps every other private file of the site behaving
   * exactly as it did.
   */
  public function testTheDownloadHeadersOfTheBackOffice() {
    myapi_test_db_seed([
      'file_managed'            => [['fid' => '91', 'uri' => 'private://service_requests/fuga.jpg']],
      'field_data_field_images' => [$this->fileOwnership('field_images', 91, self::NID)],
    ]);
    myapi_test_file_seed([91 => (object) [
      'fid' => 91, 'uri' => 'private://service_requests/fuga.jpg', 'filename' => 'fuga.jpg',
      'filemime' => 'image/jpeg', 'filesize' => 2048,
    ]]);

    $admin = (object) ['uid' => 5, 'roles' => ['administrator']];
    $headers = myapi_service_request_file_download_headers('private://service_requests/fuga.jpg', $admin);
    $this->assertSame('inline; filename="fuga.jpg"', $headers['Content-Disposition']);
    $this->assertSame('image/jpeg', $headers['Content-Type']);

    $resident = (object) ['uid' => 5, 'roles' => ['authenticated user']];
    $this->assertSame(-1, myapi_service_request_file_download_headers('private://service_requests/fuga.jpg', $resident));

    $this->assertNull(myapi_service_request_file_download_headers('private://comprobantes_pago/recibo.pdf', $admin));
    $this->assertNull(myapi_service_request_file_download_headers('', $admin));
  }

  /* -------------------------------------------------------------------------
   * The query budget and the structural guards.
   * ---------------------------------------------------------------------- */

  /**
   * SIX CONTENT QUERIES, plus the token's — with one image and one offer, and
   * with twenty of each. Nothing grows with the number of rows.
   *
   * The sixth is the timeline (SPEC 93), and it reads the `node` table for the
   * second time: once for the request itself, once for its transactions.
   */
  public function testTheQueryBudgetDoesNotGrowWithTheData() {
    foreach ([1, 20] as $count) {
      $images = [];
      $offers = [];
      for ($i = 0; $i < $count; $i++) {
        $images[] = $this->image(200 + $i, 'foto' . $i . '.jpg', $i);
        $offers[] = $this->offer(300 + $i, self::CREATED + $i, self::PROVIDER_NID);
      }

      $this->detailFor(self::UID, [
        'field_data_field_images'  => $images,
        'field_data_field_request' => $offers,
      ]);

      $tables = $this->queriedTables();

      $this->assertSame([
        'my_api_tokens',
        'node',
        'users',
        'field_data_field_images',
        'field_data_field_request',
        'field_data_field_request',
        'node',
      ], $tables, $count . ' rows of each');
    }
  }

  /**
   * A provider's read adds the provider_role questions and nothing else, and
   * they are asked ONCE however many times the code needs them: the include
   * caches them statically per uid, which is why resolving the trim after the
   * access rule costs no second lookup.
   */
  public function testTheProviderPathAsksTheRoleQuestionsOnce() {
    $this->detailFor(self::PROVIDER_UID, $this->providerScenario([], [
      'field_data_field_request' => [$this->offer(46, self::CREATED, self::PROVIDER_NID)],
    ]));

    $tables = $this->queriedTables();
    $providerLookups = count(array_filter($tables, function ($table) {
      return $table === 'field_data_' . MYAPI_PROVIDER_USERS_FIELD;
    }));

    $this->assertSame(1, $providerLookups, 'the provider ids are resolved once: ' . implode(', ', $tables));
  }

  /**
   * THE STRUCTURAL GUARD OF THE WHOLE SCOPE: no query of this resource carries
   * ->addTag('node_access'), and the file contains no addTag() call at all.
   *
   * The tag would hand every query of this file to
   * myapi_provider_role_alter_node_query(), a whitelist by the provider's
   * categories, and a resident holding the 'proveedor' role would stop seeing
   * the detail of their OWN request of a category they do not attend. The
   * access rule of this endpoint is myapi_service_request_viewer(), in one place
   * and in plain sight.
   *
   * The guard reads the CODE and not the raw file: the docblocks say
   * 'node_access' several times on purpose, to explain the absence.
   */
  public function testNoQueryIsTaggedForNodeAccess() {
    $this->detailFor(self::UID, [
      'field_data_field_images'  => [$this->image(91, 'fuga.jpg', 0)],
      'field_data_field_request' => [$this->offer(46, self::CREATED, self::PROVIDER_NID)],
    ]);

    foreach (myapi_test_db_queries() as $index => $query) {
      $this->assertSame([], $query['tags'], 'query ' . $index . ' carries no tag');
    }

    $this->assertStringNotContainsString('addTag', $this->codeWithoutComments());
    $this->assertStringNotContainsString('addTag', $this->codeWithoutComments('/../../includes/myapi.service_request_files.inc'));
  }

  /**
   * THE STRUCTURAL GUARD OF THE FILE ROUTE: the endpoint resolves the fid's
   * owner and COMPARES IT with the nid of the route. Deleting that comparison
   * is the one edit that turns this route into a reader of every private file
   * of the site, and the behavioural cases above would still pass a version
   * that trusted the fid — this is what fails first.
   */
  public function testTheFileEndpointComparesTheFidsOwnerWithTheRoute() {
    $code = $this->codeWithoutComments();

    $this->assertMatchesRegularExpression(
      '/myapi_service_request_file_request_nid\(\$fid\)\s*!==\s*\$nid/',
      $code,
      'the membership comparison is still there'
    );
  }

  /**
   * NO READ PATH OF THIS RESOURCE CALLS node_load(), inside a loop or anywhere
   * else: every read is a query with joins, which is what keeps the budget
   * above flat.
   *
   * This guard was written (SPEC 89) when the file was read-only, and said
   * plainly "this file never says node_load". SPEC 95 added the first WRITE
   * path — the cancellation — and a write legitimately loads the entity it is
   * about to save: rewriting field_offer_status with a direct db_update() would
   * be one query instead of N and would leave the revision table and the entity
   * cache lying. So the ban now names the two functions that are allowed to
   * load, and every other function in the file is still forbidden. Adding a
   * third name to that list is a decision, not a fix — the reason a reader must
   * never load is that the detail's query budget is flat by construction, and
   * the test right above this one is what measures it. SPEC 96 took that
   * decision once more, for the edit, on exactly the same grounds: it saves the
   * node it loads, and overwriting five fields with db_update() would leave the
   * revision table and the entity cache lying.
   */
  public function testNoReadPathCallsNodeLoad() {
    // The writes: they load what they are about to save.
    $allowed = [
      'myapi_service_request_cancel',
      'myapi_service_request_reject_live_offers',
      'myapi_service_request_update',
    ];

    // BOTH halves of the detail: the endpoints left in the resource and the six
    // loaders and serialisers SPEC 106 moved to the include. The ban follows
    // the code, not the file it happens to live in.
    $files = [
      '/../../resources/service_request.resource.inc',
      '/../../includes/myapi.service_request_detail.inc',
    ];

    $checked = 0;
    foreach ($files as $file) {
      foreach ($this->functionBodies($file) as $name => $body) {
        if (in_array($name, $allowed, TRUE)) {
          continue;
        }

        $this->assertStringNotContainsString('node_load', $body, $name . '() must not call node_load()');
        $checked++;
      }
    }

    // Sanity: the splitter actually found the file's functions. A regex that
    // silently matched nothing would make the loop above pass on an empty set.
    $this->assertGreaterThan(20, $checked, 'the resource was split into functions');
  }

}
