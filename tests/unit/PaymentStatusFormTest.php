<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.payment_workflow.inc';

/**
 * Unit tests for myapi_payment_status_form_alter().
 *
 * On an existing 'pagos' node the field_estado_pago select drops
 * "Completado" while the stored status is "Pendiente de verificar", and drops
 * "Pendiente de verificar" while it is "Completado".
 */
class PaymentStatusFormTest extends TestCase {

  private const ALL = array(
    'Nuevo' => 'Nuevo',
    'Completado' => 'Completado',
    'Anulado' => 'Anulado',
    'Pendiente de verificar' => 'Pendiente de verificar',
  );

  private function form($stored, $nid = 10) {
    $node = new stdClass();
    $node->nid = $nid;
    if ($stored !== NULL) {
      $node->field_estado_pago = array('und' => array(array('value' => $stored)));
    }
    return array(
      '#node' => $node,
      'field_estado_pago' => array(
        '#language' => 'und',
        'und' => array('#type' => 'select', '#options' => self::ALL),
      ),
    );
  }

  private function options(array $form) {
    return array_keys($form['field_estado_pago']['und']['#options']);
  }

  public function testPendingPaymentCannotJumpToCompleted() {
    $form = $this->form('Pendiente de verificar');
    myapi_payment_status_form_alter($form);

    $this->assertSame(array('Nuevo', 'Anulado', 'Pendiente de verificar'), $this->options($form));
  }

  public function testCompletedPaymentCannotGoBackToPending() {
    $form = $this->form('Completado');
    myapi_payment_status_form_alter($form);

    $this->assertSame(array('Nuevo', 'Completado', 'Anulado'), $this->options($form));
  }

  public function testOtherStoredStatusesKeepEveryOption() {
    foreach (array('Nuevo', 'Anulado') as $stored) {
      $form = $this->form($stored);
      myapi_payment_status_form_alter($form);

      $this->assertSame(array_keys(self::ALL), $this->options($form), $stored);
    }
  }

  public function testNewPaymentFormKeepsEveryOption() {
    $form = $this->form(NULL, NULL);
    myapi_payment_status_form_alter($form);

    $this->assertSame(array_keys(self::ALL), $this->options($form));
  }

  public function testFormWithoutTheStatusFieldIsLeftAlone() {
    $form = $this->form('Completado');
    unset($form['field_estado_pago']);
    myapi_payment_status_form_alter($form);

    $this->assertArrayNotHasKey('field_estado_pago', $form);
  }

}
