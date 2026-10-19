<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.payment_workflow.inc';

/**
 * Unit tests for myapi_payment_bot_fields_form_alter() (SPEC 130).
 *
 * The three bot-only fields of the 'pagos' node form are shown only while
 * field_canal is 'bot'. The selector must match the name Drupal gives the
 * options_select widget, field_canal[<langcode>], or #states never fires and
 * the fields stay hidden for good.
 */
class PaymentBotFieldsFormTest extends TestCase {

  private function form() {
    $form = array('field_canal' => array('#language' => 'und'));
    foreach (array('field_banco_emisor', 'field_banco_destino', 'field_comprobante_ocr') as $name) {
      $form[$name] = array('#type' => 'container');
    }
    return $form;
  }

  public function testEachBotFieldIsVisibleOnlyForTheBotChannel() {
    $form = $this->form();
    myapi_payment_bot_fields_form_alter($form);

    $expected = array(':input[name="field_canal[und]"]' => array('value' => 'bot'));
    foreach (array('field_banco_emisor', 'field_banco_destino', 'field_comprobante_ocr') as $name) {
      $this->assertSame($expected, $form[$name]['#states']['visible'], $name);
    }
  }

  public function testOtherFieldsAreLeftAlone() {
    $form = $this->form() + array('field_monto' => array('#type' => 'container'));
    myapi_payment_bot_fields_form_alter($form);

    $this->assertArrayNotHasKey('#states', $form['field_monto']);
    $this->assertArrayNotHasKey('#states', $form['field_canal']);
  }

  public function testWithoutTheChannelFieldNothingIsHidden() {
    $form = $this->form();
    unset($form['field_canal']);
    myapi_payment_bot_fields_form_alter($form);

    $this->assertArrayNotHasKey('#states', $form['field_banco_emisor']);
  }

  public function testAMissingBotFieldIsNotCreated() {
    $form = $this->form();
    unset($form['field_comprobante_ocr']);
    myapi_payment_bot_fields_form_alter($form);

    $this->assertArrayNotHasKey('field_comprobante_ocr', $form);
  }

}
