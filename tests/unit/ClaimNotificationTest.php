<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.building_admin.inc';
require_once __DIR__ . '/../../includes/myapi.claim_notification.inc';

/**
 * Unit tests for the push + inbox texts of the claim notifications (SPEC 68),
 * built by the pure half of includes/myapi.claim_notification.inc.
 *
 * These strings are what the resident and their neighbours read on a locked
 * screen, so they are pinned to the character: the noun taken from
 * field_claim_type, the status label of SPEC 62's catalogue, the 80/120 cuts
 * and, above all, the degraded shapes — a claim with no status label, a
 * transaction with no comment, a type nobody recognises. Each of those is a
 * real case in production data, and each one has to produce a text that still
 * reads correctly instead of a dangling separator or a blank line.
 *
 * Deliberately NOT tested here, and said out loud rather than skipped in
 * silence (same criterion as ClaimTransactionInitialCommentTest):
 *   - myapi_claim_notification_row(), which reads a node through the Field API
 *     and calls node_load() / user_load() to resolve the condominium and the
 *     requester. Its field reads go through myapi_building_admin_field_value()
 *     and myapi_building_admin_field_target_id(), already covered by
 *     BuildingAdminTest.
 *   - myapi_claim_condominium_uids() and myapi_claim_requester_uid(), which are
 *     db_select() and the Field API — exactly what tests/unit avoids, the same
 *     reason myapi_reservation_enqueue_admin_mails() has no unit test.
 *   - The three orchestrators and the three detectors, which are node hooks and
 *     queue writes.
 * All of them are in the spec's manual acceptance matrix instead.
 */
class ClaimNotificationTest extends TestCase {

  /**
   * 04/08/2026 16:45 — the reception instant every example of the spec uses.
   */
  private function receptionDate() {
    return mktime(16, 45, 0, 8, 4, 2026);
  }

  /**
   * 05/08/2026 09:30 — the transaction instant every example of the spec uses.
   */
  private function transactionDate() {
    return mktime(9, 30, 0, 8, 5, 2026);
  }

  /**
   * 05/08/2026 18:00 — a 'now' on the SAME day as transactionDate(), i.e. the
   * ordinary case: the operator writes the transaction and the resident is
   * notified minutes later. SPEC 71 drops the date line here.
   */
  private function sameDay() {
    return mktime(18, 0, 0, 8, 5, 2026);
  }

  /**
   * 07/08/2026 10:00 — a 'now' two days AFTER transactionDate(), i.e. the
   * backdated case: the operator registers today a visit made on the 5th. The
   * date line survives, because no surface can show that one.
   */
  private function laterDay() {
    return mktime(10, 0, 0, 8, 7, 2026);
  }

  /* -------------------------------------------------------------------------
   * Labels.
   * ---------------------------------------------------------------------- */

  public function testTypeLabelAndNounFollowTheStoredValue() {
    $this->assertSame('Reclamo', myapi_claim_type_label('claim'));
    $this->assertSame('Requerimiento', myapi_claim_type_label('requirement'));
    $this->assertSame('reclamo', myapi_claim_type_noun('claim'));
    $this->assertSame('requerimiento', myapi_claim_type_noun('requirement'));
  }

  /**
   * A missing or invented field_claim_type is not a third noun: only
   * 'requirement' branches, everything else reads as a claim — the bundle's own
   * name, exactly like SPEC 61.
   */
  public function testUnknownOrMissingTypeFallsBackToTheClaimNoun() {
    foreach (array(NULL, '', 'suggestion') as $value) {
      $this->assertSame('Reclamo', myapi_claim_type_label($value));
      $this->assertSame('reclamo', myapi_claim_type_noun($value));
    }
  }

  /**
   * The four keys SPEC 62 left standing, with the labels
   * _myapi_claims_install() writes into field_status' allowed_values.
   */
  public function testStatusLabelCoversTheFourStatuses() {
    $this->assertSame('Recibido', myapi_claim_status_label('received'));
    $this->assertSame('En proceso', myapi_claim_status_label('in_progress'));
    $this->assertSame('Resuelto', myapi_claim_status_label('resolved'));
    $this->assertSame('Cerrado', myapi_claim_status_label('closed'));
  }

  /**
   * NULL is a first-class answer, not an error: it is what the two transaction
   * texts branch on. 'duplicated' was the fifth status until SPEC 62 dropped
   * it, so it must degrade like any other unknown value.
   */
  public function testUnresolvableStatusLabelIsNull() {
    foreach (array(NULL, '', 'duplicated', 'in progress') as $value) {
      $this->assertNull(myapi_claim_status_label($value));
    }
  }

  /* -------------------------------------------------------------------------
   * The excerpt.
   * ---------------------------------------------------------------------- */

  /**
   * A text of exactly the limit — or shorter — comes back untouched and, above
   * all, WITHOUT an ellipsis.
   */
  public function testExcerptLeavesAFittingTextAlone() {
    $exact = str_repeat('a', 80);

    $this->assertSame($exact, myapi_claim_excerpt($exact, 80));
    $this->assertSame('Fuga de agua en el pasillo', myapi_claim_excerpt('Fuga de agua en el pasillo', 80));
  }

  /**
   * Over the limit the cut lands on a word boundary and ends in '…', which
   * counts against the budget: the result never exceeds the limit. It may fall
   * a few characters short of it, because the cut moves back to the boundary.
   */
  public function testExcerptCutsWithAnEllipsis() {
    $long = trim(str_repeat('palabra ', 20));
    $cut = myapi_claim_excerpt($long, 80);

    $this->assertLessThanOrEqual(80, mb_strlen($cut, 'UTF-8'));
    $this->assertStringEndsWith('…', $cut);
    $this->assertStringStartsWith('palabra palabra', $cut);
  }

  /**
   * The comment limit is the other one, and it is independent: a text between
   * the two survives whole at 120 and gets cut at 80.
   */
  public function testTheTwoLimitsAreIndependent() {
    $text = trim(str_repeat('palabra ', 12));

    $this->assertSame(95, mb_strlen($text, 'UTF-8'));
    $this->assertSame($text, myapi_claim_excerpt($text, MYAPI_CLAIM_COMMENT_EXCERPT));
    $this->assertStringEndsWith('…', myapi_claim_excerpt($text, MYAPI_CLAIM_SUBJECT_EXCERPT));
  }

  /**
   * field_comment is a textarea: its newlines and runs of spaces are collapsed
   * BEFORE the cut, so a comment never breaks the line layout of the body and
   * never spends part of its budget on whitespace.
   */
  public function testExcerptCollapsesWhitespace() {
    $this->assertSame('línea 1 línea 2', myapi_claim_excerpt("línea 1\n\n  línea 2", 80));
  }

  /**
   * An empty, missing or whitespace-only value yields '', which is the flag the
   * bodies use to drop the line altogether.
   */
  public function testExcerptOfNothingIsEmpty() {
    foreach (array(NULL, '', '   ', "\n\n") as $value) {
      $this->assertSame('', myapi_claim_excerpt($value, 80));
    }
  }

  /* -------------------------------------------------------------------------
   * Creation — to the requester.
   * ---------------------------------------------------------------------- */

  public function testCreationTitleCarriesTheEvent() {
    $this->assertSame('Reclamo recibido', myapi_claim_push_title('claim'));
    $this->assertSame('Requerimiento recibido', myapi_claim_push_title('requirement'));
    $this->assertSame('Reclamo recibido', myapi_claim_push_title(NULL));
  }

  public function testCreationBodyIsSubjectAndReceptionDate() {
    $this->assertSame(
      "Fuga de agua en el pasillo\nRecibido el 04/08/2026 16:45",
      myapi_claim_push_body('Fuga de agua en el pasillo', $this->receptionDate())
    );
  }

  /**
   * node.title is required on the bundle, so an empty subject means corrupt
   * data. The body drops its line instead of opening with a blank one.
   */
  public function testCreationBodyWithoutSubjectDropsTheLine() {
    $this->assertSame(
      'Recibido el 04/08/2026 16:45',
      myapi_claim_push_body('', $this->receptionDate())
    );
  }

  /* -------------------------------------------------------------------------
   * Publication — to the neighbours.
   * ---------------------------------------------------------------------- */

  public function testPublicationTitleSaysTheClaimIsNotYours() {
    $this->assertSame('Nuevo reclamo en tu condominio', myapi_claim_published_push_title('claim'));
    $this->assertSame('Nuevo requerimiento en tu condominio', myapi_claim_published_push_title('requirement'));
  }

  /**
   * The same builder serves the two ways a claim reaches a neighbour: the
   * caller passes the reception date when it is born public and the save time
   * when it turns public later. Both say the same thing — the instant this
   * neighbour could see it — so there is one text and one type.
   */
  public function testPublicationBodyIsSubjectAndTheVisibilityInstant() {
    $this->assertSame(
      "Fuga de agua en el pasillo\nPublicado el 04/08/2026 16:45",
      myapi_claim_published_push_body('Fuga de agua en el pasillo', $this->receptionDate())
    );

    $this->assertSame(
      "Fuga de agua en el pasillo\nPublicado el 05/08/2026 09:30",
      myapi_claim_published_push_body('Fuga de agua en el pasillo', $this->transactionDate())
    );
  }

  /* -------------------------------------------------------------------------
   * Transaction — to the requester.
   * ---------------------------------------------------------------------- */

  /**
   * The status goes in the title: it is the only thing read on a locked screen,
   * and without it the resident has to open the app to learn what happened.
   */
  public function testTransactionTitleCarriesTheStatus() {
    $this->assertSame(
      'Tu reclamo pasó a "En proceso"',
      myapi_claim_transaction_push_title('claim', myapi_claim_status_label('in_progress'), TRUE, TRUE)
    );

    $this->assertSame(
      'Tu requerimiento pasó a "Resuelto"',
      myapi_claim_transaction_push_title('requirement', myapi_claim_status_label('resolved'), TRUE, FALSE)
    );
  }

  /**
   * SPEC 71, the bug this spec exists for: a follow-up transaction on a claim
   * that is ALREADY 'in_progress' does not move it anywhere, so it must not be
   * titled "Tu reclamo pasó a …". Two consecutive follow-ups used to arrive as
   * two byte-identical headlines and the resident could not tell them apart —
   * nor tell either of them from a duplicate — from the lock screen.
   */
  public function testTransactionTitleDoesNotClaimATransitionThatDidNotHappen() {
    $label = myapi_claim_status_label('in_progress');

    $this->assertSame(
      'Nueva respuesta en tu reclamo',
      myapi_claim_transaction_push_title('claim', $label, FALSE, TRUE)
    );

    $this->assertSame(
      'Nueva respuesta en tu requerimiento',
      myapi_claim_transaction_push_title('requirement', $label, FALSE, TRUE)
    );
  }

  /**
   * No transition and nothing said either: there is no "respuesta" to announce,
   * so the title falls back to the generic event. The status is deliberately
   * NOT quoted — it is the same one the resident was already told about.
   */
  public function testTransactionTitleWithoutTransitionNorCommentIsGeneric() {
    $this->assertSame(
      'Novedad en tu reclamo',
      myapi_claim_transaction_push_title('claim', myapi_claim_status_label('in_progress'), FALSE, FALSE)
    );
  }

  /**
   * With no resolvable status there is nothing to quote, so the title states
   * the event alone rather than printing empty quotes.
   */
  public function testTransactionTitleWithoutStatusDegradesToTheGenericOne() {
    $this->assertSame(
      'Novedad en tu reclamo',
      myapi_claim_transaction_push_title('claim', myapi_claim_status_label('duplicated'))
    );

    $this->assertSame(
      'Novedad en tu requerimiento',
      myapi_claim_transaction_push_title('requirement', NULL)
    );
  }

  /**
   * The pre-SPEC-71 signature still produces the pre-SPEC-71 text: a caller
   * that cannot tell whether the status moved keeps the wording that shipped,
   * which is the conservative default documented on the function.
   */
  public function testTransactionTitleDefaultsToTheTransitionWording() {
    $this->assertSame(
      'Tu reclamo pasó a "En proceso"',
      myapi_claim_transaction_push_title('claim', myapi_claim_status_label('in_progress'))
    );
  }

  /**
   * SPEC 71: for an event of the day the date line is gone. The push banner is
   * already stamped "ahora" by the OS and the inbox renders `created`, so the
   * line was repeating what the reader had in front of them — and displacing
   * the comment, which is the only part that says what happened.
   */
  public function testTransactionBodyOfTodayIsSubjectAndComment() {
    $this->assertSame(
      "Fuga de agua en el pasillo\nSe asignó un técnico para revisar la tubería del tercer piso.",
      myapi_claim_transaction_push_body(
        'Fuga de agua en el pasillo',
        'Se asignó un técnico para revisar la tubería del tercer piso.',
        $this->transactionDate(),
        $this->sameDay()
      )
    );
  }

  /**
   * A backdated field_status_date — the operator registering today a visit made
   * on the 5th — keeps its line: that date is a fact neither the banner nor the
   * inbox can show.
   */
  public function testTransactionBodyOfAnotherDayKeepsTheDate() {
    $this->assertSame(
      "Fuga de agua en el pasillo\nSe asignó un técnico.\n05/08/2026 09:30",
      myapi_claim_transaction_push_body(
        'Fuga de agua en el pasillo',
        'Se asignó un técnico.',
        $this->transactionDate(),
        $this->laterDay()
      )
    );
  }

  /**
   * field_comment is optional on a transaction saved from the native node form.
   * Its line disappears and, for an event of the day, the body is the subject
   * alone — no blank line, no dangling date.
   */
  public function testTransactionBodyWithoutCommentIsTheSubjectAlone() {
    $this->assertSame(
      'Fuga de agua en el pasillo',
      myapi_claim_transaction_push_body('Fuga de agua en el pasillo', '   ', $this->transactionDate(), $this->sameDay())
    );
  }

  /**
   * Both lines gone at once — corrupt subject AND no comment — would leave an
   * empty push body, which is worse than a redundant one. The date comes back
   * for that case and only for it.
   */
  public function testTransactionBodyNeverComesBackEmpty() {
    $this->assertSame(
      '05/08/2026 09:30',
      myapi_claim_transaction_push_body('', NULL, $this->transactionDate(), $this->sameDay())
    );
  }

  /**
   * The comment is cut at 120 in the push — the email carries it whole — and
   * the subject at 80, so the 200-character push limit can only ever reach the
   * tail of the comment and never the first line.
   */
  public function testTransactionBodyCutsSubjectAndComment() {
    $body = myapi_claim_transaction_push_body(
      trim(str_repeat('asunto ', 20)),
      trim(str_repeat('comentario ', 30)),
      $this->transactionDate(),
      $this->laterDay()
    );

    $lines = explode("\n", $body);

    $this->assertCount(3, $lines);
    $this->assertLessThanOrEqual(80, mb_strlen($lines[0], 'UTF-8'));
    $this->assertLessThanOrEqual(120, mb_strlen($lines[1], 'UTF-8'));
    $this->assertStringEndsWith('…', $lines[0]);
    $this->assertStringEndsWith('…', $lines[1]);
    $this->assertSame('05/08/2026 09:30', $lines[2]);
  }

  /* -------------------------------------------------------------------------
   * Transaction — to the neighbours.
   * ---------------------------------------------------------------------- */

  public function testNeighbourTransactionTitleSaysTheClaimIsNotYours() {
    $this->assertSame(
      'Novedad en un reclamo de tu condominio',
      myapi_claim_transaction_neighbour_push_title('claim')
    );

    $this->assertSame(
      'Novedad en un requerimiento de tu condominio',
      myapi_claim_transaction_neighbour_push_title('requirement')
    );
  }

  /**
   * SPEC 71, the neighbour's half of the same fix: a follow-up that moves
   * nothing announces itself as the comment it is. Their title never carried
   * the status, so this is the only word that changes.
   */
  public function testNeighbourTransactionTitleSaysWhenItIsOnlyAComment() {
    $this->assertSame(
      'Nueva respuesta en un reclamo de tu condominio',
      myapi_claim_transaction_neighbour_push_title('claim', FALSE, TRUE)
    );

    $this->assertSame(
      'Novedad en un reclamo de tu condominio',
      myapi_claim_transaction_neighbour_push_title('claim', FALSE, FALSE)
    );
  }

  /**
   * The neighbour's status travels in the BODY, because their title has to
   * spend itself on saying the claim is not theirs. For an event of the day the
   * line is the status alone (SPEC 71).
   */
  public function testNeighbourTransactionBodyCarriesTheStatusWithoutTheDate() {
    $this->assertSame(
      "Fuga de agua en el pasillo\nEstado: En proceso\nSe asignó un técnico para revisar la tubería del tercer piso.",
      myapi_claim_transaction_neighbour_push_body(
        'Fuga de agua en el pasillo',
        myapi_claim_status_label('in_progress'),
        'Se asignó un técnico para revisar la tubería del tercer piso.',
        $this->transactionDate(),
        $this->sameDay()
      )
    );
  }

  /**
   * A backdated event keeps status and date together on the one line, which is
   * the shape SPEC 68 defined and SPEC 71 narrowed to this case.
   */
  public function testNeighbourTransactionBodyOfAnotherDayJoinsStatusAndDate() {
    $this->assertSame(
      "Fuga de agua en el pasillo\nEstado: En proceso · 05/08/2026 09:30\nSe asignó un técnico.",
      myapi_claim_transaction_neighbour_push_body(
        'Fuga de agua en el pasillo',
        myapi_claim_status_label('in_progress'),
        'Se asignó un técnico.',
        $this->transactionDate(),
        $this->laterDay()
      )
    );
  }

  /**
   * With no resolvable status the 'Estado: … · ' prefix disappears and the line
   * is left as the bare date, when there is a date to print at all: no line may
   * start with 'Estado:' without a label.
   */
  public function testNeighbourTransactionBodyWithoutStatusKeepsABackdatedDate() {
    $body = myapi_claim_transaction_neighbour_push_body(
      'Fuga de agua en el pasillo',
      NULL,
      'Se asignó un técnico.',
      $this->transactionDate(),
      $this->laterDay()
    );

    $this->assertSame(
      "Fuga de agua en el pasillo\n05/08/2026 09:30\nSe asignó un técnico.",
      $body
    );
    $this->assertStringNotContainsString('Estado:', $body);
  }

  /**
   * No status AND an event of the day leaves nothing for the middle line to
   * say, so the line goes entirely: an empty 'Estado:' or a redundant date read
   * worse than two clean lines.
   */
  public function testNeighbourTransactionBodyDropsAnEmptyMiddleLine() {
    $this->assertSame(
      "Fuga de agua en el pasillo\nSe asignó un técnico.",
      myapi_claim_transaction_neighbour_push_body(
        'Fuga de agua en el pasillo',
        NULL,
        'Se asignó un técnico.',
        $this->transactionDate(),
        $this->sameDay()
      )
    );
  }

  /**
   * Every optional line gone at once still produces a body, for the same reason
   * the requester's does: an empty push is worse than a redundant date.
   */
  public function testNeighbourTransactionBodyNeverComesBackEmpty() {
    $this->assertSame(
      '05/08/2026 09:30',
      myapi_claim_transaction_neighbour_push_body('', NULL, NULL, $this->transactionDate(), $this->sameDay())
    );
  }

  /* -------------------------------------------------------------------------
   * The status-change detector (SPEC 71).
   * ---------------------------------------------------------------------- */

  /**
   * Builds a 'claim_transaction' node carrying a status and, optionally, the
   * transient property myapi_claim_transaction_sync_claim_status() stashes.
   */
  private function transactionNode($status, $previous = NULL, $stash = TRUE) {
    $node = new stdClass();
    // The stored shape, langcode => delta => item, with the literal 'und' the
    // rest of the suite uses: LANGUAGE_NONE is a Drupal constant and tests/unit
    // runs outside Drupal.
    $node->field_status = array('und' => array(0 => array('value' => $status)));
    if ($stash) {
      $node->myapi_claim_previous_status = $previous;
    }

    return $node;
  }

  public function testChangedStatusComparesAgainstTheStash() {
    $this->assertTrue(myapi_claim_transaction_changed_status(
      $this->transactionNode('in_progress', 'received')
    ));

    $this->assertFalse(myapi_claim_transaction_changed_status(
      $this->transactionNode('in_progress', 'in_progress')
    ));
  }

  /**
   * A claim with no field_status stashes NULL, which is a legitimate previous
   * value and not an absence — hence property_exists() and not isset(). The
   * first transaction of such a claim DID move it somewhere.
   */
  public function testChangedStatusTreatsAStashedNullAsAValue() {
    $this->assertTrue(myapi_claim_transaction_changed_status(
      $this->transactionNode('received', NULL)
    ));

    $this->assertFalse(myapi_claim_transaction_changed_status(
      $this->transactionNode(NULL, NULL)
    ));
  }

  /**
   * No stash at all — a path that saved a transaction without going through the
   * sync — answers TRUE, the conservative default: claiming the status did not
   * move would be inventing a fact, while "pasó a X" is at worst the wording
   * that shipped before this spec.
   */
  public function testChangedStatusWithoutTheStashDefaultsToTrue() {
    $this->assertTrue(myapi_claim_transaction_changed_status(
      $this->transactionNode('in_progress', NULL, FALSE)
    ));
  }

  /* -------------------------------------------------------------------------
   * Cross-cutting.
   * ---------------------------------------------------------------------- */

  /**
   * Every date of every text uses 'd/m/Y H:i'. 'Y-m-d' is a machine format and
   * must not surface in anything the resident reads.
   */
  public function testNoTextEverPrintsAMachineDate() {
    $texts = array(
      myapi_claim_push_body('Fuga', $this->receptionDate()),
      myapi_claim_published_push_body('Fuga', $this->receptionDate()),
      // Backdated, so the two transaction bodies still carry their date line
      // and there is something to assert on.
      myapi_claim_transaction_push_body('Fuga', 'Comentario', $this->transactionDate(), $this->laterDay()),
      myapi_claim_transaction_neighbour_push_body('Fuga', 'En proceso', 'Comentario', $this->transactionDate(), $this->laterDay()),
    );

    foreach ($texts as $text) {
      $this->assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2}/', $text);
      $this->assertMatchesRegularExpression('#\d{2}/\d{2}/\d{4} \d{2}:\d{2}#', $text);
    }
  }

  /**
   * SPEC 71. The same person is the requester of one claim and the neighbour of
   * another, so both bodies land in the same notification tray — as the report
   * that opened this spec showed. The comment must sit in the SAME place in
   * both: last. Before this spec the neighbour's body put it third and the
   * requester's second, which read as two unrelated formats.
   */
  public function testBothTransactionBodiesEndWithTheComment() {
    $comment = 'Se asignó un técnico.';

    $requester = myapi_claim_transaction_push_body('Fuga', $comment, $this->transactionDate(), $this->sameDay());
    $neighbour = myapi_claim_transaction_neighbour_push_body('Fuga', 'En proceso', $comment, $this->transactionDate(), $this->sameDay());

    foreach (array($requester, $neighbour) as $body) {
      $lines = explode("\n", $body);

      $this->assertSame('Fuga', $lines[0]);
      $this->assertSame($comment, end($lines));
    }
  }

  /**
   * With the subject already cut to 80, the longest body this spec can produce
   * stays under the 200-character push limit except for the tail of the
   * comment — so myapi_onesignal_truncate_body() can never reach the first
   * line. Asserted on the worst case: the neighbour's three-line body with
   * both fields at their maximum.
   */
  public function testTheFirstLineIsAlwaysSafeFromThePushLimit() {
    $body = myapi_claim_transaction_neighbour_push_body(
      trim(str_repeat('asunto ', 20)),
      'En proceso',
      trim(str_repeat('comentario ', 30)),
      $this->transactionDate(),
      // The longest shape this builder can produce is the backdated one, where
      // the middle line carries both the status and the date.
      $this->laterDay()
    );

    $lines = explode("\n", $body);
    $head = $lines[0] . "\n" . $lines[1];

    $this->assertLessThan(200, mb_strlen($head, 'UTF-8'));
  }

}
