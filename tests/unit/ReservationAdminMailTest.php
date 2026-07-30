<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.mail.inc';

/**
 * Unit tests for the detail email sent to the 'backend' role, now serving two
 * keys (SPEC 50): 'reservation_created_admin' (SPEC 48) and
 * 'reservation_cancelled_admin'.
 *
 * myapi_mail_format_reservation_admin() and myapi_mail_reservation_admin_html()
 * are where the key stops being a string and becomes a subject, a heading and
 * two extra lines, so they are the seam worth testing. The enqueue side,
 * myapi_reservation_enqueue_admin_mails(), runs db_select() twice and
 * user_load_multiple() once, so it stays out of tests/unit for the same
 * documented reason as myapi_reservation_calendar_rows() (SPEC 47); its
 * verification is `php -l` plus the manual matrix of the spec's step 10.
 *
 * Half of these tests are non-regression ones: generalising a function that
 * spec 48 left Implemented is only safe if the creation variant still renders
 * its ten lines, its old subject and neither of the two new lines.
 */
class ReservationAdminMailTest extends TestCase {

  /**
   * The params both keys share, already escaped, as
   * myapi_reservation_enqueue_admin_mails() builds them.
   */
  private function params($extra = []) {
    return $extra + [
      'nid'         => 501513,
      'user'        => 'Ana Pérez (aperez)',
      'email'       => 'ana@example.com',
      'unit'        => 'Casa 12',
      'area'        => 'Gimnasio',
      'condominium' => 'Los Robles',
      'date'        => '27/07/2026',
      'schedule'    => '09:00 – 10:00',
      'duration'    => '1h 0min',
      'status'      => 'Cancelada',
      'created'     => '20/07/2026 11:30',
      'node_url'    => 'https://crespcord.lamotora.com/node/501513',
    ];
  }

  /**
   * Params of a cancellation email, with the two extra values.
   */
  private function cancelledParams($cancel_reason = 'El evento se pospuso') {
    return $this->params([
      'cancelled_by'  => 'Usuario',
      'cancel_reason' => $cancel_reason,
    ]);
  }

  /**
   * Extracts the label => value pairs of the data table, in display order.
   *
   * Anchored on the label cell's own colour so the layout's outer table cells
   * (header, footer, button) never leak into the result.
   */
  private function lines($html) {
    preg_match_all('~<tr><td style="[^"]*color:#907050[^"]*">(.*?)</td><td[^>]*>(.*?)</td></tr>~s', $html, $matches);

    return array_combine($matches[1], $matches[2]);
  }

  /* -- Subject ------------------------------------------------------------ */

  public function testCancelledSubjectUsesTheCancellationWording() {
    $message = ['body' => [], 'headers' => []];

    myapi_mail_format_reservation_admin($message, $this->cancelledParams(), 'reservation_cancelled_admin');

    $this->assertSame('Reserva cancelada #501513 — Gimnasio, 27/07/2026', $message['subject']);
  }

  public function testCreatedSubjectIsUnchanged() {
    $message = ['body' => [], 'headers' => []];

    myapi_mail_format_reservation_admin($message, $this->params(), 'reservation_created_admin');

    $this->assertSame('Nueva reserva #501513 — Gimnasio, 27/07/2026', $message['subject']);
  }

  /**
   * A subject is plain text, so the escaped params are decoded back: an area
   * named 'Cancha & golf' must not read 'Cancha &amp; golf' in the inbox list.
   */
  public function testSubjectDecodesEscapedParams() {
    $message = ['body' => [], 'headers' => []];
    $params = $this->cancelledParams();
    $params['area'] = 'Cancha &amp; golf';

    myapi_mail_format_reservation_admin($message, $params, 'reservation_cancelled_admin');

    $this->assertSame('Reserva cancelada #501513 — Cancha & golf, 27/07/2026', $message['subject']);
  }

  /**
   * Both keys must keep the HTML content type, or MyapiHtmlMailSystem delivers
   * markup the client shows as plain text.
   */
  public function testBothKeysDeclareHtml() {
    foreach (['reservation_created_admin', 'reservation_cancelled_admin'] as $key) {
      $message = ['body' => [], 'headers' => []];

      myapi_mail_format_reservation_admin($message, $this->cancelledParams(), $key);

      $this->assertSame('text/html; charset=UTF-8', $message['headers']['Content-Type'], $key);
      $this->assertCount(1, $message['body'], $key);
    }
  }

  /* -- Body: the cancellation variant ------------------------------------- */

  public function testCancelledHtmlAddsCancelledByAndMotivo() {
    $lines = $this->lines(myapi_mail_reservation_admin_html($this->cancelledParams(), TRUE));

    $this->assertSame([
      'Usuario', 'Email', 'Vivienda', 'Área', 'Condominio', 'Fecha', 'Horario',
      'Duración', 'Estado', 'Creada', 'Cancelada por', 'Motivo',
    ], array_keys($lines));

    $this->assertSame('Usuario', $lines['Cancelada por']);
    $this->assertSame('El evento se pospuso', $lines['Motivo']);
    $this->assertSame('Cancelada', $lines['Estado']);
  }

  /**
   * No reason, no line — same criterion as the calendar detail panel, where a
   * line that does not apply is not drawn at all. The other eleven stay put.
   */
  public function testCancelledHtmlOmitsMotivoWhenEmpty() {
    $lines = $this->lines(myapi_mail_reservation_admin_html($this->cancelledParams(''), TRUE));

    $this->assertSame([
      'Usuario', 'Email', 'Vivienda', 'Área', 'Condominio', 'Fecha', 'Horario',
      'Duración', 'Estado', 'Creada', 'Cancelada por',
    ], array_keys($lines));
  }

  /**
   * 'Cancelada por' reads 'Usuario' and never the raw stored 'user': this key
   * only fires from the resident's endpoint. A params array missing the value
   * altogether still prints the label rather than an empty cell.
   */
  public function testCancelledByFallsBackToUsuario() {
    $params = $this->cancelledParams();
    unset($params['cancelled_by']);

    $lines = $this->lines(myapi_mail_reservation_admin_html($params, TRUE));

    $this->assertSame('Usuario', $lines['Cancelada por']);
  }

  public function testCancelledHtmlKeepsTheNodeButton() {
    $html = myapi_mail_reservation_admin_html($this->cancelledParams(), TRUE);

    $this->assertStringContainsString('href="https://crespcord.lamotora.com/node/501513"', $html);
    $this->assertStringContainsString('>Ver reserva</a>', $html);
    $this->assertStringContainsString('<h1', $html);
    $this->assertStringContainsString('Reserva cancelada', $html);
  }

  /* -- Body: no regression on the creation variant ------------------------ */

  public function testCreatedHtmlHasNeitherExtraLine() {
    $lines = $this->lines(myapi_mail_reservation_admin_html($this->params()));

    $this->assertSame([
      'Usuario', 'Email', 'Vivienda', 'Área', 'Condominio', 'Fecha', 'Horario',
      'Duración', 'Estado', 'Creada',
    ], array_keys($lines));
  }

  /**
   * The creation variant ignores the two extra params even when they somehow
   * reach it, so only the key decides what the email says.
   */
  public function testCreatedHtmlIgnoresCancellationParams() {
    $html = myapi_mail_reservation_admin_html($this->cancelledParams(), FALSE);

    $this->assertStringNotContainsString('>Cancelada por</td>', $html);
    $this->assertStringNotContainsString('>Motivo</td>', $html);
    $this->assertStringContainsString('Nueva reserva', $html);
  }

  /**
   * Default argument: every pre-SPEC-50 call site passed one argument only and
   * must keep producing the creation email.
   */
  public function testDefaultVariantIsTheCreationOne() {
    $this->assertSame(
      myapi_mail_reservation_admin_html($this->params(), FALSE),
      myapi_mail_reservation_admin_html($this->params())
    );
  }

  /* -- Body: the resident's cancellation email ---------------------------- */

  /**
   * The resident's email carries the reason as its last data line, after
   * 'Duración', and keeps the closing sentence of SPEC 48.
   */
  public function testResidentCancellationEmailShowsTheReason() {
    $params = [
      'name'          => 'Ana',
      'area'          => 'Gimnasio',
      'condominium'   => 'Los Robles',
      'unit'          => 'Casa 12',
      'date'          => '27/07/2026',
      'schedule'      => '09:00 - 10:00',
      'duration'      => '1h 0min',
      'nid'           => 501513,
      'cancel_reason' => 'El evento se pospuso',
    ];

    $lines = $this->lines(myapi_mail_reservation_user_html($params, TRUE));

    $this->assertSame(
      ['Área', 'Condominio', 'Vivienda', 'Fecha', 'Horario', 'Duración', 'Motivo'],
      array_keys($lines)
    );
    $this->assertSame('El evento se pospuso', $lines['Motivo']);
  }

  /**
   * With an empty reason, and with an item enqueued before SPEC 50 (no such
   * key at all), the six lines of SPEC 48 are all there is.
   */
  public function testResidentCancellationEmailWithoutReasonIsUnchanged() {
    $params = [
      'name'        => 'Ana',
      'area'        => 'Gimnasio',
      'condominium' => 'Los Robles',
      'unit'        => 'Casa 12',
      'date'        => '27/07/2026',
      'schedule'    => '09:00 - 10:00',
      'duration'    => '1h 0min',
      'nid'         => 501513,
    ];
    $expected = ['Área', 'Condominio', 'Vivienda', 'Fecha', 'Horario', 'Duración'];

    $this->assertSame($expected, array_keys($this->lines(myapi_mail_reservation_user_html($params, TRUE))));

    $params['cancel_reason'] = '';
    $this->assertSame($expected, array_keys($this->lines(myapi_mail_reservation_user_html($params, TRUE))));
  }
}
