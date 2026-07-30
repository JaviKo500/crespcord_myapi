<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.reservation_query.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_notification.inc';

/**
 * Unit tests for the push + inbox body of the reservation notifications
 * (SPEC 48) with the cancellation reason added by SPEC 50.
 *
 * myapi_reservation_notification_body() is the only pure function of
 * includes/myapi.reservation_notification.inc: it takes a row and returns the
 * plain text the app renders. Everything around it — the row builder, the two
 * enqueue helpers and the three notifiers — runs node_load(), db_select() or
 * the mail queue, so they stay out of tests/unit for the same documented
 * reason as myapi_reservation_calendar_rows() (SPEC 47).
 *
 * The core promise under test is the "byte for byte" one of SPEC 50: with no
 * reason the body must be EXACTLY the three-line text of SPEC 48, so an
 * upgraded site sends the very same notification it sent yesterday.
 *
 * includes/myapi.reservation_query.inc is required because the schedule label
 * reuses myapi_reservation_time_to_minutes() for the overnight marker.
 */
class ReservationNotificationBodyTest extends TestCase {

  /**
   * Builds a reservation row with the shape
   * myapi_reservation_notification_row() returns. Only the five properties the
   * body reads are set here.
   */
  private function row($area_title, $cancel_reason = NULL, $start = '09:00', $end = '10:00') {
    $row = new stdClass();
    $row->area_title = $area_title;
    $row->date = '2026-07-27';
    $row->start_time = $start;
    $row->end_time = $end;
    $row->cancel_reason = $cancel_reason;

    return $row;
  }

  /**
   * The exact four-line text of the spec.
   */
  public function testCancelledBodyWithReasonHasTheMotivoLine() {
    $body = myapi_reservation_notification_body(
      $this->row('Cancha de golf', 'Mantenimiento de la piscina'),
      TRUE
    );

    $this->assertSame(
      "Tu reserva del área \"Cancha de golf\" ha sido cancelada por un operador."
      . "\nFecha: 27/07/2026"
      . "\nHorario: 09:00 - 10:00"
      . "\nMotivo: Mantenimiento de la piscina",
      $body
    );
  }

  /**
   * No reason: byte for byte the SPEC 48 body, three lines and no trailing
   * newline.
   */
  public function testCancelledBodyWithoutReasonIsUnchanged() {
    $body = myapi_reservation_notification_body($this->row('Cancha de golf'), TRUE);

    $this->assertSame(
      "Tu reserva del área \"Cancha de golf\" ha sido cancelada por un operador."
      . "\nFecha: 27/07/2026"
      . "\nHorario: 09:00 - 10:00",
      $body
    );
  }

  /**
   * A whitespace-only reason is no reason: same three lines, no empty 'Motivo'.
   */
  public function testWhitespaceOnlyReasonDoesNotAddTheLine() {
    foreach (['', '   ', "\n"] as $reason) {
      $body = myapi_reservation_notification_body($this->row('Cancha de golf', $reason), TRUE);

      $this->assertStringNotContainsString('Motivo', $body, json_encode($reason));
      $this->assertSame(2, substr_count($body, "\n"), json_encode($reason));
    }
  }

  /**
   * A row built before SPEC 50, with no cancel_reason property at all, must not
   * warn nor add the line.
   */
  public function testRowWithoutTheCancelReasonPropertyIsSafe() {
    $row = $this->row('Cancha de golf');
    unset($row->cancel_reason);

    $body = myapi_reservation_notification_body($row, TRUE);

    $this->assertStringNotContainsString('Motivo', $body);
  }

  /**
   * The 'Motivo' line belongs to the cancellation and nowhere else: a creation
   * never carries it, even when the node happens to hold a reason (an operator
   * may type one with the reservation still confirmed).
   */
  public function testCreatedBodyNeverCarriesTheReason() {
    $body = myapi_reservation_notification_body(
      $this->row('Cancha de golf', 'Mantenimiento de la piscina'),
      FALSE
    );

    $this->assertSame(
      "Tu reserva del área \"Cancha de golf\" ha sido confirmada."
      . "\nFecha: 27/07/2026"
      . "\nHorario: 09:00 - 10:00",
      $body
    );
  }

  /**
   * A deleted area drops the quoted name, and the reason still lands last.
   */
  public function testDeletedAreaKeepsTheReasonAsTheFourthLine() {
    $body = myapi_reservation_notification_body($this->row(NULL, 'El evento se pospuso'), TRUE);

    $this->assertSame(
      "Tu reserva ha sido cancelada por un operador."
      . "\nFecha: 27/07/2026"
      . "\nHorario: 09:00 - 10:00"
      . "\nMotivo: El evento se pospuso",
      $body
    );
  }

  /**
   * The overnight marker of the schedule line is untouched by the new line.
   */
  public function testOvernightMarkerSurvivesWithAReason() {
    $body = myapi_reservation_notification_body(
      $this->row('Salón', 'Se pospuso', '22:00', '02:00'),
      TRUE
    );

    $this->assertSame(
      "Tu reserva del área \"Salón\" ha sido cancelada por un operador."
      . "\nFecha: 27/07/2026"
      . "\nHorario: 22:00 - 02:00 (+1 día)"
      . "\nMotivo: Se pospuso",
      $body
    );
  }

  /**
   * The body carries the reason RAW: this text is stored in myapi_notifications
   * and rendered by the app as plain text, so escaping it here would show the
   * resident '&amp;' where they typed '&'. The 200-character push cut is
   * applied later, by myapi_onesignal_truncate_body(), and never here.
   */
  public function testReasonTravelsRawAndUncut() {
    $reason = 'Se canceló por "lluvia" & viento — ' . str_repeat('x', 200);

    $body = myapi_reservation_notification_body($this->row('Cancha', $reason), TRUE);

    $this->assertStringContainsString("\nMotivo: " . $reason, $body);
    $this->assertGreaterThan(200, strlen($body));
  }
}
