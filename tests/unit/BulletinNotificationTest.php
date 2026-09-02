<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../includes/myapi.onesignal.inc';
require_once __DIR__ . '/../../includes/myapi.notification.inc';

/**
 * Unit tests for the bulletin fan-out (SPEC 25, covered by SPEC 121).
 *
 * THE OTHER HALF OF THE BULLETIN RULE. BulletinEndpointTest covers the READ
 * side — which bulletins a resident may see — and this class covers the WRITE
 * side: when a 'boletin' is published, WHO gets a row in myapi_notifications
 * and what goes on the push queue.
 *
 * The two must agree, and nothing enforces that they do: the visibility query
 * and the recipient resolver are separate code written from the same table,
 * and a divergence means a resident is notified about a bulletin they cannot
 * open, or opens one they were never told about. Several cases below are
 * written as the mirror of a case in BulletinEndpointTest for exactly that
 * reason, and say so.
 *
 * WHAT MADE THIS TESTABLE. Until SPEC 121 the fixture db_insert() threw, so
 * myapi_notification_create() could never run past its fan-out and nothing
 * downstream of it — the rows, the batching, the queue payload — was
 * observable. See MyapiTestWriteQuery and MyapiTestQueue in bootstrap.php.
 *
 * The FAIL-SAFE is the property most worth naming: an unknown audience or an
 * unknown scope notifies NOBODY and logs, rather than falling through to
 * "everyone". A bug on that path is a mass push to the whole building.
 */
class BulletinNotificationTest extends TestCase {

  const NID = 500;
  const CONDOMINIUM = 12;
  const OTHER_CONDOMINIUM = 99;

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_db_fail_writes();
    myapi_test_queue_reset();
    $GLOBALS['myapi_test_db_writes'] = [];
    $GLOBALS['myapi_test_watchdog'] = [];
    $GLOBALS['myapi_test_users'] = [];
  }

  protected function tearDown(): void {
    myapi_test_db_seed();
    myapi_test_db_fail_writes();
    myapi_test_queue_reset();
    $GLOBALS['myapi_test_watchdog'] = [];
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  /**
   * A 'boletin' node with the audience fields set the Drupal way.
   */
  private function bulletin(array $spec = []) {
    $spec += [
      'nid'         => self::NID,
      'title'       => 'Corte de agua',
      'scope'       => 'General',
      'role'        => 'Todos',
      'message'     => NULL,
      'condominium' => NULL,
      'personalizar' => [],
      'ocupantes'   => [],
    ];

    $node = (object) ['nid' => $spec['nid'], 'title' => $spec['title'], 'type' => 'boletin'];

    if ($spec['scope'] !== NULL) {
      $node->field_tipo_de_boletin = [LANGUAGE_NONE => [['value' => $spec['scope']]]];
    }
    if ($spec['role'] !== NULL) {
      $node->field_enviar_a = [LANGUAGE_NONE => [['value' => $spec['role']]]];
    }
    if ($spec['message'] !== NULL) {
      $node->field_mensaje = [LANGUAGE_NONE => [['value' => $spec['message']]]];
    }
    if ($spec['condominium'] !== NULL) {
      $node->field_condominio = [LANGUAGE_NONE => [['target_id' => $spec['condominium']]]];
    }
    if ($spec['personalizar']) {
      $node->field_personalizar = [LANGUAGE_NONE => array_map(function ($uid) {
        return ['target_id' => $uid];
      }, $spec['personalizar'])];
    }
    if ($spec['ocupantes']) {
      $node->field_ocupantes = [LANGUAGE_NONE => array_map(function ($uid) {
        return ['target_id' => $uid];
      }, $spec['ocupantes'])];
    }

    return $node;
  }

  /**
   * The building: units, their owners/occupants, their condominium, and the
   * users table every recipient is filtered through.
   *
   * @param array $users  uid => status (1 active, 0 blocked).
   */
  private function seedBuilding(array $users = [3 => 1, 4 => 1, 5 => 1]) {
    $user_rows = [];
    foreach ($users as $uid => $status) {
      $user_rows[] = ['uid' => (string) $uid, 'status' => (string) $status, 'name' => 'u' . $uid];
    }

    myapi_test_db_seed([
      // Unit 45 (condominium 12): owner 3, occupant 4.
      // Unit 46 (condominium 99): owner 5.
      'node' => [
        ['nid' => '45', 'type' => 'vivienda', 'status' => '1', 'title' => 'A-101'],
        ['nid' => '46', 'type' => 'vivienda', 'status' => '1', 'title' => 'B-201'],
        ['nid' => '47', 'type' => 'vivienda', 'status' => '0', 'title' => 'C-301 (no publicada)'],
      ],
      'field_data_field_propietario' => [
        ['entity_id' => '45', 'field_propietario_target_id' => '3', 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '46', 'field_propietario_target_id' => '5', 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '47', 'field_propietario_target_id' => '9', 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'field_data_field_ocupante' => [],
      'field_data_field_ocupantes' => [
        ['entity_id' => '45', 'field_ocupantes_target_id' => '4', 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'field_data_field_condominio' => [
        ['entity_id' => '45', 'field_condominio_target_id' => (string) self::CONDOMINIUM, 'deleted' => '0', 'entity_type' => 'node'],
        ['entity_id' => '46', 'field_condominio_target_id' => (string) self::OTHER_CONDOMINIUM, 'deleted' => '0', 'entity_type' => 'node'],
      ],
      'users' => $user_rows,
      'myapi_notifications' => [],
    ]);
    $GLOBALS['myapi_test_db_writes'] = [];
    myapi_test_queue_reset();
  }

  /**
   * The rows the fan-out inserted, in order.
   */
  private function insertedRows() {
    $writes = myapi_test_db_writes('myapi_notifications');
    if (!$writes) {
      return [];
    }

    return isset($writes[0]['rows']) ? $writes[0]['rows'] : [];
  }

  /**
   * The uids the fan-out wrote a row for.
   */
  private function notifiedUids() {
    return array_map(function ($row) {
      return (int) $row['uid'];
    }, $this->insertedRows());
  }

  /* -------------------------------------------------------------------------
   * The four field readers.
   * ---------------------------------------------------------------------- */

  /**
   * myapi_notification_field_value() reads delta 0 of the default language and
   * answers NULL for an absent, empty or differently-shaped field.
   */
  public function testFieldValueReadsDeltaZeroOrNull() {
    $node = $this->bulletin(['scope' => 'Condominio', 'role' => 'Propietarios']);

    $this->assertSame('Condominio', myapi_notification_field_value($node, 'field_tipo_de_boletin'));
    $this->assertSame('Propietarios', myapi_notification_field_value($node, 'field_enviar_a'));
    $this->assertNull(myapi_notification_field_value($node, 'field_mensaje'));
    $this->assertNull(myapi_notification_field_value($node, 'field_inexistente'));

    // A field present but empty, and one whose item carries no 'value'.
    $node->field_vacio = [LANGUAGE_NONE => []];
    $node->field_raro = [LANGUAGE_NONE => [['target_id' => 7]]];
    $this->assertNull(myapi_notification_field_value($node, 'field_vacio'));
    $this->assertNull(myapi_notification_field_value($node, 'field_raro'));
  }

  /**
   * Only delta 0 is read, even when the field carries several values.
   */
  public function testFieldValueReadsOnlyTheFirstDelta() {
    $node = $this->bulletin();
    $node->field_multi = [LANGUAGE_NONE => [['value' => 'primero'], ['value' => 'segundo']]];

    $this->assertSame('primero', myapi_notification_field_value($node, 'field_multi'));
  }

  /**
   * myapi_notification_field_target_id() answers the first reference as an
   * INT — the field stores it as a string — and NULL when there is none.
   */
  public function testFieldTargetIdAnswersAnIntOrNull() {
    $node = $this->bulletin(['condominium' => '12']);

    $this->assertSame(12, myapi_notification_field_target_id($node, 'field_condominio'));
    $this->assertNull(myapi_notification_field_target_id($node, 'field_ausente'));
  }

  /**
   * myapi_notification_field_target_ids() answers every reference as ints, in
   * order, and an empty list for an absent field — never NULL, because the
   * callers merge the result.
   */
  public function testFieldTargetIdsAnswersEveryReference() {
    $node = $this->bulletin(['personalizar' => ['7', '8', '9']]);

    $this->assertSame([7, 8, 9], myapi_notification_field_target_ids($node, 'field_personalizar'));
    $this->assertSame([], myapi_notification_field_target_ids($node, 'field_ausente'));

    // An item with no target_id is skipped rather than answered as 0.
    $node->field_mixto = [LANGUAGE_NONE => [['target_id' => 5], ['value' => 'x'], ['target_id' => 6]]];
    $this->assertSame([5, 6], myapi_notification_field_target_ids($node, 'field_mixto'));
  }

  /**
   * myapi_boletin_role_canonical() maps the three catalogue values and answers
   * NULL for everything else — the fail-safe the resolver reads.
   */
  public function testRoleCanonicalMapsTheThreeValuesAndNothingElse() {
    $this->assertSame('propietarios', myapi_boletin_role_canonical('Propietarios'));
    $this->assertSame('ocupantes', myapi_boletin_role_canonical('Ocupantes'));
    $this->assertSame('todos', myapi_boletin_role_canonical('Todos'));

    foreach (['propietarios', 'PROPIETARIOS', 'Vecinos', '', NULL, 'Todo', 0] as $value) {
      $this->assertNull(myapi_boletin_role_canonical($value), json_encode($value));
    }
  }

  /* -------------------------------------------------------------------------
   * myapi_notification_plain_text(): what the push banner shows.
   * ---------------------------------------------------------------------- */

  /**
   * NULL and the empty string travel through untouched — the caller stores the
   * absence rather than an empty banner.
   */
  public function testPlainTextPassesNullAndEmptyThrough() {
    $this->assertNull(myapi_notification_plain_text(NULL));
    $this->assertSame('', myapi_notification_plain_text(''));
  }

  /**
   * Tags are stripped and block ends become newlines, so the structure of a
   * WYSIWYG message survives as lines instead of running together.
   */
  public function testBlockEndsBecomeNewlinesAndTagsAreStripped() {
    $html = '<p>Primero</p><p>Segundo</p>';

    $this->assertSame("Primero\nSegundo", myapi_notification_plain_text($html));
  }

  /**
   * A <br> is a newline too, in every spelling.
   */
  public function testEveryBrSpellingBecomesANewline() {
    foreach (['<br>', '<br/>', '<br />', '<BR>', '<br  />'] as $br) {
      $this->assertSame("A\nB", myapi_notification_plain_text('A' . $br . 'B'), $br);
    }
  }

  /**
   * A list becomes one line per item.
   */
  public function testAListBecomesOneLinePerItem() {
    $html = '<ul><li>Agua</li><li>Luz</li></ul>';

    $this->assertSame("Agua\nLuz", myapi_notification_plain_text($html));
  }

  /**
   * Entities are decoded, including the non-breaking space, which becomes an
   * ordinary one instead of a stray U+00A0 in the banner.
   */
  public function testEntitiesAreDecodedAndNbspBecomesASpace() {
    $this->assertSame('Café & té', myapi_notification_plain_text('Caf&eacute; &amp; t&eacute;'));
    $this->assertSame('A B', myapi_notification_plain_text('A&nbsp;B'));
    $this->assertSame('"comillas"', myapi_notification_plain_text('&quot;comillas&quot;'));
  }

  /**
   * The source indentation of a WYSIWYG is squeezed away: every line is
   * trimmed and its internal runs of spaces and tabs collapse to one.
   */
  public function testIndentationAndInternalRunsAreSqueezed() {
    $html = "<p>   Aviso    importante   </p>\n<p>\t\tSegunda\t\tlínea</p>";

    $this->assertSame("Aviso importante\nSegunda línea", myapi_notification_plain_text($html));
  }

  /**
   * Blank lines are dropped entirely — an empty paragraph must not spread the
   * banner out.
   */
  public function testBlankLinesAreDropped() {
    $html = '<p>Uno</p><p></p><p>   </p><p>Dos</p>';

    $this->assertSame("Uno\nDos", myapi_notification_plain_text($html));
  }

  /**
   * Windows and old-Mac line endings are normalised before the per-line pass,
   * so the result never carries a stray carriage return.
   */
  public function testEveryLineEndingIsNormalised() {
    $this->assertSame("Uno\nDos", myapi_notification_plain_text("Uno\r\nDos"));
    $this->assertSame("Uno\nDos", myapi_notification_plain_text("Uno\rDos"));
  }

  /**
   * A message that is only markup collapses to an empty string rather than to
   * a line of whitespace.
   */
  public function testAMessageOfOnlyMarkupCollapsesToEmpty() {
    $this->assertSame('', myapi_notification_plain_text('<p></p><br><div>   </div>'));
  }

  /**
   * Plain text with no markup at all is returned as it is, accents included.
   */
  public function testPlainTextWithoutMarkupIsUnchanged() {
    $this->assertSame('Mañana no habrá agua', myapi_notification_plain_text('Mañana no habrá agua'));
  }

  /**
   * A '0' is a real message and is not dropped: the guard is a comparison
   * against '' and NULL, not an empty() check.
   */
  public function testAMessageOfZeroSurvives() {
    $this->assertSame('0', myapi_notification_plain_text('0'));
  }

  /* -------------------------------------------------------------------------
   * myapi_boletin_recipient_uids(): the three scopes.
   * ---------------------------------------------------------------------- */

  /**
   * 'General' resolves over every PUBLISHED unit of the site, and the role
   * decides which side of them. This is the write-side mirror of
   * BulletinEndpointTest::testTheGeneralBranchMatchesExactlyTheRolesTheReaderHolds.
   */
  public function testTheGeneralScopeResolvesOverEveryPublishedUnit() {
    $cases = [
      'Propietarios' => [3, 5],
      'Ocupantes'    => [4],
      'Todos'        => [3, 5, 4],
    ];

    foreach ($cases as $role => $expected) {
      $this->seedBuilding();

      $uids = myapi_boletin_recipient_uids($this->bulletin(['scope' => 'General', 'role' => $role]));

      sort($uids);
      sort($expected);
      $this->assertSame($expected, $uids, $role);
    }
  }

  /**
   * The owner of an UNPUBLISHED unit is not notified: the universe is the
   * published units, and uid 9 owns only unit 47.
   */
  public function testTheOwnerOfAnUnpublishedUnitIsNotNotified() {
    $this->seedBuilding([3 => 1, 4 => 1, 5 => 1, 9 => 1]);

    $uids = myapi_boletin_recipient_uids($this->bulletin(['scope' => 'General', 'role' => 'Todos']));

    $this->assertNotContains(9, $uids);
  }

  /**
   * 'Condominio' resolves over the units of the referenced building only.
   */
  public function testTheCondominioScopeIsLimitedToItsBuilding() {
    $this->seedBuilding();

    $uids = myapi_boletin_recipient_uids($this->bulletin([
      'scope'       => 'Condominio',
      'role'        => 'Todos',
      'condominium' => self::CONDOMINIUM,
    ]));

    sort($uids);
    $this->assertSame([3, 4], $uids, 'uid 5 lives in the other building');
  }

  /**
   * The role narrows inside the building too.
   */
  public function testTheCondominioScopeStillHonoursTheRole() {
    $this->seedBuilding();

    $owners = myapi_boletin_recipient_uids($this->bulletin([
      'scope' => 'Condominio', 'role' => 'Propietarios', 'condominium' => self::CONDOMINIUM,
    ]));
    $occupants = myapi_boletin_recipient_uids($this->bulletin([
      'scope' => 'Condominio', 'role' => 'Ocupantes', 'condominium' => self::CONDOMINIUM,
    ]));

    $this->assertSame([3], $owners);
    $this->assertSame([4], $occupants);
  }

  /**
   * A 'Condominio' bulletin with no field_condominio notifies nobody — and
   * does so silently, because a missing reference is an editorial mistake and
   * not an unknown catalogue value.
   */
  public function testACondominioBulletinWithNoBuildingNotifiesNobody() {
    $this->seedBuilding();

    $uids = myapi_boletin_recipient_uids($this->bulletin(['scope' => 'Condominio', 'role' => 'Todos']));

    $this->assertSame([], $uids);
  }

  /**
   * A building nobody lives in notifies nobody.
   */
  public function testAnEmptyBuildingNotifiesNobody() {
    $this->seedBuilding();

    $uids = myapi_boletin_recipient_uids($this->bulletin([
      'scope' => 'Condominio', 'role' => 'Todos', 'condominium' => 777,
    ]));

    $this->assertSame([], $uids);
  }

  /**
   * 'Personalizado' reads the users named ON THE NODE, and the role chooses
   * which of the two reference fields counts — the write-side mirror of
   * BulletinEndpointTest::testTheAudienceChoosesWhichReferenceFieldCounts.
   */
  public function testThePersonalizadoScopeReadsTheNodesOwnReferences() {
    $this->seedBuilding([3 => 1, 4 => 1, 5 => 1, 7 => 1, 8 => 1]);

    $owners = myapi_boletin_recipient_uids($this->bulletin([
      'scope' => 'Personalizado', 'role' => 'Propietarios',
      'personalizar' => [7], 'ocupantes' => [8],
    ]));
    $occupants = myapi_boletin_recipient_uids($this->bulletin([
      'scope' => 'Personalizado', 'role' => 'Ocupantes',
      'personalizar' => [7], 'ocupantes' => [8],
    ]));
    $both = myapi_boletin_recipient_uids($this->bulletin([
      'scope' => 'Personalizado', 'role' => 'Todos',
      'personalizar' => [7], 'ocupantes' => [8],
    ]));

    $this->assertSame([7], $owners);
    $this->assertSame([8], $occupants);
    sort($both);
    $this->assertSame([7, 8], $both);
  }

  /**
   * 'Personalizado' IGNORES field_condominio: the reference is the whole rule,
   * and a named user in another building is still notified.
   */
  public function testThePersonalizadoScopeIgnoresTheBuilding() {
    $this->seedBuilding([3 => 1, 5 => 1]);

    $uids = myapi_boletin_recipient_uids($this->bulletin([
      'scope' => 'Personalizado', 'role' => 'Propietarios',
      'condominium' => self::CONDOMINIUM, 'personalizar' => [5],
    ]));

    $this->assertSame([5], $uids, 'uid 5 lives in the other building and is still notified');
  }

  /**
   * A user named twice — in both fields of a 'Todos' bulletin — gets ONE row,
   * not two.
   */
  public function testAUserNamedTwiceIsNotifiedOnce() {
    $this->seedBuilding([3 => 1, 7 => 1]);

    $uids = myapi_boletin_recipient_uids($this->bulletin([
      'scope' => 'Personalizado', 'role' => 'Todos',
      'personalizar' => [7], 'ocupantes' => [7],
    ]));

    $this->assertSame([7], $uids);
  }

  /* -------------------------------------------------------------------------
   * The fail-safes.
   * ---------------------------------------------------------------------- */

  /**
   * AN UNKNOWN SCOPE NOTIFIES NOBODY AND LOGS. The default branch returns
   * immediately, before any resolution: a bug here would be a mass push.
   */
  public function testAnUnknownScopeNotifiesNobodyAndLogs() {
    foreach (['Difusion', 'general', '', NULL] as $scope) {
      $this->seedBuilding();
      $GLOBALS['myapi_test_watchdog'] = [];

      $uids = myapi_boletin_recipient_uids($this->bulletin(['scope' => $scope, 'role' => 'Todos']));

      $this->assertSame([], $uids, json_encode($scope));
      $this->assertNotSame([], $GLOBALS['myapi_test_watchdog'], json_encode($scope));
      $this->assertStringContainsString('field_tipo_de_boletin', $GLOBALS['myapi_test_watchdog'][0]['text'], json_encode($scope));
    }
  }

  /**
   * AN UNKNOWN ROLE NOTIFIES NOBODY AND LOGS, on both scopes that read it.
   */
  public function testAnUnknownRoleNotifiesNobodyAndLogs() {
    foreach (['General', 'Condominio'] as $scope) {
      foreach (['Vecinos', 'todos', '', NULL] as $role) {
        $this->seedBuilding();
        $GLOBALS['myapi_test_watchdog'] = [];

        $uids = myapi_boletin_recipient_uids($this->bulletin([
          'scope' => $scope, 'role' => $role, 'condominium' => self::CONDOMINIUM,
        ]));

        $label = $scope . '/' . json_encode($role);
        $this->assertSame([], $uids, $label);
        $this->assertNotSame([], $GLOBALS['myapi_test_watchdog'], $label);
        $this->assertStringContainsString('field_enviar_a', $GLOBALS['myapi_test_watchdog'][0]['text'], $label);
      }
    }
  }

  /**
   * On 'Personalizado' an unknown role notifies nobody too — neither reference
   * field is read, because neither branch matches — and it still logs.
   */
  public function testAnUnknownRoleOnPersonalizadoReadsNeitherField() {
    $this->seedBuilding([7 => 1, 8 => 1]);

    $uids = myapi_boletin_recipient_uids($this->bulletin([
      'scope' => 'Personalizado', 'role' => 'Vecinos',
      'personalizar' => [7], 'ocupantes' => [8],
    ]));

    $this->assertSame([], $uids);
    $this->assertNotSame([], $GLOBALS['myapi_test_watchdog']);
  }

  /**
   * BLOCKED ACCOUNTS ARE FILTERED OUT. A resolved uid still has to be an
   * active user, or a disabled account keeps receiving pushes.
   */
  public function testBlockedAccountsAreNeverNotified() {
    $this->seedBuilding([3 => 1, 4 => 0, 5 => 1]);

    $uids = myapi_boletin_recipient_uids($this->bulletin(['scope' => 'General', 'role' => 'Todos']));

    sort($uids);
    $this->assertSame([3, 5], $uids, 'uid 4 is blocked');
  }

  /**
   * A resolved uid with no users row at all is dropped as well — a deleted
   * account referenced by a stale field row.
   */
  public function testAUidWithNoAccountIsDropped() {
    $this->seedBuilding([3 => 1]);

    $uids = myapi_boletin_recipient_uids($this->bulletin([
      'scope' => 'Personalizado', 'role' => 'Propietarios', 'personalizar' => [3, 4242],
    ]));

    $this->assertSame([3], $uids);
  }

  /**
   * With nothing resolved the active-user query is not even run: there is
   * nothing to filter, and an "IN ()" is invalid SQL in Drupal 7.
   */
  public function testAnEmptySetSkipsTheActiveUserQuery() {
    $this->seedBuilding();

    myapi_boletin_recipient_uids($this->bulletin([
      'scope' => 'Condominio', 'role' => 'Todos', 'condominium' => 777,
    ]));

    $this->assertSame([], myapi_test_db_queries('users'));
  }

  /* -------------------------------------------------------------------------
   * myapi_notification_create_from_boletin(): the rows and the push.
   * ---------------------------------------------------------------------- */

  /**
   * The glue writes one row per recipient with the documented columns, and
   * answers how many.
   */
  public function testOneRowPerRecipientWithTheDocumentedColumns() {
    $this->seedBuilding();

    $count = myapi_notification_create_from_boletin($this->bulletin([
      'scope' => 'Condominio', 'role' => 'Todos', 'condominium' => self::CONDOMINIUM,
      'message' => '<p>Corte de agua</p>',
    ]));

    $this->assertSame(2, $count);

    $rows = $this->insertedRows();
    $this->assertCount(2, $rows);
    $this->assertSame([3, 4], $this->notifiedUids());

    $row = $rows[0];
    $this->assertSame(MYAPI_NOTIFICATION_SOURCE_BOLETIN, $row['source_type']);
    $this->assertSame(self::NID, $row['source_nid']);
    $this->assertSame(MYAPI_NOTIFICATION_TYPE_BULLETIN, $row['type']);
    $this->assertSame('Corte de agua', $row['title']);
    $this->assertSame('Corte de agua', $row['body']);
    $this->assertSame(MYAPI_NOTIFICATION_DEEP_LINK_BULLETIN, $row['deep_link_target']);
    $this->assertSame(self::NID, $row['deep_link_id']);
    $this->assertSame(self::CONDOMINIUM, $row['condominium_id']);
    $this->assertSame(0, $row['is_read']);
    $this->assertSame(REQUEST_TIME, $row['created']);
    $this->assertNull($row['read_at']);
  }

  /**
   * THE type IS THE CONSTANT AND NOT THE AUDIENCE FIELD. Every bulletin
   * notification is of type 'bulletin', whatever field_tipo_de_boletin says —
   * that field is the audience, not the category the app switches on.
   */
  public function testTheTypeIsTheConstantAndNotTheAudience() {
    foreach (['General', 'Condominio', 'Personalizado'] as $scope) {
      $this->seedBuilding([3 => 1, 7 => 1]);

      myapi_notification_create_from_boletin($this->bulletin([
        'scope' => $scope, 'role' => 'Todos',
        'condominium' => self::CONDOMINIUM, 'personalizar' => [7],
      ]));

      $rows = $this->insertedRows();
      $this->assertNotEmpty($rows, $scope);
      $this->assertSame(MYAPI_NOTIFICATION_TYPE_BULLETIN, $rows[0]['type'], $scope);
    }
  }

  /**
   * condominium_id IS ONLY EXACT FOR A 'Condominio' BULLETIN. The other two
   * scopes span buildings, so the column stays NULL rather than carrying a
   * building that only some recipients belong to.
   */
  public function testTheCondominiumIsStampedOnlyForACondominioBulletin() {
    $this->seedBuilding([3 => 1, 4 => 1, 5 => 1, 7 => 1]);
    myapi_notification_create_from_boletin($this->bulletin([
      'scope' => 'Condominio', 'role' => 'Todos', 'condominium' => self::CONDOMINIUM,
    ]));
    $this->assertSame(self::CONDOMINIUM, $this->insertedRows()[0]['condominium_id']);

    $this->seedBuilding([3 => 1, 4 => 1, 5 => 1]);
    myapi_notification_create_from_boletin($this->bulletin(['scope' => 'General', 'role' => 'Todos']));
    $this->assertNull($this->insertedRows()[0]['condominium_id']);

    // Even when the editor set field_condominio on a Personalizado bulletin,
    // which the resolver ignores.
    $this->seedBuilding([7 => 1]);
    myapi_notification_create_from_boletin($this->bulletin([
      'scope' => 'Personalizado', 'role' => 'Propietarios',
      'condominium' => self::CONDOMINIUM, 'personalizar' => [7],
    ]));
    $this->assertNull($this->insertedRows()[0]['condominium_id']);
  }

  /**
   * The body is the FLATTENED message: the row carries plain text, because the
   * push banner cannot render markup.
   */
  public function testTheStoredBodyIsPlainText() {
    $this->seedBuilding([3 => 1]);

    myapi_notification_create_from_boletin($this->bulletin([
      'scope' => 'Personalizado', 'role' => 'Propietarios', 'personalizar' => [3],
      'message' => "<p>Primero</p><ul><li>Agua</li></ul>",
    ]));

    $this->assertSame("Primero\nAgua", $this->insertedRows()[0]['body']);
  }

  /**
   * A bulletin with no message stores a NULL body rather than an empty string.
   */
  public function testABulletinWithNoMessageStoresANullBody() {
    $this->seedBuilding([3 => 1]);

    myapi_notification_create_from_boletin($this->bulletin([
      'scope' => 'Personalizado', 'role' => 'Propietarios', 'personalizar' => [3],
    ]));

    $this->assertNull($this->insertedRows()[0]['body']);
  }

  /**
   * WITH NO RECIPIENT NOTHING HAPPENS: no insert, no queue item, and the
   * answer is 0. The short-circuit is what keeps an unknown audience from
   * costing a write.
   */
  public function testWithNoRecipientNothingIsWrittenOrQueued() {
    $this->seedBuilding();

    $count = myapi_notification_create_from_boletin($this->bulletin([
      'scope' => 'Condominio', 'role' => 'Todos', 'condominium' => 777,
    ]));

    $this->assertSame(0, $count);
    $this->assertSame([], myapi_test_db_writes());
    $this->assertSame([], myapi_test_queue_items());
  }

  /* -------------------------------------------------------------------------
   * The deferred push.
   * ---------------------------------------------------------------------- */

  /**
   * The push is ENQUEUED and never sent inline: the queue carries one item and
   * no HTTP request leaves the server during the node save.
   */
  public function testThePushIsQueuedAndNotSentInline() {
    myapi_test_http_reset();
    $this->seedBuilding();

    myapi_notification_create_from_boletin($this->bulletin(['scope' => 'General', 'role' => 'Todos']));

    $items = myapi_test_queue_items(MYAPI_ONESIGNAL_QUEUE);
    $this->assertCount(1, $items);
    $this->assertSame([], myapi_test_http_requests(), 'nothing left the server synchronously');
  }

  /**
   * The queued payload carries the external ids AS STRINGS, the title, the
   * body and the deep-link data the app opens.
   */
  public function testTheQueuedPayloadIsTheDocumentedOne() {
    $this->seedBuilding();

    myapi_notification_create_from_boletin($this->bulletin([
      'scope' => 'Condominio', 'role' => 'Todos', 'condominium' => self::CONDOMINIUM,
      'message' => '<p>Corte de agua</p>',
    ]));

    $item = myapi_test_queue_items(MYAPI_ONESIGNAL_QUEUE)[0]['data'];

    $this->assertSame(['3', '4'], $item['external_ids']);
    $this->assertSame('Corte de agua', $item['title']);
    $this->assertSame('Corte de agua', $item['body']);
    $this->assertSame([
      'target'            => MYAPI_NOTIFICATION_DEEP_LINK_BULLETIN,
      'id'                => self::NID,
      'unit'              => NULL,
      'condominium'       => self::CONDOMINIUM,
      'notification_type' => MYAPI_NOTIFICATION_TYPE_BULLETIN,
      'audience'          => MYAPI_NOTIFICATION_AUDIENCE_RESIDENT,
      'provider'          => NULL,
    ], $item['data']);
  }

  /**
   * A bulletin with no message queues an EMPTY STRING body, not a null: the
   * push payload has no null body.
   */
  public function testTheQueuedBodyOfAMessagelessBulletinIsAnEmptyString() {
    $this->seedBuilding([3 => 1]);

    myapi_notification_create_from_boletin($this->bulletin([
      'scope' => 'Personalizado', 'role' => 'Propietarios', 'personalizar' => [3],
    ]));

    $item = myapi_test_queue_items(MYAPI_ONESIGNAL_QUEUE)[0]['data'];

    $this->assertSame('', $item['body']);
    $this->assertNull($this->insertedRows()[0]['body'], 'the inbox row keeps the NULL');
  }

  /**
   * The audience is 'resident' by default: the bulletin trigger predates
   * SPEC 109 and passes no audience, so the app opens the resident side.
   */
  public function testTheDefaultAudienceIsResident() {
    $this->seedBuilding([3 => 1]);

    myapi_notification_create_from_boletin($this->bulletin([
      'scope' => 'Personalizado', 'role' => 'Propietarios', 'personalizar' => [3],
    ]));

    $data = myapi_test_queue_items(MYAPI_ONESIGNAL_QUEUE)[0]['data']['data'];
    $this->assertSame('resident', $data['audience']);
    $this->assertNull($data['provider']);
  }

  /**
   * THE BATCHING. myapi_notification_create() chunks the external ids by
   * MYAPI_ONESIGNAL_MAX_EXTERNAL_IDS, so a building larger than one request
   * becomes several queue items — and the inbox rows are still one insert.
   */
  public function testTheExternalIdsAreChunkedByTheOneSignalLimit() {
    $recipients = range(1000, 1000 + MYAPI_ONESIGNAL_MAX_EXTERNAL_IDS);
    $users = [];
    foreach ($recipients as $uid) {
      $users[$uid] = 1;
    }
    $this->seedBuilding($users);

    $count = myapi_notification_create([
      'source_type'      => MYAPI_NOTIFICATION_SOURCE_BOLETIN,
      'source_nid'       => self::NID,
      'type'             => MYAPI_NOTIFICATION_TYPE_BULLETIN,
      'title'            => 'Masivo',
      'deep_link_target' => MYAPI_NOTIFICATION_DEEP_LINK_BULLETIN,
      'deep_link_id'     => self::NID,
      'uids'             => $recipients,
    ]);

    $this->assertSame(MYAPI_ONESIGNAL_MAX_EXTERNAL_IDS + 1, $count);

    $items = myapi_test_queue_items(MYAPI_ONESIGNAL_QUEUE);
    $this->assertCount(2, $items, 'one item per chunk');
    $this->assertCount(MYAPI_ONESIGNAL_MAX_EXTERNAL_IDS, $items[0]['data']['external_ids']);
    $this->assertCount(1, $items[1]['data']['external_ids']);
    $this->assertCount(1, myapi_test_db_writes('myapi_notifications'), 'still one multi-row insert');
  }

  /**
   * myapi_notification_create() dedupes and casts its uids before doing
   * anything: the same recipient twice is one row and one external id.
   */
  public function testTheCreateEntryPointDedupesItsRecipients() {
    $this->seedBuilding([3 => 1, 4 => 1]);

    $count = myapi_notification_create([
      'source_type'      => MYAPI_NOTIFICATION_SOURCE_BOLETIN,
      'source_nid'       => self::NID,
      'type'             => MYAPI_NOTIFICATION_TYPE_BULLETIN,
      'title'            => 'Aviso',
      'deep_link_target' => MYAPI_NOTIFICATION_DEEP_LINK_BULLETIN,
      'deep_link_id'     => self::NID,
      'uids'             => [3, '3', 4, 3],
    ]);

    $this->assertSame(2, $count);
    $this->assertSame([3, 4], $this->notifiedUids());
    $this->assertSame(['3', '4'], myapi_test_queue_items(MYAPI_ONESIGNAL_QUEUE)[0]['data']['external_ids']);
  }

  /**
   * An empty recipient list is a 0 with no write and no queue item — the guard
   * every trigger of the module relies on.
   */
  public function testTheCreateEntryPointShortCircuitsOnAnEmptyList() {
    $this->seedBuilding();

    $this->assertSame(0, myapi_notification_create(['uids' => [], 'source_type' => 'x', 'type' => 'y', 'title' => 'z']));
    $this->assertSame(0, myapi_notification_create(['source_type' => 'x', 'type' => 'y', 'title' => 'z']));
    $this->assertSame([], myapi_test_db_writes());
    $this->assertSame([], myapi_test_queue_items());
  }
}
