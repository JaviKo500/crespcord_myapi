<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.claim_transaction_admin.inc';

/**
 * Unit tests for myapi_claim_transaction_file_item(), the helper that turns the
 * submitted value of a 'managed_file' element into a field item
 * (includes/myapi.claim_transaction_admin.inc).
 *
 * This is a regression suite before it is anything else. The creation form of a
 * 'claim_transaction' saved every transaction WITHOUT its image and without its
 * attachment: the submit handler read
 * $form_state['values']['field_images']['fid'], the shape of the native
 * file/image widget ('#extended' => TRUE, file_field_widget_form()), while a
 * bare '#type' => 'managed_file' has its value consolidated to the plain fid by
 * file_managed_file_validate(). Reading ['fid'] off an integer is NULL in PHP
 * 7.4 and empty() hid it: no warning, no error, no file. Editing the same
 * transaction afterwards did attach the file, because node/%nid/edit is the
 * native widget — which is exactly how the bug was reported.
 *
 * So the first test below is the bug itself, and every other case is a fence
 * around it. Both value shapes are accepted deliberately: it is the difference
 * of one flag on the element, and a future change flipping it must not silently
 * bring the bug back.
 *
 * Free of Drupal — no t(), no Field API, no database — which is what puts the
 * whole helper in tests/unit, the same split every other pure helper of this
 * include follows (SPEC 60's title, SPEC 61's initial comment).
 *
 * Deliberately NOT tested here, and said out loud rather than skipped in
 * silence: myapi_claim_transaction_create_form_submit(), which calls
 * node_object_prepare() and node_save() and reads the global $user. What it
 * does with this helper — assign the item at delta 0 of each field, skip the
 * field entirely when NULL — is three lines of glue over the cases below, and
 * it belongs to the manual acceptance matrix.
 */
class ClaimTransactionFileItemTest extends TestCase {

  /* -------------------------------------------------------------------------
   * The bare fid: what a '#type' => 'managed_file' element really submits.
   * ---------------------------------------------------------------------- */

  /**
   * The bug, turned into a test.
   *
   * A file uploaded on the creation form arrives as the fid and nothing else.
   * Before the fix this value produced no field item at all.
   */
  public function testBareFidProducesAnItem() {
    $this->assertSame(
      array('fid' => 12, 'display' => 1),
      myapi_claim_transaction_file_item(12)
    );
  }

  /**
   * The fid as it actually arrives from a POST: a string, not an int. The item
   * carries it typed, so what reaches the storage layer is an integer either
   * way.
   */
  public function testFidAsStringIsCastToInt() {
    $item = myapi_claim_transaction_file_item('12');

    $this->assertSame(12, $item['fid']);
    $this->assertSame(1, $item['display']);
  }

  /* -------------------------------------------------------------------------
   * The extended shape: the native widget's, accepted so the helper survives a
   * '#extended' => TRUE on those elements.
   * ---------------------------------------------------------------------- */

  public function testExtendedArrayProducesTheSameItem() {
    $this->assertSame(
      array('fid' => 7, 'display' => 1),
      myapi_claim_transaction_file_item(array('fid' => 7))
    );
  }

  /**
   * The extended array as file_managed_file_value() returns it, with the
   * element's other children alongside the fid: only the fid is read.
   */
  public function testExtendedArrayIgnoresItsOtherKeys() {
    $value = array(
      'fid' => '7',
      'upload' => '',
      'upload_button' => 'Subir al servidor',
    );

    $this->assertSame(array('fid' => 7, 'display' => 1), myapi_claim_transaction_file_item($value));
  }

  /* -------------------------------------------------------------------------
   * "No file was uploaded" — every shape of it. All of them must answer NULL,
   * which is what makes the submit handler leave the field off the node
   * instead of writing a broken item.
   * ---------------------------------------------------------------------- */

  /**
   * Zero is what file_managed_file_value() returns for an untouched element,
   * and the single most common case of this whole helper: the operator saved
   * the form without choosing any file.
   */
  public function testZeroFidIsNoFile() {
    $this->assertNull(myapi_claim_transaction_file_item(0));
    $this->assertNull(myapi_claim_transaction_file_item('0'));
  }

  public function testEmptyExtendedArrayIsNoFile() {
    $this->assertNull(myapi_claim_transaction_file_item(array('fid' => 0)));
    $this->assertNull(myapi_claim_transaction_file_item(array()));
  }

  /**
   * The element is not in $form_state['values'] at all — another module
   * removed it, or a field_access rule hid it. The submit handler passes NULL
   * for that case rather than reading a missing index.
   */
  public function testNullIsNoFile() {
    $this->assertNull(myapi_claim_transaction_file_item(NULL));
  }

  public function testEmptyStringIsNoFile() {
    $this->assertNull(myapi_claim_transaction_file_item(''));
  }

  /**
   * A non-numeric value resolves to "no file" and NOT to fid 0: an item whose
   * fid is 0 would be a file reference pointing at nothing.
   */
  public function testNonNumericValueIsNoFile() {
    $this->assertNull(myapi_claim_transaction_file_item('no soy un fid'));
    $this->assertNull(myapi_claim_transaction_file_item(array('fid' => 'no soy un fid')));
  }

  /**
   * A negative fid cannot exist — file_managed.fid is an unsigned serial — so
   * it is rejected rather than cast and stored.
   */
  public function testNegativeFidIsNoFile() {
    $this->assertNull(myapi_claim_transaction_file_item(-3));
    $this->assertNull(myapi_claim_transaction_file_item('-3'));
  }

  /* -------------------------------------------------------------------------
   * The item's own shape.
   * ---------------------------------------------------------------------- */

  /**
   * 'display' is there for field_attachment, a 'file' field whose column is
   * NOT NULL DEFAULT 1 (file_field_schema()): omitting it would send NULL into
   * a NOT NULL column, which fails on MySQL under the STRICT_TRANS_TABLES the
   * Drupal 7 driver sets. field_images is an 'image' field and has no such
   * column — field storage writes the columns the field declares, so the key
   * is ignored there and one item shape serves both fields.
   *
   * Nothing else is written: file_field_presave()/file_field_insert() make the
   * file permanent and register its usage, and image_field_presave() fills
   * width/height. That is why an item is just these two keys.
   */
  public function testTheItemCarriesNothingBeyondFidAndDisplay() {
    $this->assertSame(
      array('fid', 'display'),
      array_keys(myapi_claim_transaction_file_item(12))
    );
  }

}
