<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../resources/bulletin.resource.inc';

/**
 * End-to-end unit tests for GET /api/v1/bulletins (SPECS 29 and 31, covered by
 * SPEC 121).
 *
 * THE HARDEST READ RULE IN THE MODULE, and the only listing with NO 403 on its
 * main path: the audience lives inside the query, so a reader who may see
 * nothing gets an empty list rather than an error. That design is exactly what
 * makes it dangerous — every way of getting the visibility wrong answers a
 * plausible 200, either with a neighbour's bulletin in it or with the
 * reader's own missing.
 *
 * The rule is the cross of two fields:
 *
 *   field_tipo_de_boletin  x  field_enviar_a
 *   General                   Propietarios / Ocupantes / Todos
 *   Condominio                + the node's field_condominio must be one of
 *                               the reader's condominiums FOR THAT ROLE
 *   Personalizado             + the reader must be referenced ON THE NODE
 *                               (field_personalizar for owners,
 *                                field_ocupantes for occupants)
 *
 * and it is built as a nested db_or()/db_and() with two correlated EXISTS
 * sub-selects. The fixture query builder evaluates all of that for real (see
 * matchesGroup() and existsFor() in bootstrap.php) — what it does not do is
 * resolve the JOINs, so every fixture row carries the joined columns flat.
 *
 * The 3x3 grid of "role held x audience asked for" is walked explicitly below,
 * because the failure mode of a missing branch is silence.
 */
class BulletinEndpointTest extends TestCase {

  const TOKEN = 'a-valid-access-token';

  const UID = 3;

  /**
   * The reader's own unit and condominium, and a second condominium they have
   * nothing to do with.
   */
  const UNIT = 45;
  const CONDOMINIUM = 12;
  const OTHER_CONDOMINIUM = 99;

  /**
   * A fixed created timestamp, so the date-range cases are deterministic. Noon
   * on purpose: a bulletin created at noon falls inside its own day whatever
   * the site timezone does to the 00:00:00/23:59:59 bounds.
   */
  const CREATED = 1780000000;

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_file_seed();
    myapi_test_write_reset();
    $GLOBALS['myapi_test_users'] = [];
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  protected function tearDown(): void {
    $_GET = [];
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $GLOBALS['myapi_test_users'] = [];
    myapi_test_db_seed();
    myapi_test_file_seed();
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  /**
   * One 'boletin' row, carrying the joined audience columns flat.
   *
   * @param array $spec
   *   'id', 'type' (the audience axis), 'send_to' (the role), and optionally
   *   'condominium', 'created', 'published', 'title', 'message', 'file_id'.
   */
  private function bulletinRow(array $spec) {
    $spec += [
      'type'        => 'General',
      'send_to'     => 'Todos',
      'condominium' => NULL,
      'created'     => self::CREATED,
      'published'   => '1',
      'title'       => 'Boletín ' . $spec['id'],
      'message'     => NULL,
      'file_id'     => NULL,
    ];

    return [
      'nid'                              => (string) $spec['id'],
      'title'                            => $spec['title'],
      'type'                             => 'boletin',
      'status'                           => (string) $spec['published'],
      'created'                          => (string) $spec['created'],
      // The audience axis, under the alias the projection gives it and under
      // the qualified name the condition reads.
      'ftipo.field_tipo_de_boletin_value' => $spec['type'],
      'field_tipo_de_boletin_value'      => $spec['type'],
      'field_enviar_a_value'             => $spec['send_to'],
      'send_to'                          => $spec['send_to'],
      'field_condominio_target_id'       => $spec['condominium'] === NULL ? NULL : (string) $spec['condominium'],
      'condominium_id'                   => $spec['condominium'] === NULL ? NULL : (string) $spec['condominium'],
      'message'                          => $spec['message'],
      'file_id'                          => $spec['file_id'] === NULL ? NULL : (string) $spec['file_id'],
    ];
  }

  /**
   * Seeds the reader's role in the building and the given bulletins.
   *
   * @param array $bulletins  Specs for bulletinRow().
   * @param array $roles
   *   'owner' => bool and 'occupant' => bool: whether the reader owns and/or
   *   occupies self::UNIT, which is what puts them in self::CONDOMINIUM.
   * @param array $tables     Extra fixture tables, merged last.
   */
  private function seed(array $bulletins, array $roles = [], array $tables = []) {
    $roles += ['owner' => TRUE, 'occupant' => FALSE];

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
    $GLOBALS['myapi_test_users'][self::UID] = ['uid' => self::UID, 'name' => 'pcordero', 'status' => 1];

    $rows = [];
    foreach ($bulletins as $spec) {
      $rows[] = $this->bulletinRow($spec);
    }

    myapi_test_db_seed($tables + [
      'my_api_tokens' => [[
        'id'                => '1',
        'uid'               => (string) self::UID,
        'access_token_hash' => myapi_token_hash(self::TOKEN),
        'revoked'           => '0',
        'access_expires_at' => REQUEST_TIME + 1800,
      ]],
      'field_data_field_propietario' => $roles['owner']
        ? [['entity_id' => (string) self::UNIT, 'field_propietario_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node']]
        : [],
      'field_data_field_ocupante' => [],
      'field_data_field_ocupantes' => $roles['occupant']
        ? [['entity_id' => (string) self::UNIT, 'field_ocupantes_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node']]
        : [],
      'field_data_field_condominio' => [
        ['entity_id' => (string) self::UNIT, 'field_condominio_target_id' => (string) self::CONDOMINIUM, 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'field_data_field_personalizar' => [],
      'node' => $rows,
    ]);
  }

  private function request() {
    return myapi_test_capture('myapi_bulletin_dispatch');
  }

  private function ids(array $result) {
    return array_column($result['json']['data']['bulletins'], 'id');
  }

  /* -------------------------------------------------------------------------
   * Routing and authentication.
   * ---------------------------------------------------------------------- */

  /**
   * Every verb other than GET is 405 and runs no query.
   */
  public function testEveryMethodOtherThanGetIs405AndRunsNoQuery() {
    $this->seed([['id' => 1]]);

    foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
      $_SERVER['REQUEST_METHOD'] = $method;

      $result = $this->request();

      $this->assertSame(405, $result['status'], $method);
      $this->assertSame('method_not_allowed', $result['json']['error_code'], $method);
      $this->assertSame([], myapi_test_db_queries(), $method);
    }
  }

  /**
   * A lowercase verb is still a GET.
   */
  public function testLowercaseGetIsAccepted() {
    $_SERVER['REQUEST_METHOD'] = 'get';
    $this->seed([['id' => 1]]);

    $this->assertSame(200, $this->request()['status']);
  }

  /**
   * Every way of failing the token is a 401 that reads no bulletin.
   */
  public function testEveryFailingTokenIs401AndReadsNoBulletin() {
    $this->seed([['id' => 1]]);
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $result = $this->request();
    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
    $this->assertSame([], myapi_test_db_queries());

    foreach ([
      'unknown' => function () { $GLOBALS['myapi_test_db']['my_api_tokens'] = []; },
      'revoked' => function () { $GLOBALS['myapi_test_db']['my_api_tokens'][0]['revoked'] = '1'; },
      'expired' => function () { $GLOBALS['myapi_test_db']['my_api_tokens'][0]['access_expires_at'] = REQUEST_TIME - 1; },
      'deleted' => function () { $GLOBALS['myapi_test_users'] = []; },
      'blocked' => function () { $GLOBALS['myapi_test_users'][BulletinEndpointTest::UID]['status'] = 0; },
    ] as $name => $break) {
      $this->seed([['id' => 1]]);
      $break();

      $result = $this->request();

      $this->assertSame(401, $result['status'], $name);
      $this->assertSame('invalid_token', $result['json']['error_code'], $name);
      $this->assertSame([], myapi_test_db_queries('node'), $name);
    }
  }

  /* -------------------------------------------------------------------------
   * Branch 'General': the role the reader holds.
   * ---------------------------------------------------------------------- */

  /**
   * The 3x3 grid of the General branch, walked in full: for each role the
   * reader may hold, exactly which audiences reach them.
   *
   * An owner is not an occupant and an occupant is not an owner; both are
   * members. The grid is written out rather than derived, because a rule
   * derived from the code under test proves nothing.
   */
  public function testTheGeneralBranchMatchesExactlyTheRolesTheReaderHolds() {
    $grid = [
      'owner only'    => ['roles' => ['owner' => TRUE, 'occupant' => FALSE], 'visible' => ['Propietarios', 'Todos']],
      'occupant only' => ['roles' => ['owner' => FALSE, 'occupant' => TRUE], 'visible' => ['Ocupantes', 'Todos']],
      'both'          => ['roles' => ['owner' => TRUE, 'occupant' => TRUE], 'visible' => ['Propietarios', 'Ocupantes', 'Todos']],
    ];

    foreach ($grid as $name => $case) {
      $this->seed([
        ['id' => 1, 'type' => 'General', 'send_to' => 'Propietarios'],
        ['id' => 2, 'type' => 'General', 'send_to' => 'Ocupantes'],
        ['id' => 3, 'type' => 'General', 'send_to' => 'Todos'],
      ], $case['roles']);

      $answered = array_column($this->request()['json']['data']['bulletins'], 'send_to');
      sort($answered);
      $expected = $case['visible'];
      sort($expected);

      $this->assertSame($expected, $answered, $name);
    }
  }

  /**
   * A reader with no unit at all sees no General bulletin — not even one
   * addressed to 'Todos'. 'Todos' means every RESIDENT, not every account.
   */
  public function testAReaderWithNoUnitSeesNoGeneralBulletin() {
    $this->seed([
      ['id' => 1, 'type' => 'General', 'send_to' => 'Todos'],
      ['id' => 2, 'type' => 'General', 'send_to' => 'Propietarios'],
    ], ['owner' => FALSE, 'occupant' => FALSE]);

    $result = $this->request();

    $this->assertSame(200, $result['status'], 'no 403: the audience is applied inside the query');
    $this->assertSame([], $this->ids($result));
    $this->assertSame(0, $result['json']['data']['pagination']['total']);
  }

  /**
   * A bulletin whose field_enviar_a is missing or holds a value outside the
   * catalogue matches no branch and stays hidden — the fail-safe the visibility
   * builder documents.
   */
  public function testAnUnknownOrMissingAudienceIsHidden() {
    $this->seed([
      ['id' => 1, 'type' => 'General', 'send_to' => 'Todos'],
      ['id' => 2, 'type' => 'General', 'send_to' => 'Vecinos'],
      ['id' => 3, 'type' => 'General', 'send_to' => NULL],
      ['id' => 4, 'type' => 'Difusion', 'send_to' => 'Todos'],
    ], ['owner' => TRUE, 'occupant' => TRUE]);

    $this->assertSame([1], $this->ids($this->request()));
  }

  /* -------------------------------------------------------------------------
   * Branch 'Condominio': the role AND the building.
   * ---------------------------------------------------------------------- */

  /**
   * A Condominio bulletin reaches the reader only when it names one of THEIR
   * condominiums for a role they hold there.
   */
  public function testACondominioBulletinNeedsBothTheRoleAndTheBuilding() {
    $this->seed([
      ['id' => 1, 'type' => 'Condominio', 'send_to' => 'Propietarios', 'condominium' => self::CONDOMINIUM],
      ['id' => 2, 'type' => 'Condominio', 'send_to' => 'Propietarios', 'condominium' => self::OTHER_CONDOMINIUM],
      ['id' => 3, 'type' => 'Condominio', 'send_to' => 'Ocupantes', 'condominium' => self::CONDOMINIUM],
      ['id' => 4, 'type' => 'Condominio', 'send_to' => 'Todos', 'condominium' => self::CONDOMINIUM],
      ['id' => 5, 'type' => 'Condominio', 'send_to' => 'Todos', 'condominium' => self::OTHER_CONDOMINIUM],
    ], ['owner' => TRUE, 'occupant' => FALSE]);

    // Newest first, and every fixture shares a created timestamp, so the nid
    // tie-breaker is what orders them: 4 before 1.
    $this->assertSame([4, 1], $this->ids($this->request()));
  }

  /**
   * The building of a NEIGHBOUR is never readable, whatever the audience.
   */
  public function testTheBulletinsOfAnotherBuildingAreNeverReadable() {
    $this->seed([
      ['id' => 1, 'type' => 'Condominio', 'send_to' => 'Propietarios', 'condominium' => self::OTHER_CONDOMINIUM],
      ['id' => 2, 'type' => 'Condominio', 'send_to' => 'Ocupantes', 'condominium' => self::OTHER_CONDOMINIUM],
      ['id' => 3, 'type' => 'Condominio', 'send_to' => 'Todos', 'condominium' => self::OTHER_CONDOMINIUM],
    ], ['owner' => TRUE, 'occupant' => TRUE]);

    $result = $this->request();

    $this->assertSame([], $this->ids($result));
    $this->assertSame(0, $result['json']['data']['pagination']['total']);
  }

  /**
   * A Condominio bulletin with no field_condominio row matches nothing: the
   * branch compares against a set, and a NULL is in no set.
   */
  public function testACondominioBulletinWithNoBuildingIsHidden() {
    $this->seed([
      ['id' => 1, 'type' => 'Condominio', 'send_to' => 'Todos', 'condominium' => NULL],
      ['id' => 2, 'type' => 'Condominio', 'send_to' => 'Todos', 'condominium' => self::CONDOMINIUM],
    ], ['owner' => TRUE]);

    $this->assertSame([2], $this->ids($this->request()));
  }

  /**
   * The ROLE IS PER BUILDING. Owning in tower A and occupying in tower B does
   * not make the reader an occupant of A: an 'Ocupantes' bulletin of A stays
   * hidden.
   */
  public function testTheRoleIsResolvedPerBuildingAndNotGlobally() {
    // The reader owns unit 45 (condominium 12) and occupies unit 46
    // (condominium 99).
    $this->seed([
      ['id' => 1, 'type' => 'Condominio', 'send_to' => 'Ocupantes', 'condominium' => self::CONDOMINIUM],
      ['id' => 2, 'type' => 'Condominio', 'send_to' => 'Ocupantes', 'condominium' => self::OTHER_CONDOMINIUM],
      ['id' => 3, 'type' => 'Condominio', 'send_to' => 'Propietarios', 'condominium' => self::CONDOMINIUM],
      ['id' => 4, 'type' => 'Condominio', 'send_to' => 'Propietarios', 'condominium' => self::OTHER_CONDOMINIUM],
    ], ['owner' => TRUE, 'occupant' => FALSE], [
      'field_data_field_ocupantes' => [
        ['entity_id' => '46', 'field_ocupantes_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'field_data_field_condominio' => [
        ['entity_id' => (string) self::UNIT, 'field_condominio_target_id' => (string) self::CONDOMINIUM, 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '46', 'field_condominio_target_id' => (string) self::OTHER_CONDOMINIUM, 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);

    $this->assertSame([3, 2], $this->ids($this->request()));
  }

  /* -------------------------------------------------------------------------
   * Branch 'Personalizado': the reader named on the node.
   * ---------------------------------------------------------------------- */

  /**
   * A Personalizado bulletin reaches the reader only when the node itself
   * references them, through the field matching the audience.
   */
  public function testAPersonalizadoBulletinNeedsTheReaderOnTheNode() {
    $this->seed([
      ['id' => 1, 'type' => 'Personalizado', 'send_to' => 'Propietarios'],
      ['id' => 2, 'type' => 'Personalizado', 'send_to' => 'Propietarios'],
    ], ['owner' => TRUE], [
      'field_data_field_personalizar' => [
        ['entity_id' => '1', 'field_personalizar_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '2', 'field_personalizar_target_id' => '900', 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);

    $this->assertSame([1], $this->ids($this->request()));
  }

  /**
   * The two reference fields are the two audiences: field_personalizar answers
   * for owners, field_ocupantes for occupants, and 'Todos' accepts either.
   */
  public function testTheTwoReferenceFieldsAnswerTheTwoAudiences() {
    $this->seed([
      ['id' => 1, 'type' => 'Personalizado', 'send_to' => 'Propietarios'],
      ['id' => 2, 'type' => 'Personalizado', 'send_to' => 'Ocupantes'],
      ['id' => 3, 'type' => 'Personalizado', 'send_to' => 'Todos'],
      ['id' => 4, 'type' => 'Personalizado', 'send_to' => 'Todos'],
    ], ['owner' => TRUE], [
      // Named as an owner on 1 and 3, as an occupant on 2 and 4.
      'field_data_field_personalizar' => [
        ['entity_id' => '1', 'field_personalizar_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '3', 'field_personalizar_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'field_data_field_ocupantes' => [
        ['entity_id' => '2', 'field_ocupantes_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '4', 'field_ocupantes_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);

    $this->assertSame([4, 3, 2, 1], $this->ids($this->request()));
  }

  /**
   * A Personalizado bulletin addressed to 'Propietarios' does NOT reach a
   * reader who is only named in field_ocupantes, and vice versa: the audience
   * chooses the field.
   */
  public function testTheAudienceChoosesWhichReferenceFieldCounts() {
    $this->seed([
      ['id' => 1, 'type' => 'Personalizado', 'send_to' => 'Propietarios'],
      ['id' => 2, 'type' => 'Personalizado', 'send_to' => 'Ocupantes'],
    ], ['owner' => TRUE], [
      // Named only in the OCCUPANT field of bulletin 1 and only in the OWNER
      // field of bulletin 2 — the wrong field in both cases.
      'field_data_field_ocupantes' => [
        ['entity_id' => '1', 'field_ocupantes_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'field_data_field_personalizar' => [
        ['entity_id' => '2', 'field_personalizar_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);

    $this->assertSame([], $this->ids($this->request()));
  }

  /**
   * THE PERSONALIZADO BRANCH DOES NOT NEED A UNIT. A reader who owns and
   * occupies nothing still receives a bulletin that names them — this is the
   * one branch that does not depend on the reader's sets, which is why the
   * visibility db_or() is never empty.
   */
  public function testAReaderWithNoUnitStillReceivesAPersonalizadoBulletin() {
    $this->seed([
      ['id' => 1, 'type' => 'Personalizado', 'send_to' => 'Todos'],
      ['id' => 2, 'type' => 'General', 'send_to' => 'Todos'],
    ], ['owner' => FALSE, 'occupant' => FALSE], [
      'field_data_field_personalizar' => [
        ['entity_id' => '1', 'field_personalizar_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);

    $this->assertSame([1], $this->ids($this->request()));
  }

  /**
   * THE BRANCH IS GATED BY THE TYPE, and this is the case that proves it: a
   * GENERAL bulletin addressed to a role the reader does not hold stays hidden
   * even though the node happens to name them in field_personalizar.
   *
   * Found by mutation: dropping the
   * `ftipo.field_tipo_de_boletin_value = 'Personalizado'` condition from that
   * branch left every other case of this class green, because none of them had
   * a reader who was named on a node of ANOTHER type. Without the guard, being
   * referenced anywhere would make a bulletin readable regardless of its
   * audience.
   */
  public function testTheReferenceOnlyOpensBulletinsOfThePersonalizadoType() {
    // The reader is an OCCUPANT and not an owner, so the General and
    // Condominio branches of a 'Propietarios' bulletin do not match them —
    // the only thing that could open these two is the reference.
    $this->seed([
      ['id' => 1, 'type' => 'General', 'send_to' => 'Propietarios'],
      ['id' => 2, 'type' => 'Condominio', 'send_to' => 'Propietarios', 'condominium' => self::CONDOMINIUM],
      ['id' => 3, 'type' => 'Personalizado', 'send_to' => 'Propietarios'],
    ], ['owner' => FALSE, 'occupant' => TRUE], [
      'field_data_field_personalizar' => [
        ['entity_id' => '1', 'field_personalizar_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '2', 'field_personalizar_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '3', 'field_personalizar_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);

    $this->assertSame([3], $this->ids($this->request()), 'only the Personalizado one');
  }

  /**
   * A deleted reference row does not make a Personalizado bulletin visible:
   * the sub-select filters on deleted = 0.
   */
  public function testADeletedReferenceRowDoesNotRevealAPersonalizadoBulletin() {
    $this->seed([
      ['id' => 1, 'type' => 'Personalizado', 'send_to' => 'Todos'],
    ], ['owner' => TRUE], [
      'field_data_field_personalizar' => [
        ['entity_id' => '1', 'field_personalizar_target_id' => (string) self::UID, 'deleted' => '1', 'entity_type' => 'node'],
      ],
    ]);

    $this->assertSame([], $this->ids($this->request()));
  }

  /**
   * The sub-select is CORRELATED to the outer node: being named on bulletin 1
   * does not reveal bulletin 2. A sub-select that lost its correlation would
   * show every Personalizado bulletin on the site to anyone named on any of
   * them.
   */
  public function testTheReferenceIsCorrelatedToEachBulletin() {
    $this->seed([
      ['id' => 1, 'type' => 'Personalizado', 'send_to' => 'Todos'],
      ['id' => 2, 'type' => 'Personalizado', 'send_to' => 'Todos'],
      ['id' => 3, 'type' => 'Personalizado', 'send_to' => 'Todos'],
    ], ['owner' => TRUE], [
      'field_data_field_personalizar' => [
        ['entity_id' => '2', 'field_personalizar_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);

    $this->assertSame([2], $this->ids($this->request()));
  }

  /* -------------------------------------------------------------------------
   * The base conditions.
   * ---------------------------------------------------------------------- */

  /**
   * An unpublished bulletin and a node of another type are both excluded, and
   * both are excluded from the count too.
   */
  public function testUnpublishedAndForeignTypesAreExcludedFromListAndCount() {
    $this->seed([
      ['id' => 1],
      ['id' => 2, 'published' => '0'],
    ], ['owner' => TRUE]);
    $GLOBALS['myapi_test_db']['node'][] = ['type' => 'recibo'] + $this->bulletinRow(['id' => 3]);

    $result = $this->request();

    $this->assertSame([1], $this->ids($result));
    $this->assertSame(1, $result['json']['data']['pagination']['total']);
  }

  /* -------------------------------------------------------------------------
   * The ?condominium_id filter (SPEC 31).
   * ---------------------------------------------------------------------- */

  /**
   * A valid id the reader belongs to narrows the Condominio branch to that
   * building and leaves General and Personalizado untouched.
   */
  public function testTheFilterNarrowsOnlyTheCondominioBranch() {
    $this->seed([
      ['id' => 1, 'type' => 'General', 'send_to' => 'Todos'],
      ['id' => 2, 'type' => 'Condominio', 'send_to' => 'Todos', 'condominium' => self::CONDOMINIUM],
      ['id' => 3, 'type' => 'Condominio', 'send_to' => 'Todos', 'condominium' => self::OTHER_CONDOMINIUM],
    ], ['owner' => TRUE], [
      'field_data_field_condominio' => [
        ['entity_id' => (string) self::UNIT, 'field_condominio_target_id' => (string) self::CONDOMINIUM, 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '46', 'field_condominio_target_id' => (string) self::OTHER_CONDOMINIUM, 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'field_data_field_propietario' => [
        ['entity_id' => (string) self::UNIT, 'field_propietario_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '46', 'field_propietario_target_id' => (string) self::UID, 'deleted' => '0', 'entity_type' => 'node'],
      ],
    ]);

    $this->assertSame([3, 2, 1], $this->ids($this->request()), 'unfiltered: both buildings');

    $_GET['condominium_id'] = (string) self::CONDOMINIUM;
    $this->assertSame([2, 1], $this->ids($this->request()), 'the General bulletin survives the filter');
  }

  /**
   * A malformed condominium_id is a 422 naming the field — the one parameter
   * of this endpoint that is NOT lax, because it gates an access decision.
   */
  public function testAMalformedCondominiumIdIs422() {
    $this->seed([['id' => 1]]);

    foreach (['0', '-1', 'abc', '1.5', '+2', '01a'] as $value) {
      $_GET['condominium_id'] = $value;

      $result = $this->request();

      $this->assertSame(422, $result['status'], $value);
      $this->assertSame('invalid_field', $result['json']['error_code'], $value);
      $this->assertStringContainsString('condominium_id', $result['json']['error'], $value);
    }
  }

  /**
   * An ABSENT or empty condominium_id is not a malformation: it is no filter
   * at all, and the endpoint answers the reader's whole visible set.
   */
  public function testAnAbsentOrEmptyCondominiumIdIsNoFilter() {
    $this->seed([['id' => 1, 'type' => 'General', 'send_to' => 'Todos']]);

    $result = $this->request();
    $this->assertSame([1], $this->ids($result));

    $_GET['condominium_id'] = '';
    $result = $this->request();
    $this->assertSame(200, $result['status']);
    $this->assertSame([1], $this->ids($result));
  }

  /**
   * A condominium the reader does not belong to — foreign or non-existent — is
   * 403, and the two answer the same bytes.
   */
  public function testAForeignOrMissingCondominiumIdIs403() {
    $this->seed([['id' => 1]]);

    $_GET['condominium_id'] = (string) self::OTHER_CONDOMINIUM;
    $foreign = $this->request();

    $_GET['condominium_id'] = '4242';
    $missing = $this->request();

    $this->assertSame(403, $foreign['status']);
    $this->assertSame('condominium_access_denied', $foreign['json']['error_code']);
    $this->assertSame($foreign['output'], $missing['output']);
  }

  /**
   * The 422 comes BEFORE the 403: a malformed value is rejected as a
   * malformation, not as a foreign building.
   */
  public function testTheMalformationIsReportedBeforeTheAccessCheck() {
    $this->seed([['id' => 1]], ['owner' => FALSE, 'occupant' => FALSE]);
    $_GET['condominium_id'] = 'abc';

    $this->assertSame(422, $this->request()['status']);
  }

  /**
   * The pure parser answers its three sentinels, which is what lets the caller
   * tell absent from malformed without parsing twice.
   */
  public function testTheParserAnswersItsThreeSentinels() {
    $_GET = [];
    $this->assertNull(myapi_bulletin_parse_condominium_id());

    $_GET = ['condominium_id' => ''];
    $this->assertNull(myapi_bulletin_parse_condominium_id());

    $_GET = ['condominium_id' => '12'];
    $this->assertSame(12, myapi_bulletin_parse_condominium_id());

    foreach (['0', '-3', 'abc', '1.5'] as $value) {
      $_GET = ['condominium_id' => $value];
      $this->assertFalse(myapi_bulletin_parse_condominium_id(), $value);
    }
  }

  /* -------------------------------------------------------------------------
   * Pagination, ordering and the date range.
   * ---------------------------------------------------------------------- */

  /**
   * The documented defaults, and an empty visible set answering 200.
   */
  public function testTheDocumentedDefaultsAndTheEmptySet() {
    $this->seed([['id' => 1], ['id' => 2]]);
    $this->assertSame(
      ['total' => 2, 'page' => 1, 'limit' => 20, 'total_pages' => 1],
      $this->request()['json']['data']['pagination']
    );

    $this->seed([]);
    $result = $this->request();
    $this->assertSame(200, $result['status']);
    $this->assertSame([], $result['json']['data']['bulletins']);
    $this->assertSame(0, $result['json']['data']['pagination']['total_pages']);
    $this->assertStringContainsString('"bulletins":[]', $result['output']);
  }

  /**
   * THIS ENDPOINT HAS NO '-1' SENTINEL. Unlike its receipts/extra-fees/payments
   * cousins, '?limit=-1' is just an invalid value here and falls back to 20 —
   * SPEC 15 did not touch bulletins. Pinned so the divergence is a decision and
   * not a surprise.
   */
  public function testLimitMinusOneIsNotASentinelHere() {
    $this->seed([['id' => 1], ['id' => 2]]);
    $_GET['limit'] = '-1';

    $result = $this->request();

    $this->assertSame(20, $result['json']['data']['pagination']['limit']);
    $this->assertSame(1, $result['json']['data']['pagination']['total_pages']);
  }

  /**
   * limit is clamped to [1, 50], page falls back to 1, and the page slices.
   */
  public function testPaginationClampsSlicesAndCounts() {
    $bulletins = [];
    for ($i = 1; $i <= 7; $i++) {
      $bulletins[] = ['id' => $i, 'created' => self::CREATED + $i];
    }
    $this->seed($bulletins);

    foreach (['0' => 20, 'x' => 20, '51' => 50, '3' => 3] as $sent => $expected) {
      $_GET = ['limit' => (string) $sent];
      $this->assertSame($expected, $this->request()['json']['data']['pagination']['limit'], 'limit=' . $sent);
    }

    $_GET = ['limit' => '3', 'page' => '1'];
    $first = $this->request();
    $_GET['page'] = '3';
    $third = $this->request();

    $this->assertSame(3, $first['json']['data']['pagination']['total_pages']);
    $this->assertSame([7, 6, 5], $this->ids($first));
    $this->assertSame([1], $this->ids($third));
  }

  /**
   * The order is by created, newest first, with nid as a deterministic
   * tie-breaker — so bulletins sharing a timestamp keep a stable order across
   * pages instead of whatever the database felt like.
   */
  public function testTheOrderIsCreatedThenNidAndBothFollowTheDirection() {
    $this->seed([
      ['id' => 1, 'created' => self::CREATED],
      ['id' => 2, 'created' => self::CREATED],
      ['id' => 3, 'created' => self::CREATED + 100],
    ]);

    $this->assertSame([3, 2, 1], $this->ids($this->request()));

    $_GET['sort'] = 'asc';
    $this->assertSame([1, 2, 3], $this->ids($this->request()));

    // Re-seeded so the recorded queries belong to this ?sort=asc request only.
    $this->seed([['id' => 1]]);
    $_GET['sort'] = 'asc';
    $this->request();
    $order = myapi_test_db_queries('node')[1]['order'];
    $this->assertSame([['field' => 'n.created', 'direction' => 'ASC'], ['field' => 'n.nid', 'direction' => 'ASC']], $order);
  }

  /**
   * Any other sort value falls back to descending.
   */
  public function testAnyOtherSortValueFallsBackToDescending() {
    $this->seed([
      ['id' => 1, 'created' => self::CREATED],
      ['id' => 2, 'created' => self::CREATED + 100],
    ]);

    foreach (['ASC', 'Desc', 'created', '', ['asc']] as $value) {
      $_GET['sort'] = $value;
      $this->assertSame([2, 1], $this->ids($this->request()), json_encode($value));
    }
  }

  /**
   * The date range compares against node.created, which is a TIMESTAMP: the
   * bounds are the start and the end of the day given, so a bulletin created
   * at any hour of that day is inside an equal-bounds range.
   */
  public function testTheRangeCoversTheWholeDayOfATimestampColumn() {
    $day = date('Y-m-d', self::CREATED);
    $this->seed([
      ['id' => 1, 'created' => strtotime($day . ' 00:00:01')],
      ['id' => 2, 'created' => strtotime($day . ' 23:59:58')],
      ['id' => 3, 'created' => strtotime($day . ' 00:00:00') - 1],
    ]);

    $_GET = ['date_from' => $day, 'date_to' => $day];
    $result = $this->request();

    $this->assertSame([2, 1], $this->ids($result));
    $this->assertSame(2, $result['json']['data']['pagination']['total']);
  }

  /**
   * A malformed bound is ignored and an inverted range drops both.
   */
  public function testMalformedAndInvertedRangesAreIgnored() {
    $this->seed([['id' => 1], ['id' => 2, 'created' => self::CREATED + 100]]);

    foreach (['2026-13-40', 'hoy', '2026-02-30', ''] as $value) {
      $_GET = ['date_from' => $value];
      $this->assertSame([2, 1], $this->ids($this->request()), $value);
    }

    $_GET = ['date_from' => '2030-01-01', 'date_to' => '2020-01-01'];
    $this->assertSame([2, 1], $this->ids($this->request()));
  }

  /**
   * The range parser answers timestamps, not strings, and the two bounds are
   * the two ends of their days.
   */
  public function testTheRangeParserAnswersDayBounds() {
    $_GET = [];
    $this->assertSame(['from' => NULL, 'to' => NULL], myapi_bulletin_parse_date_range());

    $_GET = ['date_from' => '2026-06-01', 'date_to' => '2026-06-01'];
    $range = myapi_bulletin_parse_date_range();

    $this->assertSame(strtotime('2026-06-01 00:00:00'), $range['from']);
    $this->assertSame(strtotime('2026-06-01 23:59:59'), $range['to']);
    $this->assertSame(86399, $range['to'] - $range['from'], 'a whole day minus one second');
  }

  /* -------------------------------------------------------------------------
   * The mapper and the attachment.
   * ---------------------------------------------------------------------- */

  /**
   * Exactly the ten documented keys, in order.
   */
  public function testTheItemHasExactlyTheTenDocumentedKeysInOrder() {
    $this->seed([['id' => 1]]);

    $item = $this->request()['json']['data']['bulletins'][0];

    $this->assertSame([
      'id', 'title', 'message', 'type', 'send_to', 'condominium_id',
      'file_id', 'file_url', 'file_mime', 'created_at',
    ], array_keys($item));
  }

  /**
   * The casts: id and created_at are always ints; condominium_id and file_id
   * are ints when present and null when not — never 0.
   */
  public function testTheCastsOfTheMapper() {
    $this->seed([[
      'id'          => 501,
      'type'        => 'Condominio',
      'send_to'     => 'Todos',
      'condominium' => self::CONDOMINIUM,
      'created'     => self::CREATED,
    ]]);

    $item = $this->request()['json']['data']['bulletins'][0];
    $this->assertSame(501, $item['id']);
    $this->assertSame(self::CONDOMINIUM, $item['condominium_id']);
    $this->assertSame(self::CREATED, $item['created_at']);

    $this->seed([['id' => 1]]);
    $result = $this->request();
    $item = $result['json']['data']['bulletins'][0];
    $this->assertNull($item['condominium_id']);
    $this->assertNull($item['file_id']);
    $this->assertStringContainsString('"condominium_id":null', $result['output']);
  }

  /**
   * The message travels VERBATIM, HTML included: field_mensaje is rich text and
   * the app is responsible for rendering it safely. No check_plain() here — the
   * case is pinned so adding one becomes a decision.
   */
  public function testTheMessageTravelsVerbatim() {
    $html = '<p>Corte de agua <strong>mañana</strong></p>';
    $this->seed([['id' => 1, 'message' => $html]]);

    $this->assertSame($html, $this->request()['json']['data']['bulletins'][0]['message']);
  }

  /**
   * An attachment answers a public URL and its MIME type, both resolved from
   * the managed file.
   */
  public function testAnAttachmentAnswersItsUrlAndMime() {
    myapi_test_file_seed([
      7 => ['fid' => 7, 'uri' => 'public://boletines/aviso.pdf', 'filemime' => 'application/pdf', 'filename' => 'aviso.pdf'],
    ]);
    $this->seed([['id' => 1, 'file_id' => 7]]);

    $item = $this->request()['json']['data']['bulletins'][0];

    $this->assertSame(7, $item['file_id']);
    $this->assertSame(file_create_url('public://boletines/aviso.pdf'), $item['file_url']);
    $this->assertSame('application/pdf', $item['file_mime']);
  }

  /**
   * A bulletin with no attachment answers three nulls and never a broken URL.
   */
  public function testABulletinWithNoAttachmentAnswersNulls() {
    $this->seed([['id' => 1]]);

    $item = $this->request()['json']['data']['bulletins'][0];

    $this->assertNull($item['file_id']);
    $this->assertNull($item['file_url']);
    $this->assertNull($item['file_mime']);
  }

  /**
   * A STORED fid WHOSE FILE IS GONE keeps the fid and answers null url/mime:
   * the endpoint degrades instead of fataling on a deleted managed file.
   */
  public function testADanglingAttachmentKeepsItsIdAndAnswersNullUrl() {
    myapi_test_file_seed([]);
    $this->seed([['id' => 1, 'file_id' => 7]]);

    $item = $this->request()['json']['data']['bulletins'][0];

    $this->assertSame(7, $item['file_id']);
    $this->assertNull($item['file_url']);
    $this->assertNull($item['file_mime']);
  }

  /**
   * The attachments of a page are loaded in ONE batch, and a page with no
   * attachment loads nothing at all.
   */
  public function testAttachmentsAreLoadedInOneBatchAndOnlyWhenThereAreAny() {
    myapi_test_file_seed([
      7 => ['fid' => 7, 'uri' => 'public://a.pdf', 'filemime' => 'application/pdf', 'filename' => 'a.pdf'],
      8 => ['fid' => 8, 'uri' => 'public://b.png', 'filemime' => 'image/png', 'filename' => 'b.png'],
    ]);
    myapi_test_write_reset();
    $this->seed([
      ['id' => 1, 'file_id' => 7, 'created' => self::CREATED],
      ['id' => 2, 'file_id' => 8, 'created' => self::CREATED + 1],
      ['id' => 3],
    ]);

    $this->request();

    $calls = myapi_test_file_load_multiple_calls();
    $this->assertCount(1, $calls, 'one batch for the whole page');
    $this->assertSame(['8', '7'], $calls[0], 'only the rows that carry a fid, in page order');

    myapi_test_write_reset();
    $this->seed([['id' => 1]]);
    $this->request();
    $this->assertSame([], myapi_test_file_load_multiple_calls(), 'no attachment, no query');
  }

  /* -------------------------------------------------------------------------
   * The envelope.
   * ---------------------------------------------------------------------- */

  /**
   * The documented envelope, and the no-store headers.
   */
  public function testTheEnvelopeHasTheDocumentedShape() {
    $this->seed([['id' => 1]]);

    $result = $this->request();

    $this->assertTrue($result['json']['success']);
    $this->assertSame(['bulletins', 'pagination'], array_keys($result['json']['data']));
    $this->assertSame(['total', 'page', 'limit', 'total_pages'], array_keys($result['json']['data']['pagination']));
    $this->assertStringContainsString('no-store', $result['headers']['Cache-Control']);
  }

  /**
   * The count and the fetch describe the SAME set: the visibility condition
   * travels on both, so a client is never offered a page it cannot reach.
   */
  public function testTheCountAndTheFetchShareTheVisibilityRule() {
    $this->seed([
      ['id' => 1, 'type' => 'General', 'send_to' => 'Propietarios'],
      ['id' => 2, 'type' => 'General', 'send_to' => 'Ocupantes'],
      ['id' => 3, 'type' => 'Condominio', 'send_to' => 'Todos', 'condominium' => self::OTHER_CONDOMINIUM],
    ], ['owner' => TRUE, 'occupant' => FALSE]);

    $result = $this->request();

    $this->assertSame([1], $this->ids($result));
    $this->assertSame(1, $result['json']['data']['pagination']['total']);

    $queries = myapi_test_db_queries('node');
    $this->assertCount(2, $queries);
    $this->assertTrue($queries[0]['count']);
    $this->assertNull($queries[0]['range']);
  }
}
