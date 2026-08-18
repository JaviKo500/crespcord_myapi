<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../resources/service_request.resource.inc';

/**
 * End-to-end unit tests for GET /api/v1/service-requests (SPEC 88).
 *
 * myapi_service_request_dispatch() is called the way hook_menu() calls it, over
 * a fixture `node` table, a fixture my_api_tokens row and a fixture
 * Authorization header. What gets asserted is the JSON body the module prints
 * and the status code it sets — the same bytes the Flutter app receives.
 *
 * THE FIXTURE ROWS ARE THE JOINED ROWS. The query builder of tests/unit records
 * joins and never resolves them, so a request is seeded flat: its own node
 * columns plus the value each JOIN would have brought, under the alias the query
 * gives it ('category_name', not 'td.name'). A request whose awarded offer was
 * deleted is seeded with 'assigned_offer_id' NULL, which is exactly what the
 * chain of LEFT JOINs answers for a reference that no longer resolves.
 *
 * Two columns are seeded QUALIFIED — 'frs.field_request_status_value' and
 * 'fcat.field_category_tid' — and that is not decoration: the first would
 * otherwise collide with n.status, the published flag, and a flat row cannot
 * carry the same column twice; the second is read by the '?category_id'
 * condition and projected as 'category_id', and the qualified key serves both.
 *
 * Two things this suite therefore does NOT prove, and both are the database's
 * half rather than the module's:
 *
 *  - that the INNER JOIN on taxonomy_term_data really drops an orphan tid. The
 *    fixture cannot make a recorded join fail to match, so what is asserted is
 *    the SHAPE of that join — INNER, on that table — and the dropping is the
 *    database's to do.
 *  - that a LEFT JOIN to the awarded offer cannot multiply rows. Both fields
 *    have cardinality 1 and node.nid is a primary key, which is an argument
 *    about the schema and not something a fixture can exercise.
 *
 * What the suite DOES prove about the offer count is the whole of its
 * correctness: the bundle and the published flag travel as conditions rather
 * than inside the ON clause — the same set of rows for an INNER JOIN — so a
 * 'service_transaction' row seeded into field_data_field_request is excluded
 * here exactly as it is in production.
 */
class ServiceRequestListEndpointTest extends TestCase {

  /**
   * The plaintext token every fixture request sends.
   */
  const TOKEN = 'a-valid-access-token';

  /**
   * The uid every fixture request authenticates as, and the requester of every
   * seeded request unless a case overrides it.
   */
  const UID = 3;

  /**
   * A fixed instant, so the two date assertions do not depend on the clock.
   */
  const CREATED = 1786633953;

  protected function setUp(): void {
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');

    myapi_test_db_seed();
    $GLOBALS['myapi_test_users'] = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $this->clearQueryString();
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  protected function tearDown(): void {
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $this->clearQueryString();
    $GLOBALS['myapi_test_users'] = [];
    myapi_test_db_seed();
  }

  private function clearQueryString() {
    unset($_GET['page'], $_GET['limit'], $_GET['sort'], $_GET['status'], $_GET['category_id'], $_GET['date_from'], $_GET['date_to']);
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  /**
   * A my_api_tokens row for the plaintext token above.
   */
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
   * One service request, as every JOIN of the page query delivers it.
   *
   * Published, of the right bundle, requested by the reader and 'open' by
   * default, so every case that is not about those reads without noise. The
   * award is NULL on both keys, which is the honest default: nothing fills
   * field_assigned_offer or field_assigned_provider today.
   */
  private function request($nid, $title, array $overrides = []) {
    return $overrides + [
      'nid'                            => (string) $nid,
      'type'                           => MYAPI_SERVICES_REQUEST_TYPE,
      // The node's published flag. The request's own status is the qualified
      // key below — see the class docblock.
      'status'                         => '1',
      'title'                          => $title,
      'created'                        => (string) self::CREATED,
      // The technical author. No query of this endpoint reads it, which is what
      // testAnAdministratorAuthoredRequestIsListed() proves.
      'uid'                            => '1',
      'fr.field_requester_target_id'   => (string) self::UID,
      'fcat.field_category_tid'        => '12',
      'category_code'                  => 'plumbing',
      'category_name'                  => 'Plomería',
      'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_OPEN,
      'description'                    => 'El calentador gotea.',
      'desired_start'                  => (string) (self::CREATED + 86400),
      'assigned_offer_id'              => NULL,
      'assigned_offer_status'          => NULL,
      'assigned_provider_id'           => NULL,
      'assigned_provider_name'         => NULL,
    ];
  }

  /**
   * One row of field_data_field_request, joined to the node that carries it.
   *
   * That node is an OFFER by default. Seeding it as a 'service_transaction' is
   * how testTimelineEntriesDoNotCountAsOffers() reproduces the silent bug the
   * bundle condition exists to prevent: field_request is shared by the two
   * bundles (SPEC 77), so an unfiltered count would grow with every status
   * change of the request.
   */
  private function offer($request_nid, array $overrides = []) {
    return $overrides + [
      'fq.field_request_target_id' => (string) $request_nid,
      'entity_type'                => 'node',
      'deleted'                    => '0',
      // What the INNER JOIN to node brings, under the alias the query gives it.
      'no.type'                    => MYAPI_SERVICES_OFFER_TYPE,
      'no.status'                  => '1',
    ];
  }

  /**
   * Seeds the token row plus the given requests and offer rows.
   *
   * One call, because every myapi_test_db_seed() replaces the whole fixture.
   */
  private function seed(array $requests = [], array $offers = [], $uid = self::UID) {
    $GLOBALS['myapi_test_users'][$uid] = ['uid' => $uid, 'name' => 'user' . $uid, 'status' => 1];

    myapi_test_db_seed([
      'my_api_tokens'            => [$this->tokenRow(['uid' => (string) $uid])],
      'node'                     => $requests,
      'field_data_field_request' => $offers,
    ]);
  }

  private function authenticate() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
  }

  /**
   * Runs the endpoint the way hook_menu() does.
   */
  private function dispatch() {
    return myapi_test_capture('myapi_service_request_dispatch');
  }

  /**
   * Authenticates, seeds and runs, which is what almost every case needs.
   */
  private function listing(array $requests, array $offers = [], $uid = self::UID) {
    $this->authenticate();
    $this->seed($requests, $offers, $uid);

    return $this->dispatch();
  }

  private function items(array $result) {
    return $result['json']['data']['service_requests'];
  }

  private function ids(array $result) {
    return array_column($this->items($result), 'id');
  }

  private function pagination(array $result) {
    return $result['json']['data']['pagination'];
  }

  private function queriedTables() {
    return array_column(myapi_test_db_queries(), 'table');
  }

  /**
   * N requests with sequential nids and descending creation dates, so every
   * pagination case has a deterministic order to check.
   */
  private function manyRequests($count) {
    $requests = [];
    for ($i = 0; $i < $count; $i++) {
      $requests[] = $this->request(100 + $i, 'Solicitud ' . $i, [
        'created' => (string) (self::CREATED - $i),
      ]);
    }

    return $requests;
  }

  /**
   * The site-local midnight of the fixture day, shifted by whole days.
   *
   * Every date fixture is built from this and never from a literal timestamp,
   * so the suite says the same thing in any site timezone — which is the whole
   * point of the filter: n.created holds an INSTANT, and '?date_from' names a
   * DAY. The shift is a relative offset and not `+ 86400 * $n` because the
   * second form lands on 23:00 or 01:00 across a DST change.
   */
  private function dayStart($offset_days = 0) {
    return strtotime(date('Y-m-d', self::CREATED) . ' 00:00:00 ' . sprintf('%+d days', $offset_days));
  }

  /**
   * The last second of that same day, 23:59:59 site-local.
   */
  private function dayEnd($offset_days = 0) {
    return strtotime($this->day($offset_days) . ' 23:59:59');
  }

  /**
   * That day as the client writes it in the query string: 'YYYY-MM-DD'.
   */
  private function day($offset_days = 0) {
    return date('Y-m-d', $this->dayStart($offset_days));
  }

  /**
   * One request per day offset, nid and creation instant derived from it, so a
   * range case reads as "yesterday, today, tomorrow" instead of as arithmetic.
   */
  private function requestsOnDays(array $offsets) {
    $requests = [];
    foreach ($offsets as $offset) {
      $requests[] = $this->request(200 + $offset, 'Solicitud ' . $offset, [
        'created' => (string) ($this->dayStart($offset) + 3600),
      ]);
    }

    return $requests;
  }

  /**
   * The resource file with every comment stripped, so a structural guard can
   * assert what the CODE says without tripping over the docblocks that explain
   * it.
   *
   * The naive form of these guards — grepping the raw file for 'node_access' or
   * for a status key — would fail on this resource precisely because it
   * documents at length why the tag is absent and where the catalogue lives.
   * Tokenising is what lets the guard read the code and the prose stay.
   */
  private function codeWithoutComments() {
    $source = file_get_contents(__DIR__ . '/../../resources/service_request.resource.inc');
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

  /* -------------------------------------------------------------------------
   * Method routing and authentication.
   * ---------------------------------------------------------------------- */

  /**
   * Everything that is not GET or POST is 405, and the method is checked
   * BEFORE the token: a PUT with a perfectly valid token is still 405, and one
   * with no token at all is 405 too — never 401. Every write except creation
   * is out of scope — PUT and DELETE still answer 405 exactly as SPEC 88 left
   * them. POST is the one method that moved: since SPEC 90 it creates a
   * request instead of answering 405, and its own guards — auth first, then
   * the field validators — are covered by
   * tests/unit/ServiceRequestCreateEndpointTest.php, not here.
   */
  public function testEveryMethodOtherThanGetOrPostIs405BeforeAuthentication() {
    foreach (['PUT', 'DELETE', 'PATCH'] as $method) {
      $this->authenticate();
      $this->seed([$this->request(128, 'Fuga en el calentador')]);
      $_SERVER['REQUEST_METHOD'] = $method;

      $authenticated = $this->dispatch();

      unset($_SERVER['HTTP_AUTHORIZATION']);
      $this->seed([$this->request(128, 'Fuga en el calentador')]);

      $anonymous = $this->dispatch();

      $this->assertSame(405, $authenticated['status'], $method);
      $this->assertSame('method_not_allowed', $authenticated['json']['error_code'], $method);
      $this->assertSame('Método no permitido.', $authenticated['json']['error'], $method);
      $this->assertSame(405, $anonymous['status'], $method . ' (anonymous)');
      $this->assertSame('method_not_allowed', $anonymous['json']['error_code'], $method . ' (anonymous)');
      $this->assertSame([], $this->queriedTables(), $method . ': the 405 costs no query');
    }
  }

  /**
   * No Authorization header: 401 missing_authorization, and not one listing
   * query.
   */
  public function testMissingAuthorizationHeaderIs401() {
    $this->seed([$this->request(128, 'Fuga en el calentador')]);

    $result = $this->dispatch();

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
    $this->assertSame('No se proporcionó token de acceso.', $result['json']['error']);
    $this->assertNotContains('node', $this->queriedTables());
  }

  /**
   * A token with no row, a revoked one and an expired one are all 401
   * invalid_token — the code the app tells "log in" from "refresh" by.
   */
  public function testUnknownRevokedAndExpiredTokensAre401InvalidToken() {
    $this->seed([$this->request(128, 'Fuga en el calentador')]);
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer some-other-token';

    $unknown = $this->dispatch();

    foreach ([['revoked' => '1'], ['access_expires_at' => REQUEST_TIME - 1]] as $overrides) {
      $this->authenticate();
      $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'user3', 'status' => 1];
      myapi_test_db_seed([
        'my_api_tokens' => [$this->tokenRow($overrides)],
        'node'          => [$this->request(128, 'Fuga en el calentador')],
      ]);

      $result = $this->dispatch();

      $this->assertSame(401, $result['status'], json_encode($overrides));
      $this->assertSame('invalid_token', $result['json']['error_code'], json_encode($overrides));
    }

    $this->assertSame(401, $unknown['status']);
    $this->assertSame('invalid_token', $unknown['json']['error_code']);
    $this->assertSame('Token inválido.', $unknown['json']['error']);
  }

  /* -------------------------------------------------------------------------
   * The scope: field_requester = uid, and nothing else.
   * ---------------------------------------------------------------------- */

  /**
   * A request whose field_requester is ANOTHER uid does not appear and does not
   * count, however close its author or its condominium may be.
   */
  public function testAnotherResidentsRequestIsNeitherListedNorCounted() {
    $result = $this->listing([
      $this->request(128, 'Mía'),
      $this->request(129, 'Del vecino', ['fr.field_requester_target_id' => '99']),
    ]);

    $this->assertSame([128], $this->ids($result));
    $this->assertSame(1, $this->pagination($result)['total']);
  }

  /**
   * A request the ADMINISTRATOR authored from the back office, with
   * field_requester pointing at the reader, IS listed: the filter is
   * field_requester and never node.uid — which today is the operator for every
   * request on the site, so filtering by it would answer an empty list to
   * everybody.
   */
  public function testAnAdministratorAuthoredRequestIsListed() {
    $result = $this->listing([
      $this->request(128, 'Cargada por el operador', [
        'uid'                          => '1',
        'fr.field_requester_target_id' => (string) self::UID,
      ]),
    ]);

    $this->assertSame([128], $this->ids($result));
  }

  /**
   * Only published nodes of the 'service_request' bundle are listed: neither an
   * unpublished request nor a provider node sneaks in.
   */
  public function testOnlyPublishedServiceRequestsAreListed() {
    $result = $this->listing([
      $this->request(128, 'Publicada'),
      $this->request(129, 'Despublicada', ['status' => '0']),
      $this->request(130, 'Un proveedor', ['type' => MYAPI_SERVICES_PROVIDER_TYPE]),
    ]);

    $this->assertSame([128], $this->ids($result));
    $this->assertSame(1, $this->pagination($result)['total']);
  }

  /**
   * A reader with no requests at all gets 200 with an empty list and total 0 —
   * never a 403. "You have nothing" is not "you may not".
   */
  public function testAReaderWithNoRequestsGets200AndAnEmptyList() {
    $result = $this->listing([$this->request(128, 'Del vecino', ['fr.field_requester_target_id' => '99'])]);

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $this->items($result));
    $this->assertSame(
      ['total' => 0, 'page' => 1, 'limit' => 20, 'total_pages' => 0],
      $this->pagination($result)
    );
    $this->assertStringContainsString('"service_requests":[]', $result['output']);
  }

  /**
   * THE STRUCTURAL GUARD OF THE WHOLE SCOPE: no query of this endpoint carries
   * ->addTag('node_access'), and the resource file contains no addTag() call at
   * all.
   *
   * The tag would hand every query of this file to
   * myapi_provider_role_alter_node_query(), a whitelist by the provider's
   * categories, and a resident who also holds the 'proveedor' role would stop
   * seeing their own requests of categories they do not attend — silently, with
   * a shorter list and no error. It is also the sentence
   * myapi_query_node_access_alter() has written down about every query of this
   * module, and this test is what keeps it true.
   *
   * The guard reads the CODE and not the raw file (see codeWithoutComments()):
   * the docblocks of this resource say 'node_access' several times on purpose,
   * to explain the absence, and a grep over the file would punish exactly the
   * documentation that makes the decision survive.
   */
  public function testNoQueryIsTaggedForNodeAccess() {
    $this->listing([$this->request(128, 'Fuga en el calentador')], [$this->offer(128)]);

    foreach (myapi_test_db_queries() as $index => $query) {
      $this->assertSame([], $query['tags'], 'query ' . $index . ' carries no tag');
    }

    $this->assertStringNotContainsString('addTag', $this->codeWithoutComments());
  }

  /* -------------------------------------------------------------------------
   * The shape of the response.
   * ---------------------------------------------------------------------- */

  /**
   * The full body, compared whole and with types: the contract the app codes
   * against, with an awarded request and a brand-new one.
   */
  public function testFullAnswerHasTheDocumentedShape() {
    $result = $this->listing(
      [
        $this->request(128, 'Fuga en el calentador', [
          'created'                        => (string) self::CREATED,
          'description'                    => 'El calentador del baño principal gotea desde el lunes.',
          'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,
          'assigned_offer_id'              => '45',
          'assigned_offer_status'          => 'selected',
          'assigned_provider_id'           => '7',
          'assigned_provider_name'         => 'Plomería Rivas',
        ]),
        $this->request(127, 'Cambiar cerradura', [
          'created'                 => (string) (self::CREATED - 100),
          'description'             => 'La puerta de servicio no cierra.',
          'fcat.field_category_tid' => '14',
          'category_code'           => 'locksmith',
          'category_name'           => 'Cerrajería',
        ]),
      ],
      [$this->offer(128), $this->offer(128), $this->offer(128)]
    );

    $this->assertSame(200, $result['status']);
    $this->assertSame([
      'success' => TRUE,
      'data'    => [
        'service_requests' => [
          [
            'id'                => 128,
            'title'             => 'Fuga en el calentador',
            'description'       => 'El calentador del baño principal gotea desde el lunes.',
            'status'            => 'assigned',
            'category'          => ['id' => 12, 'code' => 'plumbing', 'name' => 'Plomería'],
            'offers_count'      => 3,
            'assigned_offer'    => ['id' => 45, 'status' => 'selected'],
            'assigned_provider' => ['id' => 7, 'name' => 'Plomería Rivas'],
            'created'           => date('Y-m-d\TH:i:s', self::CREATED),
            'desired_start'     => date('Y-m-d\TH:i:s', self::CREATED + 86400),
          ],
          [
            'id'                => 127,
            'title'             => 'Cambiar cerradura',
            'description'       => 'La puerta de servicio no cierra.',
            'status'            => 'open',
            'category'          => ['id' => 14, 'code' => 'locksmith', 'name' => 'Cerrajería'],
            'offers_count'      => 0,
            'assigned_offer'    => NULL,
            'assigned_provider' => NULL,
            'created'           => date('Y-m-d\TH:i:s', self::CREATED - 100),
            'desired_start'     => date('Y-m-d\TH:i:s', self::CREATED + 86400),
          ],
        ],
        'pagination' => ['total' => 2, 'page' => 1, 'limit' => 20, 'total_pages' => 1],
      ],
    ], $result['json']);
  }

  /**
   * Every item carries EXACTLY the ten documented keys, in the documented order
   * — the assertion that catches a column leaking into the listing — and `data`
   * carries only the two it should.
   */
  public function testEveryItemHasExactlyTenKeysInOrder() {
    $result = $this->listing([
      $this->request(128, 'Con adjudicación', [
        'assigned_offer_id'      => '45',
        'assigned_offer_status'  => 'selected',
        'assigned_provider_id'   => '7',
        'assigned_provider_name' => 'Plomería Rivas',
      ]),
      $this->request(127, 'Sin nada'),
    ]);

    foreach ($this->items($result) as $item) {
      $this->assertSame([
        'id',
        'title',
        'description',
        'status',
        'category',
        'offers_count',
        'assigned_offer',
        'assigned_provider',
        'created',
        'desired_start',
      ], array_keys($item));
      $this->assertSame(['id', 'code', 'name'], array_keys($item['category']));
    }

    $this->assertSame(['service_requests', 'pagination'], array_keys($result['json']['data']));
    $this->assertSame(['total', 'page', 'limit', 'total_pages'], array_keys($this->pagination($result)));
  }

  /**
   * Every id travels as a JSON INTEGER and never as the string the database
   * answers: a Dart client comparing 128 to "128" fails silently.
   */
  public function testEveryIdIsPrintedAsAnInteger() {
    $result = $this->listing(
      [
        $this->request(128, 'Fuga', [
          'assigned_offer_id'      => '45',
          'assigned_offer_status'  => 'selected',
          'assigned_provider_id'   => '7',
          'assigned_provider_name' => 'Plomería Rivas',
        ]),
      ],
      [$this->offer(128)]
    );

    $item = $this->items($result)[0];
    $this->assertSame(128, $item['id']);
    $this->assertSame(12, $item['category']['id']);
    $this->assertSame(1, $item['offers_count']);
    $this->assertSame(45, $item['assigned_offer']['id']);
    $this->assertSame(7, $item['assigned_provider']['id']);

    foreach (['"id":128', '"id":12', '"offers_count":1', '"id":45', '"id":7'] as $needle) {
      $this->assertStringContainsString($needle, $result['output'], $needle);
    }
    $this->assertStringNotContainsString('"id":"', $result['output']);
    $this->assertStringNotContainsString('"offers_count":"', $result['output']);
  }

  /**
   * The category carries the STABLE CODE beside the tid — the same
   * field_category_code /api/v1/service-categories and /api/v1/providers
   * already answer for that same term. It is the value the app hangs its
   * per-category logic on: the tid changes if the vocabulary is reimported and
   * the code does not.
   */
  public function testTheCategoryCarriesTheStableCodeBesideTheTid() {
    $result = $this->listing([
      $this->request(128, 'Fuga'),
      $this->request(127, 'Cerradura', [
        'fcat.field_category_tid' => '14',
        'category_code'           => 'locksmith',
        'category_name'           => 'Cerrajería',
      ]),
    ]);

    $items = $this->items($result);
    $this->assertSame(['id' => 12, 'code' => 'plumbing', 'name' => 'Plomería'], $items[0]['category']);
    $this->assertSame(['id' => 14, 'code' => 'locksmith', 'name' => 'Cerrajería'], $items[1]['category']);
    $this->assertStringContainsString('"code":"plumbing"', $result['output']);
  }

  /**
   * A term with no field_category_code answers `code: ""` and KEEPS its
   * request: the field is required on the vocabulary, so an empty one is
   * corrupt data and not a business case, and hiding the request would make it
   * vanish from the resident's own listing with no trace. Same criterion
   * /api/v1/service-categories and /api/v1/providers already apply, which is
   * why the join is the one LEFT among the category's three tables.
   */
  public function testATermWithNoCodeAnswersAnEmptyCodeAndKeepsItsRequest() {
    $result = $this->listing([
      $this->request(128, 'Fuga', ['category_code' => NULL]),
    ]);

    $item = $this->items($result)[0];
    $this->assertSame('', $item['category']['code']);
    $this->assertSame(12, $item['category']['id']);
    $this->assertSame(1, $this->pagination($result)['total']);
    $this->assertStringNotContainsString('"code":null', $result['output']);
  }

  /**
   * The status is the CATALOGUE KEY, in English and with no label beside it:
   * the six of SPEC 87 travel as themselves, and the Spanish labels of the back
   * office never reach the response.
   */
  public function testTheStatusIsTheCatalogueKeyWithNoLabel() {
    foreach (myapi_services_request_statuses() as $key => $label) {
      $result = $this->listing([
        $this->request(128, 'Fuga', ['frs.field_request_status_value' => $key]),
      ]);

      $this->assertSame($key, $this->items($result)[0]['status'], $key);
      $this->assertStringNotContainsString($label, $result['output'], $key . ': the Spanish label does not travel');
    }
  }

  /**
   * The description keeps the line breaks the resident typed: it travels AS
   * STORED, without myapi_text_to_plain(), which collapses them. Same treatment
   * GET /api/v1/claims already gives this very same shared field.
   */
  public function testTheDescriptionKeepsItsLineBreaks() {
    $description = "Primera línea.\nSegunda línea.\n\nCuarta línea.";

    $result = $this->listing([$this->request(128, 'Fuga', ['description' => $description])]);

    $this->assertSame($description, $this->items($result)[0]['description']);
  }

  /**
   * Both dates have the documented shape, identical to `created` in
   * GET /api/v1/claims — and field_desired_start is a real timestamp, so it is
   * formatted and not passed through as a stored string.
   */
  public function testBothDatesHaveTheDocumentedShape() {
    $result = $this->listing([$this->request(128, 'Fuga')]);

    $item = $this->items($result)[0];
    $this->assertSame(date('Y-m-d\TH:i:s', self::CREATED), $item['created']);
    $this->assertSame(date('Y-m-d\TH:i:s', self::CREATED + 86400), $item['desired_start']);
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $item['created']);
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $item['desired_start']);
  }

  /* -------------------------------------------------------------------------
   * The award: two sibling keys, nulled independently.
   * ---------------------------------------------------------------------- */

  /**
   * A request with no award answers BOTH keys null — never a missing key and
   * never an object with empty halves.
   */
  public function testARequestWithNoAwardAnswersBothKeysNull() {
    foreach ([
      MYAPI_SERVICES_REQUEST_STATUS_OPEN,
      MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
      MYAPI_SERVICES_REQUEST_STATUS_CANCELLED,
    ] as $status) {
      $result = $this->listing([
        $this->request(128, 'Fuga', ['frs.field_request_status_value' => $status]),
      ]);

      $item = $this->items($result)[0];
      $this->assertNull($item['assigned_offer'], $status);
      $this->assertNull($item['assigned_provider'], $status);
      $this->assertArrayHasKey('assigned_offer', $item, $status);
      $this->assertStringContainsString('"assigned_offer":null,"assigned_provider":null', $result['output'], $status);
    }
  }

  /**
   * THE ROW THAT JUSTIFIES TWO KEYS INSTEAD OF ONE OBJECT: a 'direct' request
   * has a provider and NO offer. An awarded: {offer, provider} would have to
   * answer either a whole null — losing the provider the resident chose — or an
   * object with half of it empty.
   */
  public function testADirectRequestAnswersTheProviderWithNoOffer() {
    $result = $this->listing([
      $this->request(128, 'Pintar la reja', [
        'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_DIRECT,
        'assigned_provider_id'           => '7',
        'assigned_provider_name'         => 'Plomería Rivas',
      ]),
    ]);

    $item = $this->items($result)[0];
    $this->assertSame('direct', $item['status']);
    $this->assertNull($item['assigned_offer']);
    $this->assertSame(['id' => 7, 'name' => 'Plomería Rivas'], $item['assigned_provider']);
    $this->assertSame(0, $item['offers_count'], 'a direct request went through no bidding round');
  }

  /**
   * An 'assigned' request answers both, and the offer's status is a key of the
   * OFFER catalogue — a different list from the request's, as SPEC 77 decided.
   */
  public function testAnAssignedRequestAnswersBothAndTheOfferStatusIsFromItsOwnCatalogue() {
    foreach (array_keys(myapi_services_offer_statuses()) as $offer_status) {
      $result = $this->listing([
        $this->request(128, 'Fuga', [
          'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,
          'assigned_offer_id'              => '45',
          'assigned_offer_status'          => $offer_status,
          'assigned_provider_id'           => '7',
          'assigned_provider_name'         => 'Plomería Rivas',
        ]),
      ]);

      $item = $this->items($result)[0];
      $this->assertSame(['id', 'status'], array_keys($item['assigned_offer']), $offer_status);
      $this->assertSame(['id' => 45, 'status' => $offer_status], $item['assigned_offer'], $offer_status);
      $this->assertArrayHasKey($item['assigned_offer']['status'], myapi_services_offer_statuses(), $offer_status);
      $this->assertSame(['id' => 7, 'name' => 'Plomería Rivas'], $item['assigned_provider'], $offer_status);
    }
  }

  /**
   * A REFERENCE THAT NO LONGER RESOLVES — the awarded node deleted or
   * unpublished — answers null AND THE REQUEST STAYS IN THE LISTING. Losing a
   * request of your own because of a broken reference is the worst failure a
   * read endpoint can have: nothing is shown and no error explains it.
   *
   * The fixture models the two absences the same way, because the chain of LEFT
   * JOINs answers NULL for both: no row in the field table, and a row pointing
   * at a node that is gone or unpublished.
   */
  public function testABrokenAwardReferenceAnswersNullAndKeepsTheRequestListed() {
    $result = $this->listing([
      $this->request(128, 'Con proveedor borrado', [
        'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,
        'assigned_offer_id'              => NULL,
        'assigned_offer_status'          => NULL,
        'assigned_provider_id'           => NULL,
        'assigned_provider_name'         => NULL,
      ]),
      $this->request(127, 'Normal'),
    ]);

    $this->assertSame(2, $this->pagination($result)['total']);
    $this->assertEqualsCanonicalizing([127, 128], $this->ids($result));

    $broken = $this->items($result)[0];
    $this->assertSame(128, $broken['id']);
    $this->assertNull($broken['assigned_offer']);
    $this->assertNull($broken['assigned_provider']);
  }

  /* -------------------------------------------------------------------------
   * offers_count.
   * ---------------------------------------------------------------------- */

  /**
   * No offers answers 0 — an int, never null and never a missing key.
   */
  public function testARequestWithNoOffersAnswersZero() {
    $result = $this->listing([$this->request(128, 'Fuga')]);

    $this->assertSame(0, $this->items($result)[0]['offers_count']);
    $this->assertStringContainsString('"offers_count":0', $result['output']);
    $this->assertStringNotContainsString('"offers_count":null', $result['output']);
  }

  /**
   * EVERY offer received counts, whatever its status: one 'sent', one
   * 'rejected' and one 'withdrawn' answer 3. "How many offers did I get" is the
   * question a listing answers, and an offer later withdrawn was still
   * received.
   */
  public function testEveryOfferCountsWhateverItsStatus() {
    $result = $this->listing(
      [$this->request(128, 'Fuga')],
      [
        $this->offer(128, ['no.field_offer_status_value' => 'sent']),
        $this->offer(128, ['no.field_offer_status_value' => 'rejected']),
        $this->offer(128, ['no.field_offer_status_value' => 'withdrawn']),
      ]
    );

    $this->assertSame(3, $this->items($result)[0]['offers_count']);
  }

  /**
   * An UNPUBLISHED offer does not count.
   */
  public function testAnUnpublishedOfferDoesNotCount() {
    $result = $this->listing(
      [$this->request(128, 'Fuga')],
      [
        $this->offer(128),
        $this->offer(128, ['no.status' => '0']),
      ]
    );

    $this->assertSame(1, $this->items($result)[0]['offers_count']);
  }

  /**
   * THE SILENT BUG THIS COUNT EXISTS TO AVOID: field_request is a field SHARED
   * by 'service_offer' and 'service_transaction' (SPEC 77), so a count that did
   * not filter by bundle would grow with every timeline entry — a number that
   * stays plausible and is simply too high.
   *
   * Five rows hang off the request here and only two are offers.
   */
  public function testTimelineEntriesDoNotCountAsOffers() {
    $result = $this->listing(
      [$this->request(128, 'Fuga')],
      [
        $this->offer(128),
        $this->offer(128, ['no.type' => MYAPI_SERVICES_TRANSACTION_TYPE]),
        $this->offer(128, ['no.type' => MYAPI_SERVICES_TRANSACTION_TYPE]),
        $this->offer(128, ['no.type' => MYAPI_SERVICES_TRANSACTION_TYPE]),
        $this->offer(128),
      ]
    );

    $this->assertSame(2, $this->items($result)[0]['offers_count']);
  }

  /**
   * And the structural half of the same guard: the count query INNER-joins node
   * and narrows it to the offer bundle and the published flag. A refactor that
   * drops either condition fails here as well as above.
   */
  public function testTheOfferCountQueryIsNarrowedToPublishedOffers() {
    $this->listing([$this->request(128, 'Fuga')], [$this->offer(128)]);

    $queries = myapi_test_db_queries('field_data_field_request');
    $this->assertCount(1, $queries, 'one query for the whole page');
    $count_query = $queries[0];

    $this->assertSame(['node'], array_column($count_query['joins'], 'table'));
    $this->assertSame(['INNER'], array_column($count_query['joins'], 'type'));

    $conditions = [];
    foreach ($count_query['conditions'] as $condition) {
      $conditions[$condition['field']] = $condition['value'];
    }
    $this->assertSame(MYAPI_SERVICES_OFFER_TYPE, $conditions['no.type']);
    $this->assertSame(1, $conditions['no.status']);
    $this->assertSame(['fq.field_request_target_id'], $count_query['group_by']);
  }

  /**
   * Each request gets ITS OWN count: the grouping by nid is what this catches,
   * and the failure would show one resident's offers under another request.
   */
  public function testEachRequestGetsItsOwnCount() {
    $result = $this->listing(
      [
        $this->request(128, 'Tres ofertas', ['created' => (string) self::CREATED]),
        $this->request(127, 'Una oferta', ['created' => (string) (self::CREATED - 10)]),
        $this->request(126, 'Ninguna', ['created' => (string) (self::CREATED - 20)]),
      ],
      [
        $this->offer(128),
        $this->offer(128),
        $this->offer(128),
        $this->offer(127),
        // An offer of a request that is not on this page at all.
        $this->offer(999),
      ]
    );

    $counts = [];
    foreach ($this->items($result) as $item) {
      $counts[$item['id']] = $item['offers_count'];
    }

    $this->assertSame([128 => 3, 127 => 1, 126 => 0], $counts);
  }

  /* -------------------------------------------------------------------------
   * The query string: '?status'.
   * ---------------------------------------------------------------------- */

  /**
   * Every key of the catalogue is a valid filter, and it filters.
   */
  public function testEveryCatalogueStatusFilters() {
    $requests = [];
    $nid = 100;
    foreach (array_keys(myapi_services_request_statuses()) as $status) {
      $requests[] = $this->request($nid, 'Solicitud ' . $status, [
        'frs.field_request_status_value' => $status,
        'created'                        => (string) (self::CREATED - $nid),
      ]);
      $nid++;
    }

    foreach (array_keys(myapi_services_request_statuses()) as $status) {
      $_GET['status'] = $status;

      $result = $this->listing($requests);

      $this->assertCount(1, $this->items($result), $status);
      $this->assertSame($status, $this->items($result)[0]['status'], $status);
      $this->assertSame(1, $this->pagination($result)['total'], $status);
    }
  }

  /**
   * A comma-separated list filters by all of them, and an unknown key inside
   * the list is dropped in SILENCE — '?status=open,invented' filters by 'open'
   * alone, with a 200 and no 422.
   */
  public function testAStatusListFiltersByEachValidKeyAndDropsTheRest() {
    $requests = [
      $this->request(128, 'Abierta', [
        'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_OPEN,
        'created'                        => (string) self::CREATED,
      ]),
      $this->request(127, 'Con ofertas', [
        'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
        'created'                        => (string) (self::CREATED - 10),
      ]),
      $this->request(126, 'Cerrada', [
        'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_CLOSED,
        'created'                        => (string) (self::CREATED - 20),
      ]),
    ];

    $_GET['status'] = 'open,offered';
    $both = $this->listing($requests);

    $_GET['status'] = 'open,inventado';
    $one = $this->listing($requests);

    $this->assertSame([128, 127], $this->ids($both));
    $this->assertSame(2, $this->pagination($both)['total']);
    $this->assertSame(200, $one['status']);
    $this->assertSame([128], $this->ids($one));
    $this->assertSame(1, $this->pagination($one)['total']);
  }

  /**
   * A '?status' with no valid key at all — and an array one — falls back to NO
   * FILTER with a 200, never a 422. Lax is the idiom of the whole module.
   */
  public function testAnInvalidStatusFallsBackToNoFilterSilently() {
    $requests = [
      $this->request(128, 'Abierta', ['created' => (string) self::CREATED]),
      $this->request(127, 'Cerrada', [
        'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_CLOSED,
        'created'                        => (string) (self::CREATED - 10),
      ]),
    ];

    foreach (['inventado', '', 'OPEN', 'abierta', ',', ['open']] as $value) {
      $_GET['status'] = $value;

      $result = $this->listing($requests);

      $this->assertSame(200, $result['status'], var_export($value, TRUE));
      $this->assertSame([128, 127], $this->ids($result), var_export($value, TRUE));
      $this->assertSame(2, $this->pagination($result)['total'], var_export($value, TRUE));
    }
  }

  /**
   * THE WHITELIST IS THE CATALOGUE AND NOT A LIST TYPED INTO THIS FILE: no
   * status key appears as a string literal in the resource's code.
   *
   * The day the catalogue gains a seventh status, the filter accepts it with no
   * change here — which is exactly what happened when SPEC 87 added 'direct'.
   * The guard reads the code with the comments stripped, because the docblocks
   * name the statuses on purpose when they explain the two award keys.
   */
  public function testNoStatusKeyIsTypedIntoTheResource() {
    $code = $this->codeWithoutComments();

    foreach (array_keys(myapi_services_request_statuses()) as $status) {
      $this->assertStringNotContainsString("'" . $status . "'", $code, $status);
      $this->assertStringNotContainsString('"' . $status . '"', $code, $status);
    }
  }

  /* -------------------------------------------------------------------------
   * The query string: '?category_id', the one strict parameter.
   * ---------------------------------------------------------------------- */

  /**
   * With a tid, only the requests of that category come back — and `total`
   * counts the filtered set, not the whole listing.
   */
  public function testTheCategoryFilterNarrowsBothTheListAndTheTotal() {
    $requests = [
      $this->request(128, 'Fuga', [
        'fcat.field_category_tid' => '12',
        'created'                 => (string) self::CREATED,
      ]),
      $this->request(127, 'Cerradura', [
        'fcat.field_category_tid' => '14',
        'created'                 => (string) (self::CREATED - 10),
      ]),
      $this->request(126, 'Grifo', [
        'fcat.field_category_tid' => '12',
        'created'                 => (string) (self::CREATED - 20),
      ]),
    ];

    $_GET['category_id'] = '12';

    $result = $this->listing($requests);

    $this->assertSame(200, $result['status']);
    $this->assertSame([128, 126], $this->ids($result));
    $this->assertSame(2, $this->pagination($result)['total']);
  }

  /**
   * A well-formed tid that no request carries — a term of another vocabulary,
   * or one that no longer exists — answers 200 with an empty list and total 0.
   * NOT a 422 and NOT a 404: the endpoint filters, it does not validate the
   * catalogue, and a 404 would say the endpoint does not exist rather than the
   * category.
   */
  public function testAnUnknownButWellFormedCategoryIdIsAnEmptyList() {
    $_GET['category_id'] = '999999';

    $result = $this->listing([$this->request(128, 'Fuga')]);

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $this->items($result));
    $this->assertSame(0, $this->pagination($result)['total']);
    $this->assertSame(0, $this->pagination($result)['total_pages']);
  }

  /**
   * Every malformed category_id is a 422 invalid_field naming the parameter —
   * the ONE 422 of this endpoint. The empty string is in the list on purpose:
   * '?category_id=' is a present-and-broken value, not an absent one.
   */
  public function testAMalformedCategoryIdIs422InvalidField() {
    foreach (['abc', '0', '-3', '', '1.5', ' 1', '+1', 'null'] as $value) {
      $this->authenticate();
      $this->seed([$this->request(128, 'Fuga')]);
      $_GET['category_id'] = $value;

      $result = $this->dispatch();

      $this->assertSame(422, $result['status'], var_export($value, TRUE));
      $this->assertFalse($result['json']['success'], var_export($value, TRUE));
      $this->assertSame('invalid_field', $result['json']['error_code'], var_export($value, TRUE));
      $this->assertStringContainsString('category_id', $result['json']['error'], var_export($value, TRUE));
    }
  }

  /**
   * '?category_id[]=1' — an array where a scalar is expected — is the same 422
   * and NOT a PHP notice: is_scalar() is checked before the string cast.
   */
  public function testAnArrayCategoryIdIs422AndNotAWarning() {
    $this->authenticate();
    $this->seed([$this->request(128, 'Fuga')]);
    $_GET['category_id'] = ['1'];

    $result = $this->dispatch();

    $this->assertSame(422, $result['status']);
    $this->assertSame('invalid_field', $result['json']['error_code']);
  }

  /**
   * The 422 is answered BEFORE any listing query: a malformed filter costs the
   * token lookup and nothing else.
   */
  public function testTheMalformedFilterCostsNoListingQuery() {
    $this->authenticate();
    $this->seed([$this->request(128, 'Fuga')]);
    $_GET['category_id'] = 'abc';

    $this->dispatch();

    $this->assertSame(['my_api_tokens'], $this->queriedTables());
  }

  /**
   * The two filters compose with AND, and the pagination describes the result
   * of the two together.
   */
  public function testTheTwoFiltersComposeWithAnd() {
    $requests = [
      $this->request(128, 'Plomería abierta', [
        'fcat.field_category_tid'        => '12',
        'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_OPEN,
        'created'                        => (string) self::CREATED,
      ]),
      $this->request(127, 'Plomería cerrada', [
        'fcat.field_category_tid'        => '12',
        'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_CLOSED,
        'created'                        => (string) (self::CREATED - 10),
      ]),
      $this->request(126, 'Cerrajería abierta', [
        'fcat.field_category_tid'        => '14',
        'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_OPEN,
        'created'                        => (string) (self::CREATED - 20),
      ]),
    ];

    $_GET['category_id'] = '12';
    $_GET['status'] = 'open,offered';

    $result = $this->listing($requests);

    $this->assertSame([128], $this->ids($result));
    $this->assertSame(1, $this->pagination($result)['total']);
    $this->assertSame(1, $this->pagination($result)['total_pages']);
  }

  /**
   * THE TWO IDIOMS OF VALIDATION LIVE SIDE BY SIDE ON PURPOSE, and this pins
   * them both so that "fixing the inconsistency" by levelling them breaks the
   * suite: '?category_id=abc' is a 422 and '?status=abc' is a 200 with no
   * filter. The coherence that matters is that of the SAME parameter across
   * sibling endpoints — '?category_id' already answers 422 in
   * GET /api/v1/providers — not that of different parameters inside one.
   */
  public function testCategoryIdIsStrictAndStatusIsLaxInTheSameRequest() {
    $this->authenticate();
    $this->seed([$this->request(128, 'Fuga')]);
    $_GET['status'] = 'abc';

    $lax = $this->dispatch();

    $this->authenticate();
    $this->seed([$this->request(128, 'Fuga')]);
    $_GET['category_id'] = 'abc';

    $strict = $this->dispatch();

    $this->assertSame(200, $lax['status']);
    $this->assertSame([128], $this->ids($lax));
    $this->assertSame(422, $strict['status']);
  }

  /* -------------------------------------------------------------------------
   * The query string: '?date_from' / '?date_to', the range over n.created.
   *
   * THE COLUMN IS THE ONE '?sort' ALREADY ORDERS BY, and the bounds are DAYS
   * against an INSTANT — which is where every case below lives: the day named
   * by '?date_to' has to be included whole, or a resident filtering by today
   * loses everything they asked for after midnight.
   * ---------------------------------------------------------------------- */

  /**
   * With both bounds, only the requests created inside the range come back —
   * and `total` counts the filtered set, not the whole listing.
   */
  public function testTheDateRangeNarrowsBothTheListAndTheTotal() {
    $_GET['date_from'] = $this->day(0);
    $_GET['date_to'] = $this->day(0);

    $result = $this->listing($this->requestsOnDays([-1, 0, 1]));

    $this->assertSame(200, $result['status']);
    $this->assertSame([200], $this->ids($result));
    $this->assertSame(1, $this->pagination($result)['total']);
    $this->assertSame(1, $this->pagination($result)['total_pages']);
  }

  /**
   * BOTH BOUNDS ARE INCLUSIVE OF THE WHOLE DAY, and this is the case the
   * conversion to timestamps exists for: a request created at 00:00:00 and one
   * created at 23:59:59 of the same day both survive '?date_from=D&date_to=D',
   * while the second before and the second after do not. Comparing the day
   * strings against a bare midnight would drop the 23:59:59 one — the whole
   * afternoon of the last day — with no error to explain it.
   */
  public function testEachBoundIsInclusiveOfTheWholeDay() {
    $requests = [
      $this->request(128, 'Primer segundo del día', ['created' => (string) $this->dayStart(0)]),
      $this->request(127, 'Último segundo del día', ['created' => (string) $this->dayEnd(0)]),
      $this->request(126, 'Un segundo antes', ['created' => (string) $this->dayEnd(-1)]),
      $this->request(125, 'Un segundo después', ['created' => (string) $this->dayStart(1)]),
    ];

    $_GET['date_from'] = $this->day(0);
    $_GET['date_to'] = $this->day(0);

    $result = $this->listing($requests);

    $this->assertSame([127, 128], $this->ids($result));
    $this->assertSame(2, $this->pagination($result)['total']);
  }

  /**
   * The bounds are independent: '?date_from' alone is open-ended forward.
   */
  public function testDateFromAloneIsOpenEndedForward() {
    $_GET['date_from'] = $this->day(0);

    $result = $this->listing($this->requestsOnDays([-1, 0, 1]));

    $this->assertSame([201, 200], $this->ids($result));
    $this->assertSame(2, $this->pagination($result)['total']);
  }

  /**
   * And '?date_to' alone is open-ended backward.
   */
  public function testDateToAloneIsOpenEndedBackward() {
    $_GET['date_to'] = $this->day(0);

    $result = $this->listing($this->requestsOnDays([-1, 0, 1]));

    $this->assertSame([200, 199], $this->ids($result));
    $this->assertSame(2, $this->pagination($result)['total']);
  }

  /**
   * An inverted range (from > to) drops the WHOLE filter instead of answering
   * the empty set it literally describes — the shared parser's rule, and the
   * same one bulletins, payments and claims already follow. A client that swaps
   * two date pickers gets its listing back, not a blank screen.
   */
  public function testAnInvertedRangeDropsTheWholeFilter() {
    $_GET['date_from'] = $this->day(1);
    $_GET['date_to'] = $this->day(-1);

    $result = $this->listing($this->requestsOnDays([-1, 0, 1]));

    $this->assertSame(200, $result['status']);
    $this->assertSame([201, 200, 199], $this->ids($result));
    $this->assertSame(3, $this->pagination($result)['total']);
  }

  /**
   * A malformed bound is dropped in silence, never a 422 — the lax idiom of
   * every parameter of this endpoint except '?category_id'. The list is not
   * decorative: '2026-13-05' and '2026-02-30' pass the pattern and are not
   * dates (checkdate() has the last word), the trailing newline is the SPEC 73
   * bug, the empty string is a present-and-broken value, and the array is the
   * one that would be a PHP warning without the is_string() guard.
   */
  public function testAMalformedDateBoundIsIgnoredSilently() {
    $values = ['abc', '2026-13-05', '2026-02-30', '', '18-08-2026', '2026-8-6', "2026-08-06\n", ['2026-08-06'], '2026-08-06 10:00:00'];

    foreach ($values as $value) {
      foreach (['date_from', 'date_to'] as $param) {
        $this->authenticate();
        $this->seed($this->requestsOnDays([-1, 0, 1]));
        $this->clearQueryString();
        $_GET[$param] = $value;

        $result = $this->dispatch();

        $label = $param . '=' . var_export($value, TRUE);
        $this->assertSame(200, $result['status'], $label);
        $this->assertSame([201, 200, 199], $this->ids($result), $label);
        $this->assertSame(3, $this->pagination($result)['total'], $label);
      }
    }
  }

  /**
   * A valid bound beside a malformed one still filters: the two are validated
   * one by one, so '?date_from=2026-13-05&date_to=D' is "everything up to D"
   * and not "no filter at all". Only the INVERTED case drops both.
   */
  public function testAMalformedBoundDoesNotDropItsValidTwin() {
    $_GET['date_from'] = '2026-13-05';
    $_GET['date_to'] = $this->day(0);

    $result = $this->listing($this->requestsOnDays([-1, 0, 1]));

    $this->assertSame([200, 199], $this->ids($result));
    $this->assertSame(2, $this->pagination($result)['total']);
  }

  /**
   * The range composes with the other two filters with AND, and `pagination`
   * describes the result of the three together.
   */
  public function testTheDateRangeComposesWithTheOtherFilters() {
    $requests = [
      $this->request(128, 'Plomería abierta de hoy', [
        'fcat.field_category_tid'        => '12',
        'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_OPEN,
        'created'                        => (string) ($this->dayStart(0) + 3600),
      ]),
      $this->request(127, 'Plomería abierta de ayer', [
        'fcat.field_category_tid'        => '12',
        'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_OPEN,
        'created'                        => (string) ($this->dayStart(-1) + 3600),
      ]),
      $this->request(126, 'Plomería cerrada de hoy', [
        'fcat.field_category_tid'        => '12',
        'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_CLOSED,
        'created'                        => (string) ($this->dayStart(0) + 7200),
      ]),
      $this->request(125, 'Cerrajería abierta de hoy', [
        'fcat.field_category_tid'        => '14',
        'frs.field_request_status_value' => MYAPI_SERVICES_REQUEST_STATUS_OPEN,
        'created'                        => (string) ($this->dayStart(0) + 10800),
      ]),
    ];

    $_GET['category_id'] = '12';
    $_GET['status'] = 'open';
    $_GET['date_from'] = $this->day(0);
    $_GET['date_to'] = $this->day(0);

    $result = $this->listing($requests);

    $this->assertSame([128], $this->ids($result));
    $this->assertSame(1, $this->pagination($result)['total']);
  }

  /**
   * THE RANGE IS FILTERED IN THE DATABASE, IN BOTH QUERIES. The structural half
   * of the cases above: the count and the page carry the same two conditions on
   * n.created, which is what makes `total` describe the rows the pages return
   * instead of the whole listing. Filtering the page in PHP would pass the
   * assertions on the items and quietly break the pagination block.
   */
  public function testBothQueriesCarryTheRangeAsSqlConditionsOnCreated() {
    $_GET['date_from'] = $this->day(0);
    $_GET['date_to'] = $this->day(0);

    $this->listing($this->requestsOnDays([-1, 0, 1]));

    $queries = myapi_test_db_queries();

    foreach ([1 => 'count', 2 => 'page'] as $index => $label) {
      $created = [];
      foreach ($queries[$index]['conditions'] as $condition) {
        if ($condition['field'] === 'n.created') {
          $created[$condition['operator']] = $condition['value'];
        }
      }

      $this->assertSame([
        '>=' => $this->dayStart(0),
        '<=' => $this->dayEnd(0),
      ], $created, $label);
    }
  }

  /**
   * The parsing is NOT written in this resource: the ISO validation and the
   * inverted-range rule live in myapi_parse_date_range_param() (SPEC 73), and a
   * fourth copy of that regular expression here would be the duplication Regla
   * 3 de CLAUDE.md forbids — and would resurrect the newline bug the shared one
   * fixed. This guard fails the day someone re-implements it in the resource.
   */
  public function testTheDateParsingIsTheSharedHelperAndNotACopy() {
    $code = $this->codeWithoutComments();

    $this->assertStringContainsString('myapi_parse_date_range_param()', $code);
    $this->assertStringNotContainsString('checkdate(', $code);
    $this->assertStringNotContainsString("\$_GET['date_from']", $code);
    $this->assertStringNotContainsString("\$_GET['date_to']", $code);
  }

  /* -------------------------------------------------------------------------
   * Pagination and ordering.
   * ---------------------------------------------------------------------- */

  /**
   * Page 2 is the next slice: no item of page 1 repeats and none is skipped.
   */
  public function testPageTwoContinuesWherePageOneEnded() {
    $requests = $this->manyRequests(12);
    $_GET['limit'] = '5';

    $first = $this->listing($requests);
    $_GET['page'] = '2';
    $second = $this->listing($requests);
    $_GET['page'] = '3';
    $third = $this->listing($requests);

    $ids = array_merge($this->ids($first), $this->ids($second), $this->ids($third));

    $this->assertCount(5, $this->ids($first));
    $this->assertCount(5, $this->ids($second));
    $this->assertCount(2, $this->ids($third));
    $this->assertSame($ids, array_unique($ids), 'no item is served twice');
    $this->assertCount(12, $ids, 'no item is skipped');
    $this->assertSame(['total' => 12, 'page' => 2, 'limit' => 5, 'total_pages' => 3], $this->pagination($second));
  }

  /**
   * '?limit=-1' answers everything on ONE page, with page 1 and total_pages 1 —
   * the pagination contract of this module since SPEC 15.
   */
  public function testLimitMinusOneAnswersEverythingOnOnePage() {
    $_GET['limit'] = '-1';
    $_GET['page'] = '5';

    $result = $this->listing($this->manyRequests(30));

    $this->assertCount(30, $this->items($result));
    $this->assertSame(
      ['total' => 30, 'page' => 1, 'limit' => -1, 'total_pages' => 1],
      $this->pagination($result)
    );
  }

  /**
   * The ceiling: '?limit=999' is cut to 50, and the block says 50.
   */
  public function testLimitAboveFiftyIsCutToFifty() {
    $_GET['limit'] = '999';

    $result = $this->listing($this->manyRequests(55));

    $this->assertCount(50, $this->items($result));
    $this->assertSame(50, $this->pagination($result)['limit']);
    $this->assertSame(55, $this->pagination($result)['total']);
  }

  /**
   * Every other invalid page/limit/sort falls back to its default IN SILENCE,
   * with a 200 and never a 422.
   */
  public function testInvalidPaginationAndSortFallBackSilently() {
    $requests = $this->manyRequests(30);

    foreach ([
      ['page' => 'abc'],
      ['page' => '0'],
      ['page' => '-1'],
      ['page' => ''],
      ['limit' => '0'],
      ['limit' => 'abc'],
      ['limit' => ''],
      ['sort' => 'arriba'],
      ['sort' => 'DESC'],
      ['sort' => ''],
    ] as $case) {
      $this->clearQueryString();
      foreach ($case as $key => $value) {
        $_GET[$key] = $value;
      }

      $result = $this->listing($requests);

      $this->assertSame(200, $result['status'], json_encode($case));
      $this->assertSame(
        ['total' => 30, 'page' => 1, 'limit' => 20, 'total_pages' => 2],
        $this->pagination($result),
        json_encode($case)
      );
      $this->assertSame(100, $this->ids($result)[0], json_encode($case) . ': the default order holds');
    }
  }

  /**
   * A page past the last one is an empty list with a 200 — never a 404 — and the
   * block still reports the real total.
   */
  public function testAPageBeyondTheLastOneIsAnEmptyList() {
    $_GET['limit'] = '5';
    $_GET['page'] = '99';

    $result = $this->listing($this->manyRequests(7));

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $this->items($result));
    $this->assertSame(7, $this->pagination($result)['total']);
    $this->assertSame(2, $this->pagination($result)['total_pages']);
    $this->assertSame(99, $this->pagination($result)['page']);
  }

  /**
   * '?sort=asc' reverses the listing, and `total` does not move.
   */
  public function testSortAscReversesTheListingAndNotTheTotal() {
    $requests = $this->manyRequests(4);

    $descending = $this->listing($requests);
    $_GET['sort'] = 'asc';
    $ascending = $this->listing($requests);

    $this->assertSame([100, 101, 102, 103], $this->ids($descending));
    $this->assertSame([103, 102, 101, 100], $this->ids($ascending));
    $this->assertSame(4, $this->pagination($descending)['total']);
    $this->assertSame(4, $this->pagination($ascending)['total']);
  }

  /**
   * THE TIE-BREAK: two requests created in the SAME second always come back in
   * the same order, greater nid first. Without it one of them repeats on page 1
   * and the other is never shown — the hardest pagination bug there is to
   * reproduce.
   */
  public function testTiesAreBrokenByNidAndAreStable() {
    $requests = [
      $this->request(128, 'A', ['created' => (string) self::CREATED]),
      $this->request(177, 'B', ['created' => (string) self::CREATED]),
      $this->request(152, 'C', ['created' => (string) self::CREATED]),
    ];

    $first = $this->listing($requests);
    $second = $this->listing($requests);

    $this->assertSame([177, 152, 128], $this->ids($first));
    $this->assertSame($first['output'], $second['output']);
  }

  /**
   * And the structural half: the ORDER BY is exactly the two criteria, in that
   * order and in the same direction.
   */
  public function testTheOrderByIsCreatedThenNid() {
    foreach (['desc' => 'DESC', 'asc' => 'ASC'] as $sort => $direction) {
      $_GET['sort'] = $sort;

      $this->listing([$this->request(128, 'Fuga')]);

      $page = myapi_test_db_queries()[2];

      $this->assertSame([
        ['field' => 'n.created', 'direction' => $direction],
        ['field' => 'n.nid', 'direction' => $direction],
      ], $page['order'], $sort);
    }
  }

  /* -------------------------------------------------------------------------
   * The cost and the shape of the queries.
   * ---------------------------------------------------------------------- */

  /**
   * THREE LISTING QUERIES, whatever the page size: the count, the page and the
   * offers of that page — never one query per request. One request and fifty
   * cost the same three, plus the token lookup.
   */
  public function testTheRequestCostsThreeListingQueriesWhateverThePageSize() {
    $expected = ['my_api_tokens', 'node', 'node', 'field_data_field_request'];

    $this->listing([$this->request(128, 'Fuga')], [$this->offer(128)]);
    $this->assertSame($expected, $this->queriedTables(), 'one request');

    $requests = $this->manyRequests(50);
    $offers = [];
    foreach ($requests as $request) {
      $offers[] = $this->offer($request['nid']);
      $offers[] = $this->offer($request['nid']);
    }

    $_GET['limit'] = '50';
    $this->listing($requests, $offers);

    $this->assertSame($expected, $this->queriedTables(), 'fifty requests');
  }

  /**
   * An empty page runs no offer query at all: there is no nid to ask about, and
   * an unbounded read over field_data_field_request is what the early return
   * avoids — an "IN ()" is invalid SQL in D7.
   */
  public function testAnEmptyPageRunsNoOfferQuery() {
    $result = $this->listing([]);

    $this->assertSame(200, $result['status']);
    $this->assertSame(['my_api_tokens', 'node', 'node'], $this->queriedTables());
  }

  /**
   * The count query carries the scope and the category joins — everything that
   * decides WHICH ROWS EXIST — and does NOT drag the presentation joins along:
   * they add columns, never rows. That frontier is what makes `total` describe
   * exactly the rows the pages return.
   */
  public function testTheCountQueryCarriesTheScopeButNotThePresentationJoins() {
    $this->listing([$this->request(128, 'Fuga')]);

    $count = myapi_test_db_queries()[1];

    $this->assertTrue($count['count'], 'the second query is the COUNT');
    $this->assertSame([
      'field_data_field_requester'      => 'INNER',
      'field_data_field_category'       => 'INNER',
      'taxonomy_term_data'              => 'INNER',
      'field_data_field_request_status' => 'LEFT',
    ], array_combine(
      array_column($count['joins'], 'table'),
      array_column($count['joins'], 'type')
    ));

    $conditions = [];
    foreach ($count['conditions'] as $condition) {
      $conditions[$condition['field']] = $condition['value'];
    }
    $this->assertSame(MYAPI_SERVICES_REQUEST_TYPE, $conditions['n.type']);
    $this->assertSame(1, $conditions['n.status']);
    $this->assertSame(self::UID, $conditions['fr.field_requester_target_id']);
  }

  /**
   * The page query adds the presentation joins, and every one of them is LEFT:
   * the shape assertion behind "a request whose awarded provider was deleted is
   * still listed". The category stays INNER — the one deliberate exception,
   * because field_category is required and a service listing with no category
   * is not actionable.
   */
  public function testThePageQueryLeftJoinsEveryPresentationTable() {
    $this->listing([$this->request(128, 'Fuga')]);

    $page = myapi_test_db_queries()[2];

    $joins = [];
    foreach ($page['joins'] as $join) {
      $joins[$join['table'] . ' ' . $join['alias']] = $join['type'];
    }

    $this->assertSame([
      'field_data_field_requester fr'          => 'INNER',
      'field_data_field_category fcat'         => 'INNER',
      'taxonomy_term_data td'                  => 'INNER',
      'field_data_field_request_status frs'    => 'LEFT',
      // The category CODE is LEFT even though its two term joins are INNER: a
      // term with no field_category_code answers code "" and keeps its request.
      'field_data_field_category_code cc'      => 'LEFT',
      'field_data_field_description fd'        => 'LEFT',
      'field_data_field_desired_start fds'     => 'LEFT',
      'field_data_field_assigned_offer fao'    => 'LEFT',
      'node no'                                => 'LEFT',
      'field_data_field_offer_status fos'      => 'LEFT',
      'field_data_field_assigned_provider fap' => 'LEFT',
      'node np'                                => 'LEFT',
    ], $joins);
  }

  /**
   * The two node joins of the award are narrowed to a PUBLISHED node of the
   * right bundle inside their ON clause — never as a WHERE condition, which on
   * a LEFT JOIN would turn it into an inner one and drop the request from the
   * listing instead of nulling the key.
   */
  public function testTheAwardJoinsAreNarrowedInsideTheirOnClause() {
    $this->listing([$this->request(128, 'Fuga')]);

    $page = myapi_test_db_queries()[2];

    $conditions = [];
    foreach ($page['joins'] as $join) {
      $conditions[$join['alias']] = $join['condition'];
    }

    foreach (['no' => ':offer_type', 'np' => ':provider_type'] as $alias => $placeholder) {
      $this->assertStringContainsString($alias . '.type = ' . $placeholder, $conditions[$alias], $alias);
      $this->assertStringContainsString($alias . '.status = 1', $conditions[$alias], $alias);
    }
  }

}
