<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.chat.inc';

/**
 * Unit tests for the decision of the chat mirror (SPEC 117) —
 * myapi_chat_field_values(), and nothing else.
 *
 * THE MIRROR IS FOUR FUNCTIONS AND ONLY THIS ONE IS TESTABLE HERE, which is not
 * a gap but the shape the spec asked for: state() is a query, write() is six
 * db_merge() and two cache invalidations, sync() is the try/catch around them,
 * and none of the three has a decision inside. Everything that could be WRONG
 * about the mirror — which column is written, when, and which one is allowed to
 * move — was pushed into the one pure function so that it could be asserted
 * here, row by row, with no site. tests/unit/bootstrap.php: "Nothing here
 * touches the database".
 *
 * THE SIX ROWS BELOW ARE THE SPEC'S TABLE, copied as tests and not paraphrased:
 * three columns × (empty / already written) × ($is_message yes / no). The two
 * rows that answer [] are the ones worth the most: they are what proves that an
 * app launching for the second time runs not one write query.
 *
 * WHAT THIS SUITE DOES NOT PROVE, and both are manual acceptance criteria of
 * the spec against a booted site: that the two tables of each field really end
 * up with one row each, and that node_load() sees the value immediately — that
 * is, that the cache invalidation of myapi_chat_field_write() works. Neither is
 * reachable without a database.
 */
class ChatFieldMirrorTest extends TestCase {

  const OFFER_NID = 901;
  const VID = 1204;
  const NOW = 1756742400;
  const EARLIER = 1756000000;

  /**
   * A thread with nothing written yet, as myapi_chat_field_state() returns it.
   */
  private function emptyRow() {
    return [
      'nid' => self::OFFER_NID,
      'vid' => self::VID,
      'path' => NULL,
      'opened_at' => NULL,
      'last_message_at' => NULL,
    ];
  }

  /**
   * A thread whose path is written and which nobody has written a message to.
   */
  private function pathOnlyRow() {
    return [
      'nid' => self::OFFER_NID,
      'vid' => self::VID,
      'path' => 'service_offers/' . self::OFFER_NID,
      'opened_at' => NULL,
      'last_message_at' => NULL,
    ];
  }

  /**
   * A thread that has been talked on before. The DB hands back strings.
   */
  private function fullRow() {
    return [
      'nid' => self::OFFER_NID,
      'vid' => self::VID,
      'path' => 'service_offers/' . self::OFFER_NID,
      'opened_at' => (string) self::EARLIER,
      'last_message_at' => (string) self::EARLIER,
    ];
  }

  // ---------------------------------------------------------------------------
  // The six rows of the spec's decision table.
  // ---------------------------------------------------------------------------

  /**
   * Row 1. A credential on a virgin thread writes THE PATH AND NOTHING ELSE.
   */
  public function testCredentialOnAVirginThreadWritesOnlyThePath() {
    $values = myapi_chat_field_values($this->emptyRow(), self::NOW, FALSE);

    $this->assertSame(['field_firebase_path' => 'service_offers/901'], $values);
  }

  /**
   * Row 2. A notice on a virgin thread writes THE THREE COLUMNS AT ONCE.
   *
   * This is the case of a thread whose owners never launched the app after the
   * deploy and went straight to writing: the path is not somebody else's
   * responsibility, it is written right here.
   */
  public function testNoticeOnAVirginThreadWritesTheThreeColumns() {
    $values = myapi_chat_field_values($this->emptyRow(), self::NOW, TRUE);

    $this->assertSame([
      'field_firebase_path' => 'service_offers/901',
      'field_chat_opened_at' => self::NOW,
      'field_last_message_at' => self::NOW,
    ], $values);
  }

  /**
   * Row 3. THE COMMON CASE IN STEADY STATE: the app launches again and the
   * mirror runs NOT ONE QUERY.
   */
  public function testSecondCredentialWritesNothingAtAll() {
    $values = myapi_chat_field_values($this->pathOnlyRow(), self::NOW, FALSE);

    $this->assertSame([], $values);
  }

  /**
   * Row 4. The first message of a thread whose path was already written: the
   * two dates are born together and the path is left alone.
   */
  public function testFirstNoticeWritesBothDatesAndLeavesThePath() {
    $values = myapi_chat_field_values($this->pathOnlyRow(), self::NOW, TRUE);

    $this->assertSame([
      'field_chat_opened_at' => self::NOW,
      'field_last_message_at' => self::NOW,
    ], $values);
  }

  /**
   * Row 5. A credential on a thread that has everything writes nothing.
   */
  public function testCredentialOnAFullThreadWritesNothing() {
    $values = myapi_chat_field_values($this->fullRow(), self::NOW, FALSE);

    $this->assertSame([], $values);
  }

  /**
   * Row 6. A later notice moves field_last_message_at AND ONLY THAT ONE.
   */
  public function testLaterNoticeMovesOnlyTheLastMessage() {
    $values = myapi_chat_field_values($this->fullRow(), self::NOW, TRUE);

    $this->assertSame(['field_last_message_at' => self::NOW], $values);
  }

  // ---------------------------------------------------------------------------
  // The three rules, asserted as rules and not as rows.
  // ---------------------------------------------------------------------------

  /**
   * THE NEGATIVE THE WHOLE FILE EXISTS FOR: a credential can NEVER write a
   * timestamp, whatever the state of the row. Six of the eight combinations
   * above already say it; this one says it as one assertion, so the day
   * somebody moves a date out of the $is_message branch, it goes red here with
   * the reason written on the label.
   */
  public function testACredentialCanNeverWriteATimestamp() {
    foreach ([$this->emptyRow(), $this->pathOnlyRow(), $this->fullRow()] as $row) {
      $values = myapi_chat_field_values($row, self::NOW, FALSE);

      $this->assertArrayNotHasKey('field_chat_opened_at', $values);
      $this->assertArrayNotHasKey('field_last_message_at', $values);
    }
  }

  /**
   * field_chat_opened_at IS WRITTEN ONCE IN THE LIFE OF THE THREAD. Not once
   * per session, not once per day: once.
   */
  public function testOpenedAtIsNeverOverwritten() {
    $row = $this->fullRow();
    $row['last_message_at'] = NULL;

    $values = myapi_chat_field_values($row, self::NOW, TRUE);

    $this->assertArrayNotHasKey('field_chat_opened_at', $values);
    $this->assertSame(self::EARLIER, (int) $row['opened_at']);
  }

  /**
   * THE PATH IS NEVER REWRITTEN, not even when what is stored differs from what
   * the convention derives. An operator who edits the column by hand dirties
   * his own screen and nothing else (decision 1); the mirror does not fight him
   * on every launch, because nobody reads that column.
   */
  public function testAHandEditedPathIsLeftAlone() {
    $row = $this->pathOnlyRow();
    $row['path'] = 'whatever the operator typed';

    $this->assertSame([], myapi_chat_field_values($row, self::NOW, FALSE));
  }

  // ---------------------------------------------------------------------------
  // The value that is written.
  // ---------------------------------------------------------------------------

  /**
   * THE MIRROR AND THE SIGNATURE SAY THE SAME BYTES. The column is a mirror of
   * myapi_chat_thread_id(), so it is derived from it and never spelled out
   * again — a second copy of 'service_offers/' is the one way this could ever
   * end up lying.
   */
  public function testThePathIsByteForByteTheDerivedThreadId() {
    $values = myapi_chat_field_values($this->emptyRow(), self::NOW, FALSE);

    $this->assertSame(
      myapi_chat_thread_id(self::OFFER_NID),
      $values['field_firebase_path']
    );
  }

  /**
   * The two dates of a first notice are THE SAME INTEGER, not two readings of
   * the clock — REQUEST_TIME travels once and is used twice.
   */
  public function testBothDatesOfAFirstNoticeAreTheSameInteger() {
    $values = myapi_chat_field_values($this->emptyRow(), self::NOW, TRUE);

    $this->assertSame($values['field_chat_opened_at'], $values['field_last_message_at']);
  }

  /**
   * The timestamp reaches the column as an INTEGER. field_chat_opened_at and
   * field_last_message_at are datestamps, and a string in a datestamp is the
   * kind of thing MySQL swallows and a formatter chokes on.
   */
  public function testTimestampsAreCastToIntegers() {
    $values = myapi_chat_field_values($this->emptyRow(), (string) self::NOW, TRUE);

    $this->assertSame(self::NOW, $values['field_chat_opened_at']);
    $this->assertSame(self::NOW, $values['field_last_message_at']);
  }

  /**
   * The nid is normalised the same way myapi_chat_thread_id() normalises it, so
   * a numeric string out of the database cannot produce 'service_offers/'.
   */
  public function testANumericStringNidStillProducesTheRightPath() {
    $row = $this->emptyRow();
    $row['nid'] = (string) self::OFFER_NID;

    $values = myapi_chat_field_values($row, self::NOW, FALSE);

    $this->assertSame('service_offers/901', $values['field_firebase_path']);
  }

  // ---------------------------------------------------------------------------
  // What counts as missing.
  // ---------------------------------------------------------------------------

  /**
   * AN EMPTY STRING IS MISSING. A column blanked from the node form is a hole,
   * and the mirror fills it back in on the next call.
   */
  public function testAnEmptyStringCountsAsMissing() {
    $row = $this->emptyRow();
    $row['path'] = '';
    $row['opened_at'] = '';

    $values = myapi_chat_field_values($row, self::NOW, TRUE);

    $this->assertArrayHasKey('field_firebase_path', $values);
    $this->assertArrayHasKey('field_chat_opened_at', $values);
  }

  /**
   * A ZERO IS NOT MISSING, and this is the reason the check is not empty(): a
   * datestamp of 0 is 1970, an absurd date but a WRITTEN one, and rewriting it
   * on every notice would turn "once in the life of the thread" into "always".
   */
  public function testAZeroTimestampIsNotTreatedAsMissing() {
    $row = $this->pathOnlyRow();
    $row['opened_at'] = '0';

    $values = myapi_chat_field_values($row, self::NOW, TRUE);

    $this->assertSame(['field_last_message_at' => self::NOW], $values);
  }
}
