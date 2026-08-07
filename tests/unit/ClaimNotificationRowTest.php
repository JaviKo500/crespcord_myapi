<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.building_admin.inc';
require_once __DIR__ . '/../../includes/myapi.unit_access.inc';
require_once __DIR__ . '/../../includes/myapi.claim_notification.inc';

/**
 * Unit tests for the half of includes/myapi.claim_notification.inc that
 * ClaimNotificationTest (SPEC 68) left out: the equivalent ROW every text is
 * built from, the three detectors that decide whether anything is sent at all,
 * the audience resolver, and the two mail param sets.
 *
 * That class covers the strings; this one covers what decides WHETHER a string
 * is produced and WITH WHICH values. Its docblock names these functions as
 * "database and Field API, i.e. exactly what tests/unit avoids" — true when it
 * was written, and no longer true since SPEC 74/77 gave the layer a fixture
 * db_select(), a node_load() and a user_load(). Nothing about that class
 * changes; this one extends the coverage past the line it drew.
 *
 * Why the detectors matter more than their size suggests: each one is a single
 * boolean standing between a save and a push to every neighbour of a building.
 * myapi_claim_is_publication_transition() firing on the wrong save announces a
 * claim that was already public — or, in the inverse direction, points a
 * neighbourhood at something that just stopped being visible.
 *
 * Out of scope here, and named rather than skipped in silence: the four
 * orchestrators (myapi_claim_notify_created/published/transaction/
 * closed_by_requester) and the two channel helpers
 * (myapi_claim_notify_inbox(), myapi_claim_enqueue_mails()). They write to the
 * notification table and to the mail queue through includes this layer does not
 * load, and what they compose — the texts and the row — is exactly what is
 * covered here and in ClaimNotificationTest.
 */
class ClaimNotificationRowTest extends TestCase {

  protected function setUp(): void {
    myapi_test_db_seed();
    myapi_test_node_seed();
    myapi_test_static_reset();
    $GLOBALS['myapi_test_users'] = [];
    unset($GLOBALS['myapi_test_profile_fields']);
  }

  protected function tearDown(): void {
    myapi_test_db_seed();
    myapi_test_node_seed();
    myapi_test_static_reset();
    $GLOBALS['myapi_test_users'] = [];
    unset($GLOBALS['myapi_test_profile_fields']);
  }

  /**
   * A 'reclamo' node with every field the row reads.
   */
  private function claimNode(array $overrides = []) {
    $node = (object) ($overrides + [
      'nid'     => 140,
      'type'    => 'reclamo',
      'title'   => 'Fuga en el pasillo',
      'created' => mktime(16, 45, 0, 8, 4, 2026),
    ]);

    $fields = [
      'field_description'    => [['value' => 'Hay agua desde el martes.']],
      'field_claim_type'     => [['value' => 'claim']],
      'field_visibility'     => [['value' => 'public']],
      'field_status'         => [['value' => 'received']],
      'field_condominium'    => [['target_id' => 12]],
      'field_requester'      => [['target_id' => 3]],
      'field_reception_date' => [['value' => '2026-08-04 16:45:00']],
    ];

    foreach ($fields as $name => $items) {
      if (!isset($node->{$name}) && !array_key_exists($name, $overrides)) {
        $node->{$name}[LANGUAGE_NONE] = $items;
      }
    }

    return $node;
  }

  /**
   * The condominium and the requester the fixture node points at.
   */
  private function seedRelated(array $account = ['uid' => 3, 'name' => 'pcordero', 'mail' => 'p@example.com', 'status' => 1]) {
    myapi_test_node_seed([12 => ['nid' => 12, 'type' => 'condominio', 'title' => 'Edificio El Sáuco']]);
    if ($account) {
      $GLOBALS['myapi_test_users'][$account['uid']] = $account;
    }
  }

  /* -------------------------------------------------------------------------
   * myapi_claim_notification_row() — the resolved row.
   * ---------------------------------------------------------------------- */

  /**
   * The complete row: every value the five emails and the six texts read,
   * resolved once so none of them goes back to the node.
   */
  public function testTheRowResolvesEveryDocumentedValue() {
    $this->seedRelated();
    $GLOBALS['myapi_test_profile_fields'] = ['first_name' => 'Priscila', 'last_name' => 'Cordero'];

    $row = myapi_claim_notification_row($this->claimNode());

    $this->assertSame(140, $row->nid);
    $this->assertSame('Fuga en el pasillo', $row->subject);
    $this->assertSame('Hay agua desde el martes.', $row->description);
    $this->assertSame('claim', $row->claim_type);
    $this->assertSame('Reclamo', $row->type_label);
    $this->assertSame('public', $row->visibility);
    $this->assertSame('received', $row->status);
    $this->assertSame('Recibido', $row->status_label);
    $this->assertSame(12, $row->condominium_id);
    $this->assertSame('Edificio El Sáuco', $row->condominium_name);
    $this->assertSame(3, $row->requester_uid);
    $this->assertSame('Priscila Cordero', $row->requester_name);
    $this->assertSame('p@example.com', $row->requester_mail);
    $this->assertSame(mktime(16, 45, 0, 8, 4, 2026), $row->reception_date);
    $this->assertSame(0, $row->image_count);
    $this->assertFalse($row->has_attachment);
  }

  /**
   * The requester's display name is the SPEC 54 profile pair, not the
   * username: the back-office email says "Priscila Cordero", not "pcordero".
   */
  public function testTheRequesterNameIsTheProfilePairWhenItExists() {
    $this->seedRelated();
    $GLOBALS['myapi_test_profile_fields'] = ['first_name' => 'Priscila', 'last_name' => 'Cordero'];

    $row = myapi_claim_notification_row($this->claimNode());

    $this->assertSame('Priscila Cordero', $row->requester_name);
  }

  /**
   * With no profile fields it falls back to the username — never to an empty
   * string, and never to a half name with a dangling space.
   */
  public function testTheRequesterNameFallsBackToTheUsername() {
    $this->seedRelated();
    $GLOBALS['myapi_test_profile_fields'] = ['first_name' => NULL, 'last_name' => NULL];

    $row = myapi_claim_notification_row($this->claimNode());

    $this->assertSame('pcordero', $row->requester_name);
  }

  /**
   * Half a profile is still a name, trimmed on the missing side.
   */
  public function testHalfAProfileNameIsTrimmed() {
    $this->seedRelated();
    $GLOBALS['myapi_test_profile_fields'] = ['first_name' => 'Priscila', 'last_name' => NULL];

    $row = myapi_claim_notification_row($this->claimNode());

    $this->assertSame('Priscila', $row->requester_name);
  }

  /**
   * A requester whose account was deleted keeps the uid and gets the same
   * label the claims listing prints for it — no notice, no empty name.
   */
  public function testADeletedRequesterKeepsItsUidAndGetsALabel() {
    myapi_test_node_seed([12 => ['nid' => 12, 'type' => 'condominio', 'title' => 'Edificio El Sáuco']]);
    $GLOBALS['myapi_test_users'] = [];

    $row = myapi_claim_notification_row($this->claimNode());

    $this->assertSame(3, $row->requester_uid);
    $this->assertSame('Usuario eliminado (#3)', $row->requester_name);
    $this->assertNull($row->requester_mail);
  }

  /**
   * A claim filed from the back office with no requester at all: NULL uid and
   * NULL name, never node.uid — which would send the acknowledgement to the
   * operator who typed it.
   */
  public function testAClaimWithoutARequesterHasNoUidAndNoName() {
    $this->seedRelated();
    $node = $this->claimNode(['field_requester' => []]);
    $node->uid = 41;

    $row = myapi_claim_notification_row($node);

    $this->assertNull($row->requester_uid);
    $this->assertNull($row->requester_name);
    $this->assertNull($row->requester_mail);
  }

  /**
   * An account with an empty address answers NULL rather than '', so the mail
   * queue skips it instead of enqueuing to nowhere.
   */
  public function testAnEmptyMailAddressBecomesNull() {
    $this->seedRelated(['uid' => 3, 'name' => 'pcordero', 'mail' => '', 'status' => 1]);

    $row = myapi_claim_notification_row($this->claimNode());

    $this->assertNull($row->requester_mail);
  }

  /**
   * A claim with no condominium gets the documented placeholder, not an empty
   * heading in the email.
   */
  public function testAClaimWithoutACondominiumGetsThePlaceholder() {
    $this->seedRelated();

    $row = myapi_claim_notification_row($this->claimNode(['field_condominium' => []]));

    $this->assertNull($row->condominium_id);
    $this->assertSame('Sin condominio', $row->condominium_name);
  }

  /**
   * And so does one whose condominium node no longer loads.
   */
  public function testACondominiumThatNoLongerLoadsGetsThePlaceholder() {
    myapi_test_node_seed();

    $row = myapi_claim_notification_row($this->claimNode());

    $this->assertSame(12, $row->condominium_id);
    $this->assertSame('Sin condominio', $row->condominium_name);
  }

  /**
   * An unknown status resolves to a NULL label — a first-class answer that
   * degrades the texts instead of printing a raw machine key.
   */
  public function testAnUnknownStatusResolvesToANullLabel() {
    $this->seedRelated();

    $row = myapi_claim_notification_row($this->claimNode(['field_status' => [LANGUAGE_NONE => [['value' => 'duplicated']]]]));

    $this->assertSame('duplicated', $row->status);
    $this->assertNull($row->status_label);
  }

  /**
   * The reception date travels as a TIMESTAMP so every text formats it through
   * the same call.
   */
  public function testTheReceptionDateBecomesATimestamp() {
    $this->seedRelated();

    $row = myapi_claim_notification_row($this->claimNode([
      'field_reception_date' => [LANGUAGE_NONE => [['value' => '2026-01-31 23:50:00']]],
    ]));

    $this->assertSame(mktime(23, 50, 0, 1, 31, 2026), $row->reception_date);
  }

  /**
   * An empty or unparseable reception date falls back to the node's creation
   * time — the closest true statement left — and never to 0 (01/01/1970).
   */
  public function testAnUnusableReceptionDateFallsBackToTheCreationTime() {
    $this->seedRelated();
    $created = mktime(16, 45, 0, 8, 4, 2026);

    foreach ([[], [LANGUAGE_NONE => [['value' => '']]], [LANGUAGE_NONE => [['value' => '   ']]]] as $field) {
      $row = myapi_claim_notification_row($this->claimNode(['field_reception_date' => $field]));

      $this->assertSame($created, $row->reception_date);
    }
  }

  /**
   * A node with no title and no created stamp still produces a row: the
   * subject is '' and the date is the request's own instant, so nothing
   * downstream sees NULL.
   */
  public function testADegradedNodeStillProducesAUsableRow() {
    $this->seedRelated();
    $node = (object) ['nid' => 140, 'type' => 'reclamo'];

    $row = myapi_claim_notification_row($node);

    $this->assertSame('', $row->subject);
    $this->assertSame('', $row->description);
    $this->assertSame(REQUEST_TIME, $row->created);
    $this->assertSame(REQUEST_TIME, $row->reception_date);
    $this->assertSame('Reclamo', $row->type_label, 'an unknown type degrades to the bundle noun');
  }

  /**
   * The files are counted into the row, which is what the back-office email
   * prints as '2 imágenes, 1 documento'.
   */
  public function testTheRowCountsImagesAndTheAttachment() {
    $this->seedRelated();
    $node = $this->claimNode();
    $node->field_images[LANGUAGE_NONE] = [['fid' => 7], ['fid' => 8]];
    $node->field_attachment[LANGUAGE_NONE] = [['fid' => 31]];

    $row = myapi_claim_notification_row($node);

    $this->assertSame(2, $row->image_count);
    $this->assertTrue($row->has_attachment);
  }

  /* -------------------------------------------------------------------------
   * myapi_claim_file_count().
   * ---------------------------------------------------------------------- */

  public function testFileCountIsZeroForEveryEmptyShape() {
    $node = (object) [];
    $this->assertSame(0, myapi_claim_file_count($node, 'field_images'));

    $node->field_images = [];
    $this->assertSame(0, myapi_claim_file_count($node, 'field_images'));

    $node->field_images = [LANGUAGE_NONE => []];
    $this->assertSame(0, myapi_claim_file_count($node, 'field_images'));

    $this->assertSame(0, myapi_claim_file_count(NULL, 'field_images'));
    $this->assertSame(0, myapi_claim_file_count('not a node', 'field_images'));
  }

  /**
   * File items carry 'fid' and not 'target_id', which is why this walk exists
   * instead of reusing the entityreference one.
   */
  public function testFileCountCountsEveryDeltaOfEveryLanguage() {
    $node = (object) ['field_images' => [
      LANGUAGE_NONE => [['fid' => 7], ['fid' => 8]],
      'es'          => [['fid' => 9]],
    ]];

    $this->assertSame(3, myapi_claim_file_count($node, 'field_images'));
  }

  /**
   * An item with no fid, an empty fid or a shape that is not an array does not
   * count as a file.
   */
  public function testFileCountIgnoresItemsWithoutAUsableFid() {
    $node = (object) ['field_images' => [LANGUAGE_NONE => [
      ['fid' => 7],
      ['fid' => 0],
      ['fid' => NULL],
      ['target_id' => 8],
      'not an item',
    ]]];

    $this->assertSame(1, myapi_claim_file_count($node, 'field_images'));
  }

  /* -------------------------------------------------------------------------
   * Dates and labels.
   * ---------------------------------------------------------------------- */

  /**
   * One format for every user-facing surface of the module.
   */
  public function testTheDateLabelIsTheModulesSingleFormat() {
    $this->assertSame('04/08/2026 16:45', myapi_claim_date_label(mktime(16, 45, 0, 8, 4, 2026)));
    $this->assertStringNotContainsString('2026-08-04', myapi_claim_date_label(mktime(16, 45, 0, 8, 4, 2026)));
  }

  /**
   * The same-day test is what decides whether a transaction body spends one of
   * its three lines on a date the reader is already looking at.
   */
  public function testDateIsTodayComparesCalendarDaysAndNotElapsedTime() {
    $event = mktime(23, 50, 0, 8, 5, 2026);

    $this->assertTrue(myapi_claim_date_is_today($event, mktime(0, 5, 0, 8, 5, 2026)), 'same day, 23h apart');
    $this->assertFalse(myapi_claim_date_is_today($event, mktime(0, 5, 0, 8, 6, 2026)), 'next day, 15 minutes later');
    $this->assertTrue(myapi_claim_date_is_today($event, $event));
    $this->assertFalse(myapi_claim_date_is_today($event, mktime(23, 50, 0, 8, 4, 2026)), 'a backdated event');
  }

  /**
   * The attachment label branches on the count, singular and plural, and
   * answers '' when there is nothing — which is what drops the line from the
   * email instead of printing an empty heading.
   */
  public function testTheAttachmentLabelBranchesOnCountAndAttachment() {
    $this->assertSame('', myapi_claim_attachment_label(0, FALSE));
    $this->assertSame('1 imagen', myapi_claim_attachment_label(1, FALSE));
    $this->assertSame('2 imágenes', myapi_claim_attachment_label(2, FALSE));
    $this->assertSame('1 documento', myapi_claim_attachment_label(0, TRUE));
    $this->assertSame('1 imagen, 1 documento', myapi_claim_attachment_label(1, TRUE));
    $this->assertSame('5 imágenes, 1 documento', myapi_claim_attachment_label(5, TRUE));
  }

  /**
   * A negative or non-numeric count degrades to "nothing" rather than to a
   * sentence about minus one image.
   */
  public function testAnImpossibleImageCountProducesNoImagePart() {
    $this->assertSame('', myapi_claim_attachment_label(-2, FALSE));
    $this->assertSame('1 documento', myapi_claim_attachment_label('abc', TRUE));
  }

  /**
   * Only the explicit 'public' value opens anything: NULL, an empty string and
   * an unknown value are all private.
   */
  public function testOnlyTheExplicitPublicValueLabelsAsPublic() {
    $this->assertSame('Público', myapi_claim_visibility_label('public'));
    $this->assertSame('Privado', myapi_claim_visibility_label('private'));
    $this->assertSame('Privado', myapi_claim_visibility_label(NULL));
    $this->assertSame('Privado', myapi_claim_visibility_label(''));
    $this->assertSame('Privado', myapi_claim_visibility_label('Public'));
  }

  /* -------------------------------------------------------------------------
   * The detectors.
   * ---------------------------------------------------------------------- */

  /**
   * The origin flag is a transient property and its DEFAULT is the
   * conservative one: with no flag there is no detail email to the back
   * office, so a migration or a drush script notifies the resident and nobody
   * else.
   */
  public function testTheApiOriginDefaultsToFalseAndReadsTheTransientFlag() {
    $this->assertFalse(myapi_claim_is_creation_from_api((object) ['nid' => 140]));
    $this->assertTrue(myapi_claim_is_creation_from_api((object) ['myapi_claim_from_api' => TRUE]));
    $this->assertFalse(myapi_claim_is_creation_from_api((object) ['myapi_claim_from_api' => FALSE]));
    $this->assertFalse(myapi_claim_is_creation_from_api((object) ['myapi_claim_from_api' => 0]));
  }

  /**
   * The publication transition needs all three conditions. An INSERT — no
   * ->original — is never a transition, whatever its visibility: the creation
   * event notifies the neighbours on its own, and firing both would send the
   * same building two pushes about one claim.
   */
  public function testAnInsertIsNeverAPublicationTransition() {
    $node = (object) ['field_visibility' => [LANGUAGE_NONE => [['value' => 'public']]]];

    $this->assertFalse(myapi_claim_is_publication_transition($node));
  }

  /**
   * private -> public is the transition.
   */
  public function testPrivateToPublicIsATransition() {
    $node = (object) ['field_visibility' => [LANGUAGE_NONE => [['value' => 'public']]]];
    $node->original = (object) ['field_visibility' => [LANGUAGE_NONE => [['value' => 'private']]]];

    $this->assertTrue(myapi_claim_is_publication_transition($node));
  }

  /**
   * A claim with no previous visibility at all — imported by hand, or saved
   * before the field existed — that becomes public IS a transition: the
   * neighbours could not see it before.
   */
  public function testAMissingPreviousVisibilityCountsAsNotPublic() {
    $node = (object) ['field_visibility' => [LANGUAGE_NONE => [['value' => 'public']]]];
    $node->original = (object) [];

    $this->assertTrue(myapi_claim_is_publication_transition($node));
  }

  /**
   * public -> public is not: an ordinary edit of an already public claim must
   * not re-announce it.
   */
  public function testPublicToPublicIsNotATransition() {
    $node = (object) ['field_visibility' => [LANGUAGE_NONE => [['value' => 'public']]]];
    $node->original = (object) ['field_visibility' => [LANGUAGE_NONE => [['value' => 'public']]]];

    $this->assertFalse(myapi_claim_is_publication_transition($node));
  }

  /**
   * And neither is the INVERSE transition, deliberately: you cannot un-notify
   * whoever already read the claim, and pointing at something that stopped
   * being visible is worse than silence.
   */
  public function testPublicToPrivateNotifiesNobody() {
    $node = (object) ['field_visibility' => [LANGUAGE_NONE => [['value' => 'private']]]];
    $node->original = (object) ['field_visibility' => [LANGUAGE_NONE => [['value' => 'public']]]];

    $this->assertFalse(myapi_claim_is_publication_transition($node));
  }

  /**
   * A transaction is notifiable by default — the expected behaviour for any
   * future path — and silenced only by the explicit opt-out flag, which is how
   * the automatic initial transaction avoids saying twice what the claim's own
   * creation already said.
   */
  public function testATransactionIsNotifiableUnlessItCarriesTheOptOut() {
    myapi_test_node_seed([140 => ['nid' => 140, 'type' => 'reclamo']]);

    $notifiable = (object) ['field_claim' => [LANGUAGE_NONE => [['target_id' => 140]]]];
    $silenced = clone $notifiable;
    $silenced->myapi_skip_claim_notification = TRUE;

    $this->assertTrue(myapi_claim_transaction_is_notifiable($notifiable));
    $this->assertFalse(myapi_claim_transaction_is_notifiable($silenced));
  }

  /**
   * A transaction whose field_claim resolves to nothing has no subject, no
   * condominium and no requester: nobody to notify and nothing to say.
   */
  public function testATransactionWithoutALoadableClaimIsNotNotifiable() {
    myapi_test_node_seed();
    $orphan = (object) ['field_claim' => [LANGUAGE_NONE => [['target_id' => 140]]]];
    $corrupt = (object) ['field_claim' => []];

    $this->assertFalse(myapi_claim_transaction_is_notifiable($orphan), 'the claim no longer exists');
    $this->assertFalse(myapi_claim_transaction_is_notifiable($corrupt), 'field_claim is empty');
  }

  /**
   * The status-change detector reads the transient previous value, and its
   * DEFAULT is TRUE: with no property there is nothing to compare against, and
   * claiming the status did not move would be inventing a fact.
   */
  public function testTheStatusChangeDetectorDefaultsToTrue() {
    $node = (object) ['field_status' => [LANGUAGE_NONE => [['value' => 'in_progress']]]];

    $this->assertTrue(myapi_claim_transaction_changed_status($node));
  }

  /**
   * property_exists() and not isset(): a claim whose previous status was NULL
   * — a legitimate absence, not a missing property — and whose transaction
   * carries NULL too has NOT changed status.
   */
  public function testANullPreviousStatusIsAValueAndNotAnAbsence() {
    $unchanged = (object) ['myapi_claim_previous_status' => NULL];
    $changed = (object) [
      'myapi_claim_previous_status' => NULL,
      'field_status' => [LANGUAGE_NONE => [['value' => 'received']]],
    ];

    $this->assertFalse(myapi_claim_transaction_changed_status($unchanged));
    $this->assertTrue(myapi_claim_transaction_changed_status($changed));
  }

  public function testTheStatusChangeDetectorComparesTheStoredValues() {
    $moved = (object) [
      'myapi_claim_previous_status' => 'received',
      'field_status' => [LANGUAGE_NONE => [['value' => 'in_progress']]],
    ];
    $repeated = (object) [
      'myapi_claim_previous_status' => 'in_progress',
      'field_status' => [LANGUAGE_NONE => [['value' => 'in_progress']]],
    ];

    $this->assertTrue(myapi_claim_transaction_changed_status($moved));
    $this->assertFalse(myapi_claim_transaction_changed_status($repeated));
  }

  /* -------------------------------------------------------------------------
   * The audience.
   * ---------------------------------------------------------------------- */

  /**
   * Seeds a condominium with two units and three members, all active.
   */
  private function seedCondominiumMembers() {
    myapi_test_db_seed([
      'field_data_field_condominio' => [
        ['entity_id' => '45', 'entity_type' => 'node', 'deleted' => '0', 'field_condominio_target_id' => '12'],
        ['entity_id' => '46', 'entity_type' => 'node', 'deleted' => '0', 'field_condominio_target_id' => '12'],
      ],
      'field_data_field_propietario' => [
        ['entity_id' => '45', 'entity_type' => 'node', 'deleted' => '0', 'field_propietario_target_id' => '3'],
        ['entity_id' => '46', 'entity_type' => 'node', 'deleted' => '0', 'field_propietario_target_id' => '7'],
      ],
      'field_data_field_ocupantes' => [
        ['entity_id' => '45', 'entity_type' => 'node', 'deleted' => '0', 'field_ocupantes_target_id' => '9'],
      ],
      'users' => [
        ['uid' => '3', 'status' => '1'],
        ['uid' => '7', 'status' => '1'],
        ['uid' => '9', 'status' => '1'],
      ],
    ]);
  }

  /**
   * A claim with no condominium reaches nobody, and costs no query.
   */
  public function testAClaimWithoutACondominiumHasNoAudience() {
    $this->assertSame([], myapi_claim_condominium_uids(NULL));
    $this->assertSame([], myapi_test_db_queries());
  }

  /**
   * The audience is the owners and occupants of the condominium, as integers.
   */
  public function testTheAudienceIsEveryOwnerAndOccupantOfTheCondominium() {
    $this->seedCondominiumMembers();

    $uids = myapi_claim_condominium_uids(12);
    sort($uids);

    $this->assertSame([3, 7, 9], $uids);
  }

  /**
   * The requester is excluded, which is what guarantees ONE notification per
   * person with the text that belongs to them: they get the requester's, the
   * neighbours get theirs.
   */
  public function testTheRequesterIsExcludedFromTheNeighbourFanOut() {
    $this->seedCondominiumMembers();

    $uids = myapi_claim_condominium_uids(12, 3);
    sort($uids);

    $this->assertSame([7, 9], $uids);
  }

  /**
   * Blocked accounts are filtered out — the one thing this wrapper adds over
   * the shared member resolver, which deliberately does not filter by status.
   */
  public function testBlockedAccountsAreNotNotified() {
    $this->seedCondominiumMembers();
    myapi_test_db_seed(array_merge($GLOBALS['myapi_test_db'], ['users' => [
      ['uid' => '3', 'status' => '1'],
      ['uid' => '7', 'status' => '0'],
      ['uid' => '9', 'status' => '1'],
    ]]));

    $uids = myapi_claim_condominium_uids(12);
    sort($uids);

    $this->assertSame([3, 9], $uids);
  }

  /**
   * A condominium whose only member is the requester answers an empty audience
   * without asking the users table: there is nobody left to filter.
   */
  public function testAnEmptyAudienceShortCircuitsBeforeTheUsersQuery() {
    myapi_test_db_seed([
      'field_data_field_condominio' => [
        ['entity_id' => '45', 'entity_type' => 'node', 'deleted' => '0', 'field_condominio_target_id' => '12'],
      ],
      'field_data_field_propietario' => [
        ['entity_id' => '45', 'entity_type' => 'node', 'deleted' => '0', 'field_propietario_target_id' => '3'],
      ],
    ]);

    $this->assertSame([], myapi_claim_condominium_uids(12, 3));
    $this->assertSame([], myapi_test_db_queries('users'));
  }

  /* -------------------------------------------------------------------------
   * Mail params.
   * ---------------------------------------------------------------------- */

  /**
   * A row with everything resolved, as the mail params read it.
   */
  private function mailRow(array $overrides = []) {
    return (object) ($overrides + [
      'nid'            => 140,
      'claim_type'     => 'claim',
      'subject'        => 'Fuga en el pasillo',
      'condominium_name' => 'Edificio El Sáuco',
      'status_label'   => 'Recibido',
      'reception_date' => mktime(16, 45, 0, 8, 4, 2026),
      'description'    => 'Hay agua desde el martes.',
    ]);
  }

  /**
   * Everything travels already resolved and ESCAPED — the queue drains on
   * cron, long after the save, so the email must describe what was true at the
   * instant of the trigger.
   */
  public function testTheCreationMailParamsAreResolvedAndEscaped() {
    $params = myapi_claim_mail_params($this->mailRow([
      'subject'     => 'Fuga <b>grave</b> & urgente',
      'description' => 'Piso 2 "B"',
    ]));

    $this->assertSame(140, $params['nid']);
    $this->assertSame('Reclamo', $params['type_label']);
    $this->assertSame('reclamo', $params['type_noun']);
    $this->assertSame('Fuga &lt;b&gt;grave&lt;/b&gt; &amp; urgente', $params['subject']);
    $this->assertSame('Piso 2 &quot;B&quot;', $params['description']);
    $this->assertSame('Edificio El Sáuco', $params['condominium']);
    $this->assertSame('Recibido', $params['status']);
    $this->assertSame('04/08/2026 16:45', $params['reception_date']);
  }

  /**
   * The subject line gets the 80-character cut; the data block gets it whole.
   */
  public function testOnlyTheShortSubjectIsCut() {
    $subject = str_repeat('a', 200);

    $params = myapi_claim_mail_params($this->mailRow(['subject' => $subject]));

    $this->assertSame($subject, $params['subject']);
    $this->assertLessThanOrEqual(81, mb_strlen($params['subject_short']));
    $this->assertStringEndsWith('…', $params['subject_short']);
  }

  /**
   * A status that cannot be resolved prints an em dash, never an empty cell.
   */
  public function testAnUnresolvedStatusPrintsADash() {
    $params = myapi_claim_mail_params($this->mailRow(['status_label' => NULL]));

    $this->assertSame('—', $params['status']);
  }

  /**
   * The transaction params carry the comment WHOLE — there is no
   * 200-character budget in an email — escaped, and the date of the
   * transaction rather than of the claim.
   */
  public function testTheTransactionMailParamsCarryTheWholeComment() {
    $comment = str_repeat('b', 300);

    $params = myapi_claim_transaction_mail_params(
      $this->mailRow(),
      'En proceso',
      $comment,
      mktime(9, 30, 0, 8, 5, 2026)
    );

    $this->assertSame($comment, $params['comment']);
    $this->assertSame('En proceso', $params['status']);
    $this->assertSame('05/08/2026 09:30', $params['date']);
  }

  /**
   * A transaction with no comment prints an empty string and not 'null'.
   */
  public function testATransactionWithoutACommentPrintsNothing() {
    $params = myapi_claim_transaction_mail_params($this->mailRow(), 'Cerrado', NULL, REQUEST_TIME);

    $this->assertSame('', $params['comment']);
  }

  /**
   * And the comment is escaped too: it is typed by an operator into a
   * back-office textarea and rendered inside an HTML email.
   */
  public function testTheTransactionCommentIsEscaped() {
    $params = myapi_claim_transaction_mail_params(
      $this->mailRow(),
      'En proceso',
      '<script>alert(1)</script>',
      REQUEST_TIME
    );

    $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $params['comment']);
  }

}
