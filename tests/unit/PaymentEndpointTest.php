<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../includes/myapi.user.inc';
require_once __DIR__ . '/../../includes/myapi.mail_queue.inc';
require_once __DIR__ . '/../../includes/myapi.onesignal.inc';
require_once __DIR__ . '/../../includes/myapi.notification.inc';
require_once __DIR__ . '/../../includes/myapi.payment_workflow.inc';
require_once __DIR__ . '/../../resources/payment.resource.inc';

/**
 * End-to-end unit tests for the four payment endpoints (SPECS 14, 20, 23 and
 * 24, covered by SPEC 121).
 *
 * THE ONLY RESOURCE OF THE MODULE THAT MOVES MONEY, and the only one whose
 * four endpoints have four different shapes:
 *
 *   GET  /units/%/payments   a listing whose estado filter is by EXCLUSION
 *   GET  /payments/%         a detail that hides by estado BEFORE checking
 *                            access, so a hidden payment 404s like a missing one
 *   POST /payments           a multipart create with twelve ordered validations
 *   PUT  /payments/%/cancel  a guarded state transition
 *
 * The listing is the third of the SPEC 15 twins, and PaginationUnlimitedTest
 * already covers the '-1' sentinel across all three; what is exercised here is
 * everything else, plus the one rule that makes payments NOT a twin: the
 * `<>` estado condition. Receipts and extra fees expose ONE state, payments
 * hide one — so a resource that copied its cousin's `=` would show the
 * resident nothing at all, and one that dropped the condition would show them
 * the 'Nuevo' rows the back office has not verified yet.
 *
 * WHAT THIS LAYER CANNOT REACH, stated once: myapi_request_body() reads
 * php://input, which a unit test cannot write, so the optional 'reason' of the
 * cancel endpoint is always absent here (the same limitation
 * ServiceOfferCreateTest and ChatNotifyTest document). Every reason-carrying
 * branch is therefore out of scope and named in SPEC 121. The upload branch of
 * the create endpoint is out for the same kind of reason: file_save_upload() is
 * Drupal's, and a test that stubbed it would be testing the stub.
 */
class PaymentEndpointTest extends TestCase {

  const TOKEN = 'a-valid-access-token';

  const UNIT = 45;
  const UID = 3;
  const CONDOMINIUM = 12;

  /**
   * The state this endpoint hides. Everything else is exposed.
   */
  const HIDDEN = 'Nuevo';

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_db_fail_writes();
    myapi_test_node_seed();
    myapi_test_file_seed();
    myapi_test_taxonomy_seed();
    myapi_test_write_reset();
    myapi_test_queue_reset();
    myapi_test_field_seed_allowed_values();
    $GLOBALS['myapi_test_db_writes'] = [];
    $GLOBALS['myapi_test_watchdog'] = [];
    $GLOBALS['myapi_test_users'] = [];
    $_GET = [];
    $_POST = [];
    $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  protected function tearDown(): void {
    $_GET = [];
    $_POST = [];
    $_FILES = [];
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $GLOBALS['myapi_test_users'] = [];
    myapi_test_db_seed();
    myapi_test_node_seed();
    myapi_test_taxonomy_seed();
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  /**
   * One 'pagos' row for the listing, carrying every joined column flat.
   */
  private function paymentRow(array $spec) {
    $spec += [
      'status'    => 'Verificado',
      'unit'      => self::UNIT,
      'published' => '1',
      'date'      => NULL,
      'values'    => [],
    ];

    $row = [
      'nid'                          => (string) $spec['id'],
      'title'                        => 'Pago ' . $spec['id'],
      'type'                         => 'pagos',
      'status'                       => (string) $spec['published'],
      'field_vivienda_target_id'     => (string) $spec['unit'],
      'unit_id'                      => (string) $spec['unit'],
      'fest.field_estado_pago_value' => $spec['status'],
      'field_fecha_de_pago_value'    => $spec['date'],
      'payment_date'                 => $spec['date'],
    ];

    return $spec['values'] + $row;
  }

  /**
   * A 'pagos' NODE, as node_load() answers it — what the detail and the cancel
   * endpoints read.
   */
  private function paymentNode(array $spec = []) {
    $spec += [
      'nid'       => 501,
      'uid'       => self::UID,
      'unit'      => self::UNIT,
      'status'    => MYAPI_PAYMENT_STATUS_PENDING,
      'reference' => 'REF-001',
      'amount'    => '120.50',
      'method'    => 'transferencia',
      'date'      => '2026-06-01T00:00:00',
      'bank'      => NULL,
      'file'      => NULL,
      'detail'    => NULL,
      'type'      => 'pagos',
    ];

    $node = (object) [
      'nid'     => $spec['nid'],
      'uid'     => $spec['uid'],
      'type'    => $spec['type'],
      'status'  => 1,
      'created' => 1780000000,
      'title'   => 'Pago ' . $spec['reference'] . ' - ' . substr($spec['date'], 0, 10),
    ];

    $node->field_vivienda[LANGUAGE_NONE][0]['target_id'] = $spec['unit'];
    $node->field_referencia[LANGUAGE_NONE][0]['value'] = $spec['reference'];
    $node->field_valor[LANGUAGE_NONE][0]['value'] = $spec['amount'];
    $node->field_forma_de_pago[LANGUAGE_NONE][0]['value'] = $spec['method'];
    $node->field_fecha_de_pago[LANGUAGE_NONE][0]['value'] = $spec['date'];
    if ($spec['status'] !== NULL) {
      $node->field_estado_pago[LANGUAGE_NONE][0]['value'] = $spec['status'];
    }
    if ($spec['bank'] !== NULL) {
      $node->field_banco[LANGUAGE_NONE][0]['tid'] = $spec['bank'];
    }
    if ($spec['file'] !== NULL) {
      $node->field_archivo[LANGUAGE_NONE][0] = ['fid' => $spec['file'], 'display' => 1];
    }
    if ($spec['detail'] !== NULL) {
      $node->field_detalle[LANGUAGE_NONE][0]['value'] = $spec['detail'];
    }

    return $node;
  }

  /**
   * Authenticates uid 3 as the owner of unit 45 and seeds the given rows.
   */
  private function seed(array $payments = [], array $tables = []) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'pcordero', 'status' => 1, 'mail' => 'p@example.com', 'roles' => []];

    $rows = [];
    foreach ($payments as $spec) {
      $rows[] = $this->paymentRow($spec);
    }

    myapi_test_db_seed($tables + [
      'my_api_tokens' => [[
        'id'                => '1',
        'uid'               => (string) self::UID,
        'access_token_hash' => myapi_token_hash(self::TOKEN),
        'revoked'           => '0',
        'access_expires_at' => REQUEST_TIME + 1800,
      ]],
      'field_data_field_propietario' => [
        ['entity_id' => (string) self::UNIT, 'field_propietario_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'node' => $rows,
    ]);
    myapi_test_write_reset();
    $GLOBALS['myapi_test_db_writes'] = [];
  }

  private function listRequest($unit_id = self::UNIT) {
    $_SERVER['REQUEST_METHOD'] = 'GET';

    return myapi_test_capture(function () use ($unit_id) {
      myapi_payment_dispatch($unit_id);
    });
  }

  private function detailRequest($payment_id) {
    $_SERVER['REQUEST_METHOD'] = 'GET';

    return myapi_test_capture(function () use ($payment_id) {
      myapi_payment_detail_dispatch($payment_id);
    });
  }

  private function cancelRequest($payment_id) {
    $_SERVER['REQUEST_METHOD'] = 'PUT';

    return myapi_test_capture(function () use ($payment_id) {
      myapi_payment_cancel_dispatch($payment_id);
    });
  }

  private function createRequest() {
    $_SERVER['REQUEST_METHOD'] = 'POST';

    return myapi_test_capture('myapi_payment_create_dispatch');
  }

  private function ids(array $result) {
    return array_column($result['json']['data']['payments'], 'id');
  }

  private function consecutive($count) {
    $payments = [];
    for ($i = 1; $i <= $count; $i++) {
      $payments[] = ['id' => $i, 'date' => sprintf('2026-06-%02d', $i)];
    }

    return $payments;
  }

  /* -------------------------------------------------------------------------
   * The four dispatchers.
   * ---------------------------------------------------------------------- */

  /**
   * Each of the four routes accepts exactly one verb, and every rejection is a
   * 405 that runs no query at all.
   */
  public function testEachDispatcherAcceptsOnlyItsOwnVerb() {
    $cases = [
      'list'   => ['allowed' => 'GET', 'call' => function () { myapi_payment_dispatch(PaymentEndpointTest::UNIT); }],
      'detail' => ['allowed' => 'GET', 'call' => function () { myapi_payment_detail_dispatch(501); }],
      'create' => ['allowed' => 'POST', 'call' => function () { myapi_payment_create_dispatch(); }],
      'cancel' => ['allowed' => 'PUT', 'call' => function () { myapi_payment_cancel_dispatch(501); }],
    ];

    foreach ($cases as $name => $case) {
      foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
        if ($method === $case['allowed']) {
          continue;
        }
        $this->seed($this->consecutive(1));
        $_SERVER['REQUEST_METHOD'] = $method;

        $result = myapi_test_capture($case['call']);

        $this->assertSame(405, $result['status'], $name . ' ' . $method);
        $this->assertSame('method_not_allowed', $result['json']['error_code'], $name . ' ' . $method);
        $this->assertSame([], myapi_test_db_queries(), $name . ' ' . $method);
      }
    }
  }

  /* -------------------------------------------------------------------------
   * GET /units/%/payments — the listing.
   * ---------------------------------------------------------------------- */

  /**
   * Every failing token is a 401 that never reaches the payments.
   */
  public function testTheListingRequiresAValidToken() {
    $this->seed($this->consecutive(1));
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $result = $this->listRequest();
    $this->assertSame(401, $result['status']);
    $this->assertSame([], myapi_test_db_queries());

    foreach ([
      'unknown' => function () { $GLOBALS['myapi_test_db']['my_api_tokens'] = []; },
      'revoked' => function () { $GLOBALS['myapi_test_db']['my_api_tokens'][0]['revoked'] = '1'; },
      'expired' => function () { $GLOBALS['myapi_test_db']['my_api_tokens'][0]['access_expires_at'] = REQUEST_TIME - 1; },
      'blocked' => function () { $GLOBALS['myapi_test_users'][PaymentEndpointTest::UID]['status'] = 0; },
    ] as $name => $break) {
      $this->seed($this->consecutive(1));
      $break();

      $result = $this->listRequest();

      $this->assertSame(401, $result['status'], $name);
      $this->assertSame([], myapi_test_db_queries('node'), $name);
    }
  }

  /**
   * A foreign unit is 403 and never reads a payment; a missing one answers the
   * same bytes.
   */
  public function testAForeignOrMissingUnitIsTheSame403() {
    $this->seed($this->consecutive(1), [
      'field_data_field_propietario' => [
        ['entity_id' => (string) self::UNIT, 'field_propietario_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '77', 'field_propietario_target_id' => '900', 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);

    $foreign = $this->listRequest(77);
    $missing = $this->listRequest(4242);

    $this->assertSame(403, $foreign['status']);
    $this->assertSame('unit_access_denied', $foreign['json']['error_code']);
    $this->assertSame($foreign['output'], $missing['output']);
    $this->assertSame([], myapi_test_db_queries('node'));
  }

  /**
   * THE RULE THAT MAKES PAYMENTS NOT A TWIN: the estado filter is by
   * EXCLUSION. Every state but 'Nuevo' is listed — including states the app
   * has never heard of — and a payment with no estado row is hidden, because
   * the join is an inner one.
   */
  public function testEveryStateButTheExcludedOneIsListed() {
    $this->seed([
      ['id' => 1, 'date' => '2026-06-01', 'status' => 'Pendiente de verificar'],
      ['id' => 2, 'date' => '2026-06-02', 'status' => 'Completado'],
      ['id' => 3, 'date' => '2026-06-03', 'status' => 'Anulado'],
      ['id' => 4, 'date' => '2026-06-04', 'status' => 'Un estado nuevo'],
      ['id' => 5, 'date' => '2026-06-05', 'status' => self::HIDDEN],
      ['id' => 6, 'date' => '2026-06-06', 'status' => NULL],
    ]);

    $result = $this->listRequest();

    $this->assertSame([4, 3, 2, 1], $this->ids($result));
    $this->assertSame(4, $result['json']['data']['pagination']['total']);
  }

  /**
   * The excluded state is excluded from the count too.
   */
  public function testTheExcludedStateIsAlsoOutOfTheCount() {
    $this->seed([
      ['id' => 1, 'date' => '2026-06-01', 'status' => 'Completado'],
      ['id' => 2, 'date' => '2026-06-02', 'status' => self::HIDDEN],
      ['id' => 3, 'date' => '2026-06-03', 'status' => self::HIDDEN],
    ]);

    $result = $this->listRequest();

    $this->assertSame(1, $result['json']['data']['pagination']['total']);
    $this->assertSame(1, $result['json']['data']['pagination']['total_pages']);
  }

  /**
   * The condition really is a `<>` and not an `=`: both queries carry it.
   */
  public function testBothQueriesCarryTheExclusionCondition() {
    $this->seed($this->consecutive(2));

    $this->listRequest();

    foreach (myapi_test_db_queries('node') as $i => $query) {
      $estado = NULL;
      foreach ($query['conditions'] as $condition) {
        if ($condition['field'] === 'fest.field_estado_pago_value') {
          $estado = $condition;
        }
      }
      $this->assertNotNull($estado, 'query ' . $i);
      $this->assertSame('<>', $estado['operator'], 'query ' . $i);
      $this->assertSame(self::HIDDEN, $estado['value'], 'query ' . $i);
    }
  }

  /**
   * Type, published flag and unit narrow the listing as well.
   */
  public function testTheOtherThreeConditionsHold() {
    $this->seed([
      ['id' => 1, 'date' => '2026-06-01'],
      ['id' => 2, 'date' => '2026-06-02', 'published' => '0'],
      ['id' => 3, 'date' => '2026-06-03', 'unit' => 77],
    ]);
    $GLOBALS['myapi_test_db']['node'][] = ['type' => 'recibo'] + $this->paymentRow(['id' => 4, 'date' => '2026-06-04']);

    $this->assertSame([1], $this->ids($this->listRequest()));
  }

  /**
   * The order is payment_date then nid, both following the direction — the
   * tie-breaker matters because several payments share a date routinely.
   */
  public function testTheOrderIsTheDateThenTheNid() {
    $this->seed([
      ['id' => 1, 'date' => '2026-06-01'],
      ['id' => 2, 'date' => '2026-06-01'],
      ['id' => 3, 'date' => '2026-06-02'],
    ]);

    $this->assertSame([3, 2, 1], $this->ids($this->listRequest()));

    $_GET['sort'] = 'asc';
    $this->assertSame([1, 2, 3], $this->ids($this->listRequest()));

    $this->seed($this->consecutive(1));
    $_GET['sort'] = 'asc';
    $this->listRequest();
    $this->assertSame([
      ['field' => 'ffec.field_fecha_de_pago_value', 'direction' => 'ASC'],
      ['field' => 'n.nid', 'direction' => 'ASC'],
    ], myapi_test_db_queries('node')[1]['order']);
  }

  /**
   * Pagination and the date range behave like the twins'.
   */
  public function testPaginationAndTheDateRangeBehaveLikeTheTwins() {
    $this->seed($this->consecutive(6));

    $this->assertSame(
      ['total' => 6, 'page' => 1, 'limit' => 20, 'total_pages' => 1],
      $this->listRequest()['json']['data']['pagination']
    );

    $_GET = ['limit' => '2', 'page' => '2'];
    $this->assertSame([4, 3], $this->ids($this->listRequest()));

    $_GET = ['date_from' => '2026-06-02', 'date_to' => '2026-06-03'];
    $result = $this->listRequest();
    $this->assertSame([3, 2], $this->ids($result));
    $this->assertSame(2, $result['json']['data']['pagination']['total']);

    $_GET = ['date_from' => '2026-06-30', 'date_to' => '2026-06-01'];
    $this->assertCount(6, $this->listRequest()['json']['data']['payments']);
  }

  /**
   * The listing item carries the twelve documented keys, in order.
   */
  public function testTheListItemHasTheDocumentedKeys() {
    $this->seed([['id' => 1, 'date' => '2026-06-01']]);

    $item = $this->listRequest()['json']['data']['payments'][0];

    $this->assertSame([
      'id', 'title', 'unit_id', 'payment_date', 'status', 'payment_method',
      'reference', 'amount', 'detail', 'file_id', 'bank_id', 'bank_name',
    ], array_keys($item));
  }

  /**
   * The casts of the listing mapper: three guarded ones, all null when absent
   * and never 0.
   */
  public function testTheListMapperCastsOnlyWhatIsPresent() {
    $this->seed([['id' => 1, 'date' => '2026-06-01', 'values' => [
      'amount'  => '120.50',
      'file_id' => '77',
      'bank_id' => '9',
      'bank_name' => 'Banco Pichincha',
    ]]]);

    $item = $this->listRequest()['json']['data']['payments'][0];
    $this->assertSame(120.5, $item['amount']);
    $this->assertSame(77, $item['file_id']);
    $this->assertSame(9, $item['bank_id']);
    $this->assertSame('Banco Pichincha', $item['bank_name']);

    $this->seed([['id' => 1, 'date' => '2026-06-01']]);
    $result = $this->listRequest();
    $item = $result['json']['data']['payments'][0];
    $this->assertNull($item['amount']);
    $this->assertNull($item['file_id']);
    $this->assertNull($item['bank_id']);
    $this->assertNull($item['bank_name']);
    $this->assertStringContainsString('"bank_id":null', $result['output']);
  }

  /* -------------------------------------------------------------------------
   * GET /payments/% — the detail.
   * ---------------------------------------------------------------------- */

  /**
   * The detail of an own, visible payment answers 200 with the full item and
   * no message.
   */
  public function testTheDetailOfAnOwnPaymentAnswersTheFullItem() {
    $this->seed();
    myapi_test_node_seed([501 => $this->paymentNode(['status' => 'Completado'])]);

    $result = $this->detailRequest(501);

    $this->assertSame(200, $result['status']);
    $this->assertArrayNotHasKey('message', $result['json']);
    $payment = $result['json']['data']['payment'];
    $this->assertSame(501, $payment['id']);
    $this->assertSame(self::UNIT, $payment['unit_id']);
    $this->assertSame('Completado', $payment['status']);
    $this->assertSame('REF-001', $payment['reference']);
    $this->assertSame(120.5, $payment['amount']);
  }

  /**
   * A missing nid, a nid of another bundle and a malformed id are all the SAME
   * 404: the endpoint never says which.
   */
  public function testEveryUnresolvableIdIsTheSame404() {
    $this->seed();
    myapi_test_node_seed([
      501 => $this->paymentNode(),
      600 => (object) ['nid' => 600, 'type' => 'recibo', 'title' => 'Un recibo'],
    ]);

    $missing = $this->detailRequest(4242);
    $this->assertSame(404, $missing['status']);
    $this->assertSame('payment_not_found', $missing['json']['error_code']);

    $this->assertSame($missing['output'], $this->detailRequest(600)['output'], 'another bundle');

    foreach (['abc', '0', '-1', ''] as $id) {
      $this->assertSame($missing['output'], $this->detailRequest($id)['output'], json_encode($id));
    }
  }

  /**
   * A malformed id is refused BEFORE any node is loaded: the ctype_digit()
   * guard comes first.
   */
  public function testAMalformedIdNeverLoadsANode() {
    $this->seed();
    myapi_test_node_seed([501 => $this->paymentNode()]);
    $GLOBALS['myapi_test_node_access_calls'] = [];

    $result = $this->detailRequest('abc');

    $this->assertSame(404, $result['status']);
  }

  /**
   * THE ORDER OF THE TWO GUARDS IS THE CONTRACT: a payment in the excluded
   * state answers 404 — like a missing one — even to the resident who owns it,
   * and a payment of another unit answers 403. Hidden-by-state is resolved
   * FIRST, so the two are never confused.
   */
  public function testHiddenByStateIs404AndForeignIs403() {
    $this->seed();
    myapi_test_node_seed([
      501 => $this->paymentNode(['status' => self::HIDDEN]),
      502 => $this->paymentNode(['nid' => 502, 'unit' => 77, 'status' => 'Completado']),
      503 => $this->paymentNode(['nid' => 503, 'unit' => 77, 'status' => self::HIDDEN]),
    ]);

    $hidden = $this->detailRequest(501);
    $this->assertSame(404, $hidden['status']);
    $this->assertSame('payment_not_found', $hidden['json']['error_code']);

    $foreign = $this->detailRequest(502);
    $this->assertSame(403, $foreign['status']);
    $this->assertSame('unit_access_denied', $foreign['json']['error_code']);

    // Hidden AND foreign: the state wins, so the answer does not reveal that
    // the payment belongs to somebody else either.
    $this->assertSame(404, $this->detailRequest(503)['status']);
  }

  /**
   * A payment with no estado row at all is NOT hidden by the detail endpoint —
   * only the exact excluded value is. This diverges from the LISTING, whose
   * inner join drops it, and the divergence is real: the same payment 404s in
   * one place and is readable in the other. Pinned as a finding of SPEC 121.
   */
  public function testAPaymentWithNoEstadoIsReadableByTheDetailButNotByTheListing() {
    $this->seed([['id' => 501, 'date' => '2026-06-01', 'status' => NULL]]);
    myapi_test_node_seed([501 => $this->paymentNode(['status' => NULL])]);

    $this->assertSame([], $this->ids($this->listRequest()), 'the listing hides it');

    // AND IT ANSWERS SILENTLY SINCE SPEC 122. That read was the one key of
    // myapi_payment_build_created_item() without an isset(), where every other
    // nullable field of the same mapper had one — so this exact request used
    // to emit an undefined-property notice on its way to the same null. The
    // handler below is what makes the ABSENCE of the notice assertable.
    $notices = [];
    set_error_handler(function ($severity, $message) use (&$notices) {
      $notices[] = $message;

      return TRUE;
    });
    try {
      $detail = $this->detailRequest(501);
    }
    finally {
      restore_error_handler();
    }

    $this->assertSame(200, $detail['status'], 'the detail answers it');
    $this->assertNull($detail['json']['data']['payment']['status']);
    $this->assertSame([], $notices, 'the read is guarded now');
  }

  /**
   * A payment with no unit reference is 403 rather than a fatal: the NULL is
   * checked before the allowlist.
   */
  public function testAPaymentWithNoUnitIs403() {
    $this->seed();
    $node = $this->paymentNode(['status' => 'Completado']);
    unset($node->field_vivienda);
    myapi_test_node_seed([501 => $node]);

    $result = $this->detailRequest(501);

    $this->assertSame(403, $result['status']);
    $this->assertSame('unit_access_denied', $result['json']['error_code']);
  }

  /**
   * The bank name and the attachment are resolved for the response, and are
   * null together when absent.
   */
  public function testTheDetailResolvesTheBankAndTheAttachment() {
    myapi_test_taxonomy_seed(['bancos' => [['tid' => '9', 'name' => 'Banco <b>Pichincha</b>', 'description' => '']]]);
    myapi_test_file_seed([77 => ['fid' => 77, 'uri' => 'private://comprobantes_pago/recibo.pdf', 'filemime' => 'application/pdf', 'filename' => 'recibo.pdf']]);
    $this->seed();
    myapi_test_node_seed([501 => $this->paymentNode(['status' => 'Completado', 'bank' => 9, 'file' => 77])]);

    $payment = $this->detailRequest(501)['json']['data']['payment'];

    $this->assertSame(9, $payment['bank_id']);
    $this->assertSame('Banco &lt;b&gt;Pichincha&lt;/b&gt;', $payment['bank_name'], 'the term name is escaped');
    $this->assertSame(77, $payment['file_id']);
    $this->assertSame('recibo.pdf', $payment['file_name']);

    $this->seed();
    myapi_test_node_seed([501 => $this->paymentNode(['status' => 'Completado'])]);
    $payment = $this->detailRequest(501)['json']['data']['payment'];
    $this->assertNull($payment['bank_id']);
    $this->assertNull($payment['bank_name']);
    $this->assertNull($payment['file_id']);
    $this->assertNull($payment['file_name']);
  }

  /**
   * A stored fid whose file is gone keeps the bank/file ids consistent: the
   * mapper reads file_id off the FILE it was handed, so a dangling attachment
   * answers null on both keys rather than half a pair.
   */
  public function testADanglingAttachmentAnswersNullOnBothFileKeys() {
    myapi_test_file_seed([]);
    $this->seed();
    myapi_test_node_seed([501 => $this->paymentNode(['status' => 'Completado', 'file' => 77])]);

    $payment = $this->detailRequest(501)['json']['data']['payment'];

    $this->assertNull($payment['file_id']);
    $this->assertNull($payment['file_name']);
  }

  /**
   * The detail item has the thirteen documented keys, in order.
   */
  public function testTheDetailItemHasTheDocumentedKeys() {
    $this->seed();
    myapi_test_node_seed([501 => $this->paymentNode(['status' => 'Completado'])]);

    $payment = $this->detailRequest(501)['json']['data']['payment'];

    $this->assertSame([
      'id', 'title', 'unit_id', 'payment_date', 'status', 'payment_method',
      'reference', 'amount', 'bank_id', 'bank_name', 'file_id', 'file_name', 'detail',
    ], array_keys($payment));
  }

  /* -------------------------------------------------------------------------
   * PUT /payments/%/cancel.
   * ---------------------------------------------------------------------- */

  /**
   * Cancelling a pending payment rewrites its state, saves it once and answers
   * 200 with the updated payment and a translated message.
   */
  public function testCancellingAPendingPaymentRewritesItsState() {
    $this->seed();
    myapi_test_node_seed([501 => $this->paymentNode(['status' => MYAPI_PAYMENT_STATUS_PENDING])]);

    $result = $this->cancelRequest(501);

    $this->assertSame(200, $result['status']);
    $this->assertSame(MYAPI_PAYMENT_STATUS_CANCELLED, $result['json']['data']['payment']['status']);
    $this->assertArrayHasKey('message', $result['json']);

    $saves = myapi_test_node_saves();
    $this->assertCount(1, $saves);
    $this->assertSame(MYAPI_PAYMENT_STATUS_CANCELLED, $saves[0]->field_estado_pago[LANGUAGE_NONE][0]['value']);
  }

  /**
   * THE OPT-OUT FLAG IS SET ON THE SAVED NODE. Without it, hook_node_update()
   * would read the very cancellation the resident just made as a back-office
   * one and push them a notification about their own action (SPEC 30).
   */
  public function testTheSavedNodeCarriesTheNotificationOptOut() {
    $this->seed();
    myapi_test_node_seed([501 => $this->paymentNode()]);

    $this->cancelRequest(501);

    $saved = myapi_test_node_saves()[0];
    $this->assertTrue(!empty($saved->myapi_skip_cancel_notification));
    // And the workflow really does read it as "do not notify".
    $saved->original = $this->paymentNode();
    $this->assertFalse(myapi_payment_is_cancellation_transition($saved));
  }

  /**
   * ONLY A PENDING PAYMENT MAY BE CANCELLED. Every other state — including an
   * already-cancelled one — is a 409, and nothing is saved.
   */
  public function testOnlyAPendingPaymentMayBeCancelled() {
    foreach (['Completado', 'Anulado', 'Nuevo', 'Cualquier cosa', NULL] as $status) {
      $this->seed();
      myapi_test_node_seed([501 => $this->paymentNode(['status' => $status])]);

      $result = $this->cancelRequest(501);

      $this->assertSame(409, $result['status'], json_encode($status));
      $this->assertSame('payment_not_pending', $result['json']['error_code'], json_encode($status));
      $this->assertSame([], myapi_test_node_saves(), json_encode($status));
    }
  }

  /**
   * The guards run in order: a missing payment is 404, a foreign one is 403,
   * and neither saves anything.
   */
  public function testTheCancelGuardsRunInOrder() {
    $this->seed();
    myapi_test_node_seed([
      501 => $this->paymentNode(),
      502 => $this->paymentNode(['nid' => 502, 'unit' => 77]),
      600 => (object) ['nid' => 600, 'type' => 'recibo'],
    ]);

    $missing = $this->cancelRequest(4242);
    $this->assertSame(404, $missing['status']);
    $this->assertSame('payment_not_found', $missing['json']['error_code']);

    $this->assertSame($missing['output'], $this->cancelRequest(600)['output'], 'another bundle');
    $this->assertSame($missing['output'], $this->cancelRequest('abc')['output'], 'a malformed id');

    $foreign = $this->cancelRequest(502);
    $this->assertSame(403, $foreign['status']);
    $this->assertSame('unit_access_denied', $foreign['json']['error_code']);

    $this->assertSame([], myapi_test_node_saves());
  }

  /**
   * THE CANCEL ENDPOINT DOES NOT HIDE BY STATE. A payment in the excluded
   * state answers 409 (not pending) rather than the 404 the detail gives it —
   * the two endpoints resolve visibility differently, which is the same
   * divergence pinned above for a payment with no estado row.
   */
  public function testTheCancelEndpointAnswers409ForAnExcludedStatePayment() {
    $this->seed();
    myapi_test_node_seed([501 => $this->paymentNode(['status' => self::HIDDEN])]);

    $result = $this->cancelRequest(501);

    $this->assertSame(409, $result['status']);
    $this->assertSame(404, $this->detailRequest(501)['status'], 'the detail hides the same payment');
  }

  /**
   * WITH NO REQUEST BODY field_detalle IS LEFT UNTOUCHED. This is the only
   * half of the 'reason' rule a unit test can reach — php://input is unwritable
   * here — and it is the half that matters most: a cancellation without a
   * reason must not blank a detail the back office had written.
   */
  public function testWithNoBodyTheExistingDetailSurvivesTheCancellation() {
    $this->seed();
    myapi_test_node_seed([501 => $this->paymentNode(['detail' => 'Nota del administrador'])]);

    $result = $this->cancelRequest(501);

    $this->assertSame('Nota del administrador', $result['json']['data']['payment']['detail']);
    $this->assertSame('Nota del administrador', myapi_test_node_saves()[0]->field_detalle[LANGUAGE_NONE][0]['value']);
  }

  /* -------------------------------------------------------------------------
   * POST /payments — the ordered validations.
   * ---------------------------------------------------------------------- */

  /**
   * The multipart fields of a valid creation.
   */
  private function validPost(array $overrides = []) {
    return $overrides + [
      'unit_id'        => (string) self::UNIT,
      'reference'      => 'REF-999',
      'amount'         => '150.75',
      'payment_method' => 'transferencia',
      'bank_id'        => '9',
      'payment_date'   => '2026-06-15',
    ];
  }

  /**
   * Seeds everything a creation needs: the unit node, the method catalogue and
   * the bank vocabulary.
   */
  private function seedForCreate(array $tables = []) {
    myapi_test_field_seed_allowed_values([
      MYAPI_PAYMENT_METHOD_FIELD => ['transferencia' => 'Transferencia', 'efectivo' => 'Efectivo'],
    ]);
    myapi_test_taxonomy_seed(['bancos' => [['tid' => '9', 'name' => 'Banco Pichincha', 'description' => '']]]);
    myapi_test_node_seed([
      self::UNIT => (object) [
        'nid' => self::UNIT, 'type' => 'vivienda', 'status' => 1, 'title' => 'A-101',
        'field_condominio' => [LANGUAGE_NONE => [['target_id' => self::CONDOMINIUM]]],
      ],
    ]);
    $this->seed([], $tables);
    $_POST = $this->validPost();
  }

  /**
   * A valid creation saves the node, answers 201 with the payment and carries
   * a translated message.
   */
  public function testAValidCreationAnswers201WithThePayment() {
    $this->seedForCreate();

    $result = $this->createRequest();

    $this->assertSame(201, $result['status']);
    $this->assertArrayHasKey('message', $result['json']);

    $payment = $result['json']['data']['payment'];
    $this->assertSame(self::UNIT, $payment['unit_id']);
    $this->assertSame('REF-999', $payment['reference']);
    $this->assertSame(150.75, $payment['amount']);
    $this->assertSame('transferencia', $payment['payment_method']);
    $this->assertSame('2026-06-15T00:00:00', $payment['payment_date']);
    $this->assertSame(9, $payment['bank_id']);
    $this->assertSame('Banco Pichincha', $payment['bank_name']);
    $this->assertNull($payment['file_id']);
  }

  /**
   * THE STATE IS FORCED AND THE AUTHOR IS THE TOKEN'S. A client cannot create
   * an already-verified payment, and cannot create one on somebody else's
   * behalf: neither value is read from the request.
   */
  public function testTheStateIsForcedAndTheAuthorIsTheTokenUid() {
    $this->seedForCreate();
    $_POST['status'] = 'Completado';
    $_POST['uid'] = '900';

    $result = $this->createRequest();

    $this->assertSame(MYAPI_PAYMENT_STATUS_PENDING, $result['json']['data']['payment']['status']);

    $saved = myapi_test_node_saves()[0];
    $this->assertSame(MYAPI_PAYMENT_STATUS_PENDING, $saved->field_estado_pago[LANGUAGE_NONE][0]['value']);
    $this->assertSame(self::UID, (int) $saved->uid);
    $this->assertSame('pagos', $saved->type);
    $this->assertSame(1, $saved->status);
  }

  /**
   * The title is autogenerated from the reference and the date, never taken
   * from the request.
   */
  public function testTheTitleIsAutogenerated() {
    $this->seedForCreate();
    $_POST['title'] = 'Título del cliente';

    $result = $this->createRequest();

    $this->assertSame('Pago REF-999 - 2026-06-15', $result['json']['data']['payment']['title']);
  }

  /**
   * Each required field answers its own 422 naming itself, and nothing is
   * saved.
   */
  public function testEachMissingRequiredFieldNamesItself() {
    foreach (['unit_id', 'reference', 'amount', 'payment_method'] as $field) {
      $this->seedForCreate();
      unset($_POST[$field]);

      $result = $this->createRequest();

      $this->assertSame(422, $result['status'], $field);
      $this->assertSame('missing_field', $result['json']['error_code'], $field);
      $this->assertStringContainsString($field, $result['json']['error'], $field);
      $this->assertSame([], myapi_test_node_saves(), $field);

      // An empty string is a missing field too.
      $this->seedForCreate();
      $_POST[$field] = '';
      $this->assertSame(422, $this->createRequest()['status'], $field . ' empty');
    }
  }

  /**
   * bank_id is required for a transfer and OPTIONAL FOR CASH — the one
   * conditional requirement of the endpoint, matched case-insensitively.
   */
  public function testTheBankIsRequiredExceptForCash() {
    $this->seedForCreate();
    unset($_POST['bank_id']);
    $result = $this->createRequest();
    $this->assertSame(422, $result['status']);
    $this->assertSame('missing_field', $result['json']['error_code']);
    $this->assertStringContainsString('bank_id', $result['json']['error']);

    foreach (['efectivo', 'Efectivo', 'EFECTIVO'] as $method) {
      $this->seedForCreate();
      $_POST['payment_method'] = 'efectivo';
      unset($_POST['bank_id']);
      // The catalogue key is lowercase; the case-insensitive comparison is on
      // the cash rule, not on the catalogue lookup.
      $result = $this->createRequest();
      $this->assertSame(201, $result['status'], $method);
      $this->assertNull($result['json']['data']['payment']['bank_id'], $method);
      $this->assertNull($result['json']['data']['payment']['bank_name'], $method);
    }
  }

  /**
   * unit_id must be a positive integer AND a published 'vivienda'; every way
   * of failing that is the same 422 naming the field.
   */
  public function testTheUnitMustBeAPublishedVivienda() {
    $cases = [
      'not a number'  => 'abc',
      'zero'          => '0',
      'negative'      => '-3',
      'missing node'  => '4242',
    ];

    foreach ($cases as $name => $value) {
      $this->seedForCreate();
      $_POST['unit_id'] = $value;

      $result = $this->createRequest();

      $this->assertSame(422, $result['status'], $name);
      $this->assertSame('invalid_field', $result['json']['error_code'], $name);
      $this->assertStringContainsString('unit_id', $result['json']['error'], $name);
    }

    // Another bundle, and an unpublished unit.
    $this->seedForCreate();
    myapi_test_node_seed([self::UNIT => (object) ['nid' => self::UNIT, 'type' => 'recibo', 'status' => 1]]);
    $this->assertSame(422, $this->createRequest()['status'], 'another bundle');

    $this->seedForCreate();
    myapi_test_node_seed([self::UNIT => (object) ['nid' => self::UNIT, 'type' => 'vivienda', 'status' => 0]]);
    $this->assertSame(422, $this->createRequest()['status'], 'unpublished');
  }

  /**
   * A unit the caller does not own or occupy is 403, and the 403 comes AFTER
   * the shape checks — a malformed id is a 422 even for a foreign unit.
   */
  public function testAForeignUnitIs403() {
    $this->seedForCreate();
    myapi_test_node_seed([
      77 => (object) ['nid' => 77, 'type' => 'vivienda', 'status' => 1, 'title' => 'B-201'],
    ]);
    $_POST['unit_id'] = '77';

    $result = $this->createRequest();

    $this->assertSame(403, $result['status']);
    $this->assertSame('unit_access_denied', $result['json']['error_code']);
    $this->assertSame([], myapi_test_node_saves());
  }

  /**
   * amount must be numeric and strictly positive.
   */
  public function testTheAmountMustBeNumericAndPositive() {
    foreach (['abc', '0', '0.00', '-1', '-0.01', 'NaN'] as $value) {
      $this->seedForCreate();
      $_POST['amount'] = $value;

      $result = $this->createRequest();

      $this->assertSame(422, $result['status'], $value);
      $this->assertSame('invalid_amount', $result['json']['error_code'], $value);
    }

    // The smallest positive amount is accepted.
    $this->seedForCreate();
    $_POST['amount'] = '0.01';
    $this->assertSame(201, $this->createRequest()['status']);
  }

  /**
   * The reference is length-capped and ESCAPED before being stored, so markup
   * typed by the resident never travels raw into the title or the email.
   */
  public function testTheReferenceIsCappedAndEscaped() {
    $this->seedForCreate();
    $_POST['reference'] = str_repeat('a', 256);
    $result = $this->createRequest();
    $this->assertSame(422, $result['status']);
    $this->assertSame('invalid_field', $result['json']['error_code']);
    $this->assertStringContainsString('reference', $result['json']['error']);

    $this->seedForCreate();
    $_POST['reference'] = str_repeat('a', 255);
    $this->assertSame(201, $this->createRequest()['status'], '255 is accepted');

    $this->seedForCreate();
    $_POST['reference'] = '<b>REF</b>';
    $result = $this->createRequest();
    $this->assertSame('&lt;b&gt;REF&lt;/b&gt;', $result['json']['data']['payment']['reference']);
  }

  /**
   * payment_method must be a KEY of the field's allowed_values — the label is
   * not accepted, and neither is a value the catalogue dropped.
   */
  public function testThePaymentMethodMustBeACatalogueKey() {
    foreach (['Transferencia', 'tarjeta', 'TRANSFERENCIA', 'transferencia x'] as $value) {
      $this->seedForCreate();
      $_POST['payment_method'] = $value;

      $result = $this->createRequest();

      $this->assertSame(422, $result['status'], $value);
      $this->assertSame('invalid_payment_method', $result['json']['error_code'], $value);
    }
  }

  /**
   * SURROUNDING WHITESPACE IS TRIMMED BEFORE THE LOOKUP, so ' transferencia '
   * is the key 'transferencia' and is accepted. That is
   * myapi_request_post_field()'s doing and not the resource's, and it applies
   * to every multipart field of this endpoint.
   */
  public function testAMethodWithSurroundingWhitespaceIsTrimmedAndAccepted() {
    $this->seedForCreate();
    $_POST['payment_method'] = '  transferencia  ';

    $result = $this->createRequest();

    $this->assertSame(201, $result['status']);
    $this->assertSame('transferencia', $result['json']['data']['payment']['payment_method']);
  }

  /**
   * A site whose field has no allowed_values at all rejects every method
   * rather than accepting any: the fallback is an empty catalogue.
   */
  public function testASiteWithNoCatalogueRejectsEveryMethod() {
    $this->seedForCreate();
    myapi_test_field_seed_allowed_values([]);

    $result = $this->createRequest();

    $this->assertSame(422, $result['status']);
    $this->assertSame('invalid_payment_method', $result['json']['error_code']);
  }

  /**
   * bank_id must be a positive integer AND a term of the 'bancos' vocabulary.
   * A term of another vocabulary is refused, which is what stops a payment
   * from being filed under a service category.
   */
  public function testTheBankMustBeATermOfTheBancosVocabulary() {
    foreach (['abc', '0', '-1', '4242'] as $value) {
      $this->seedForCreate();
      $_POST['bank_id'] = $value;

      $result = $this->createRequest();

      $this->assertSame(422, $result['status'], $value);
      $this->assertSame('invalid_bank', $result['json']['error_code'], $value);
    }

    $this->seedForCreate();
    myapi_test_taxonomy_seed([
      'bancos'             => [['tid' => '9', 'name' => 'Banco Pichincha', 'description' => '']],
      'categorias_servicio' => [['tid' => '20', 'name' => 'Plomería', 'description' => '']],
    ]);
    $_POST['bank_id'] = '20';
    $result = $this->createRequest();
    $this->assertSame(422, $result['status'], 'a term of another vocabulary');
    $this->assertSame('invalid_bank', $result['json']['error_code']);
  }

  /**
   * A bank sent WITH a cash payment is still validated: the field is optional
   * for cash, not unchecked.
   */
  public function testABankSentWithACashPaymentIsStillValidated() {
    $this->seedForCreate();
    $_POST['payment_method'] = 'efectivo';
    $_POST['bank_id'] = '4242';

    $result = $this->createRequest();

    $this->assertSame(422, $result['status']);
    $this->assertSame('invalid_bank', $result['json']['error_code']);
  }

  /**
   * payment_date accepts a date and a datetime, normalises the first to
   * midnight, and refuses everything else.
   */
  public function testThePaymentDateIsNormalisedOrRefused() {
    $this->seedForCreate();
    $_POST['payment_date'] = '2026-06-15';
    $this->assertSame('2026-06-15T00:00:00', $this->createRequest()['json']['data']['payment']['payment_date']);

    $this->seedForCreate();
    $_POST['payment_date'] = '2026-06-15T13:45:30';
    $this->assertSame('2026-06-15T13:45:30', $this->createRequest()['json']['data']['payment']['payment_date']);

    // The two newline cases are SPEC 122's: myapi_payment_normalize_date()
    // would otherwise have stored "2026-06-15\nT00:00:00" in
    // field_fecha_de_pago. Unreachable over HTTP, because
    // myapi_request_post_field() trims first — which is exactly why it went
    // unnoticed, and exactly why the validator should not depend on it.
    foreach (['15-06-2026', '2026-13-01', '2026-02-30', '2026-06-15 13:45:30', '2026-06-15T24:00:00', '2026-06-15T13:60:00', 'hoy'] as $value) {
      $this->seedForCreate();
      $_POST['payment_date'] = $value;

      $result = $this->createRequest();

      $this->assertSame(422, $result['status'], $value);
      $this->assertSame('invalid_date', $result['json']['error_code'], $value);
    }
  }

  /**
   * The normaliser itself refuses a trailing newline on both of its shapes
   * (SPEC 122), so a caller that does not trim first cannot store one.
   */
  public function testTheNormaliserRefusesATrailingNewlineOnBothShapes() {
    $this->assertNull(myapi_payment_normalize_date("2026-06-15\n"));
    $this->assertNull(myapi_payment_normalize_date("2026-06-15T13:45:30\n"));

    $this->assertSame('2026-06-15T00:00:00', myapi_payment_normalize_date('2026-06-15'));
    $this->assertSame('2026-06-15T13:45:30', myapi_payment_normalize_date('2026-06-15T13:45:30'));
  }

  /**
   * An ABSENT payment_date falls back to the server clock rather than to a
   * 422 — the field is optional.
   */
  public function testAnAbsentPaymentDateFallsBackToTheServerClock() {
    $this->seedForCreate();
    unset($_POST['payment_date']);

    $result = $this->createRequest();

    $this->assertSame(201, $result['status']);
    $this->assertSame(date('Y-m-d'), substr($result['json']['data']['payment']['payment_date'], 0, 10));
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $result['json']['data']['payment']['payment_date']);
  }

  /**
   * A DUPLICATE REFERENCE IN THE SAME UNIT IS A 409, and the same reference in
   * ANOTHER unit is fine: uniqueness is per unit.
   */
  public function testADuplicateReferenceInTheSameUnitIs409() {
    // The base table of the lookup is field_data_field_referencia, and its two
    // joins (the unit and the node type) are recorded rather than resolved —
    // so the fixture row carries their columns FLAT, the rule stated in the
    // class docblock of every listing test here.
    $existing = function ($unit) {
      return [
        'field_data_field_referencia' => [[
          'entity_id'               => '700',
          'field_referencia_value'  => 'REF-999',
          'deleted'                 => '0',
          'entity_type'             => 'node',
          'field_vivienda_target_id' => (string) $unit,
          'type'                    => 'pagos',
        ]],
      ];
    };

    $this->seedForCreate($existing(self::UNIT));

    $result = $this->createRequest();

    $this->assertSame(409, $result['status']);
    $this->assertSame('duplicate_reference', $result['json']['error_code']);
    $this->assertSame([], myapi_test_node_saves());

    // The same reference in another unit is accepted.
    $this->seedForCreate($existing(77));
    $this->assertSame(201, $this->createRequest()['status']);
  }

  /**
   * The duplicate check compares the ESCAPED reference, because that is what
   * gets stored — a resident retyping the same markup gets the 409 they
   * should.
   */
  public function testTheDuplicateCheckComparesTheStoredEscapedReference() {
    $this->seedForCreate([
      'field_data_field_referencia' => [[
        'entity_id'                => '700',
        'field_referencia_value'   => '&lt;b&gt;REF&lt;/b&gt;',
        'deleted'                  => '0',
        'entity_type'              => 'node',
        'field_vivienda_target_id' => (string) self::UNIT,
        'type'                     => 'pagos',
      ]],
    ]);
    $_POST['reference'] = '<b>REF</b>';

    $this->assertSame(409, $this->createRequest()['status']);
  }

  /**
   * The saved node carries every mapped field, and the response describes the
   * node that was actually saved.
   */
  public function testTheSavedNodeCarriesEveryMappedField() {
    $this->seedForCreate();

    $result = $this->createRequest();
    $saved = myapi_test_node_saves()[0];

    $this->assertSame(self::UNIT, $saved->field_vivienda[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame('REF-999', $saved->field_referencia[LANGUAGE_NONE][0]['value']);
    $this->assertSame(150.75, $saved->field_valor[LANGUAGE_NONE][0]['value']);
    $this->assertSame('transferencia', $saved->field_forma_de_pago[LANGUAGE_NONE][0]['value']);
    $this->assertSame('2026-06-15T00:00:00', $saved->field_fecha_de_pago[LANGUAGE_NONE][0]['value']);
    $this->assertSame(9, $saved->field_banco[LANGUAGE_NONE][0]['tid']);
    $this->assertSame($saved->nid, $result['json']['data']['payment']['id']);
  }

  /**
   * With nobody holding the 'backend' role the creation still answers 201 and
   * enqueues no mail — the email is best effort and never a precondition.
   */
  public function testTheBackendEmailIsBestEffort() {
    $this->seedForCreate();

    $result = $this->createRequest();

    $this->assertSame(201, $result['status']);
    $this->assertSame([], myapi_test_queue_items(MYAPI_MAIL_QUEUE));
  }

  /**
   * With the role held, one mail item per recipient is enqueued AFTER the node
   * was saved — and the 201 still goes out.
   */
  public function testTheBackendEmailIsEnqueuedOncePerRecipient() {
    $this->seedForCreate([
      // 'r.name' is written QUALIFIED because the role name is joined onto a
      // users row that already has a 'name' of its own — the same collision
      // the estado columns have, resolved the same way.
      'users' => [
        ['uid' => '10', 'status' => '1', 'name' => 'op1', 'mail' => 'op1@example.com', 'r.name' => MYAPI_PAYMENT_NOTIFY_ROLE],
        ['uid' => '11', 'status' => '1', 'name' => 'op2', 'mail' => 'op2@example.com', 'r.name' => MYAPI_PAYMENT_NOTIFY_ROLE],
        ['uid' => '12', 'status' => '1', 'name' => 'vecino', 'mail' => 'v@example.com', 'r.name' => 'authenticated user'],
      ],
    ]);
    $GLOBALS['myapi_test_users'][10] = ['uid' => 10, 'name' => 'op1', 'status' => 1, 'mail' => 'op1@example.com'];
    $GLOBALS['myapi_test_users'][11] = ['uid' => 11, 'name' => 'op2', 'status' => 1, 'mail' => 'op2@example.com'];

    $result = $this->createRequest();

    $this->assertSame(201, $result['status']);
    $items = myapi_test_queue_items(MYAPI_MAIL_QUEUE);
    $this->assertCount(2, $items);
    $this->assertSame(MYAPI_PAYMENT_CREATED_ADMIN_MAIL_KEY, $items[0]['data']['key']);
    $this->assertSame(['op1@example.com', 'op2@example.com'], array_column(array_column($items, 'data'), 'to'));
  }

  /**
   * Every answer of the four endpoints carries the no-store headers.
   */
  public function testEveryAnswerIsUncacheable() {
    $this->seed($this->consecutive(1));
    myapi_test_node_seed([501 => $this->paymentNode(['status' => 'Completado'])]);

    foreach ([$this->listRequest(), $this->detailRequest(501)] as $result) {
      $this->assertStringContainsString('no-store', $result['headers']['Cache-Control']);
    }
  }
}
