<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.mail.inc';
require_once __DIR__ . '/../../includes/myapi.payment_workflow.inc';

/**
 * Unit tests for the payment detail email sent to the 'backend' role (SPEC 80).
 *
 * myapi_mail_format_payment_admin() and myapi_mail_payment_admin_html() are
 * where a mail key stops being a string and becomes a subject, thirteen
 * labelled lines and a button, so they are the seam worth testing. The enqueue side,
 * myapi_payment_notify_created(), resolves the role with a query and loads the
 * recipients with user_load_multiple(), so it stays out of tests/unit for the
 * same documented reason as myapi_reservation_enqueue_admin_mails() (SPEC 48);
 * its verification is `php -l` plus the manual matrix of the doc.
 *
 * myapi_payment_date_label() is tested here too because it is pure string work
 * and it is the one value of the email that could silently drift by a day.
 * myapi_payment_method_label() is not: it reads the field's allowed_values
 * through field_info_field(), which has no meaning outside a site.
 *
 * myapi_payment_channel_label() (SPEC 129) reads allowed_values the same way,
 * but it IS tested here: SPEC 77 added the field_info_field() stub and
 * myapi_test_field_seed_allowed_values() to bootstrap.php after SPEC 80 wrote
 * the paragraph above, so a test can now hand it the catalogue the update
 * creates and assert the three labels, the empty placeholder and the raw
 * fallback without a site.
 */
class PaymentAdminMailTest extends TestCase {

  /**
   * The params, already escaped, as myapi_payment_backend_mail_params() builds
   * them for a transfer with an attached receipt.
   */
  private function params($extra = []) {
    return $extra + [
      'nid'         => 502210,
      'reference'   => 'TRF-99811',
      'amount'      => '1,250.00',
      'method'      => 'Transferencia',
      'channel'     => 'App',
      'bank'        => 'Banco Pichincha',
      'date'        => '11/08/2026',
      'unit'        => 'Casa 12',
      'condominium' => 'Los Robles',
      'resident'    => 'Ana Pérez',
      'email'       => 'ana@example.com',
      'file'        => 'comprobante.pdf',
      'status'      => 'Pendiente de verificar',
      'created'     => '12/08/2026 09:41',
      'edit_url'    => 'https://crespcord.lamotora.com/node/502210/edit',
    ];
  }

  /**
   * Extracts the label => value pairs of the data table, in display order.
   *
   * Anchored on the label cell's own colour so the layout's outer table cells
   * (header, footer, button) never leak into the result. Same reader as
   * ReservationAdminMailTest, because both emails share the HTML shell.
   */
  private function lines($html) {
    preg_match_all('~<tr><td style="[^"]*color:#907050[^"]*">(.*?)</td><td[^>]*>(.*?)</td></tr>~s', $html, $matches);

    return array_combine($matches[1], $matches[2]);
  }

  /* -- Subject ------------------------------------------------------------ */

  public function testSubjectCarriesNidReferenceAndAmount() {
    $message = ['body' => [], 'headers' => []];

    myapi_mail_format_payment_admin($message, $this->params());

    $this->assertSame('Nuevo pago #502210 — Ref. TRF-99811, 1,250.00', $message['subject']);
  }

  /**
   * A subject is plain text, so the escaped params are decoded back: a
   * reference typed as 'A&B-1' must not read 'A&amp;B-1' in the inbox list.
   */
  public function testSubjectDecodesTheEscapedReference() {
    $message = ['body' => [], 'headers' => []];

    myapi_mail_format_payment_admin($message, $this->params(['reference' => 'A&amp;B-1']));

    $this->assertSame('Nuevo pago #502210 — Ref. A&B-1, 1,250.00', $message['subject']);
  }

  /**
   * Without this header MyapiHtmlMailSystem delivers markup the client shows
   * as plain text.
   */
  public function testKeyDeclaresHtmlAndOneBodyPart() {
    $message = ['body' => [], 'headers' => []];

    myapi_mail_format_payment_admin($message, $this->params());

    $this->assertSame('text/html; charset=UTF-8', $message['headers']['Content-Type']);
    $this->assertCount(1, $message['body']);
  }

  /* -- Body --------------------------------------------------------------- */

  public function testHtmlPrintsTheThirteenLinesInOrder() {
    $lines = $this->lines(myapi_mail_payment_admin_html($this->params()));

    $this->assertSame([
      'Referencia', 'Monto', 'Forma de pago', 'Canal', 'Banco',
      'Fecha del pago', 'Vivienda', 'Condominio', 'Residente', 'Email',
      'Comprobante', 'Estado', 'Registrado el',
    ], array_keys($lines));

    $this->assertSame('TRF-99811', $lines['Referencia']);
    $this->assertSame('1,250.00', $lines['Monto']);
    $this->assertSame('App', $lines['Canal']);
    $this->assertSame('Banco Pichincha', $lines['Banco']);
    $this->assertSame('Casa 12', $lines['Vivienda']);
    $this->assertSame('comprobante.pdf', $lines['Comprobante']);
    $this->assertSame('Pendiente de verificar', $lines['Estado']);
  }

  /**
   * A cash payment has no bank and may have no receipt. Both lines are still
   * drawn with the placeholder: "no attachment" is itself what decides whether
   * the operator can verify the payment at all, so it must not disappear.
   */
  public function testEmptyBankAndFileKeepTheirLines() {
    $lines = $this->lines(myapi_mail_payment_admin_html($this->params([
      'bank'   => MYAPI_PAYMENT_MAIL_EMPTY,
      'file'   => MYAPI_PAYMENT_MAIL_EMPTY,
      'method' => 'Efectivo',
    ])));

    $this->assertCount(13, $lines);
    $this->assertSame('—', $lines['Banco']);
    $this->assertSame('—', $lines['Comprobante']);
    $this->assertSame('Efectivo', $lines['Forma de pago']);
  }

  /**
   * The button is the point of the email: it must land on the EDIT form, since
   * the operator's next action is changing field_estado_pago.
   */
  public function testButtonPointsAtTheEditForm() {
    $html = myapi_mail_payment_admin_html($this->params());

    $this->assertStringContainsString('href="https://crespcord.lamotora.com/node/502210/edit"', $html);
    $this->assertStringContainsString('>Revisar pago</a>', $html);
    $this->assertStringContainsString('Nuevo pago', $html);
    $this->assertStringContainsString('Pago #502210', $html);
  }

  /* -- myapi_payment_date_label() ----------------------------------------- */

  public function testDateLabelReformatsTheStoredValue() {
    $this->assertSame('11/08/2026', myapi_payment_date_label('2026-08-11T14:30:00'));
    $this->assertSame('11/08/2026', myapi_payment_date_label('2026-08-11'));
  }

  /**
   * The stored value is the calendar date the resident picked, never an
   * instant: reformatting it as a string is what keeps a payment made at
   * 00:30 from being reported a day earlier or later.
   */
  public function testDateLabelDoesNotShiftAcrossMidnight() {
    $this->assertSame('01/01/2026', myapi_payment_date_label('2026-01-01T00:30:00'));
    $this->assertSame('31/12/2025', myapi_payment_date_label('2025-12-31T23:59:59'));
  }

  public function testDateLabelFallsBackToThePlaceholder() {
    $this->assertSame('—', myapi_payment_date_label(NULL));
    $this->assertSame('—', myapi_payment_date_label(''));
    $this->assertSame('—', myapi_payment_date_label('11/08/2026'));
  }

  /* -- The 'Canal' line --------------------------------------------------- */

  /**
   * The mail queue survives a deploy: a payment enqueued before SPEC 129 is
   * formatted by this code when cron drains the queue, and its stored params
   * carry no 'channel' key. The line is still drawn, with the placeholder,
   * rather than emitting a PHP notice or collapsing the table by one row.
   */
  public function testChannelLineFallsBackWhenTheParamIsMissing() {
    $params = $this->params();
    unset($params['channel']);

    $lines = $this->lines(myapi_mail_payment_admin_html($params));

    $this->assertCount(13, $lines);
    $this->assertSame('—', $lines['Canal']);
  }

  /* -- myapi_payment_channel_label() -------------------------------------- */

  /**
   * Seeds field_canal with exactly the allowed_values myapi_update_7046()
   * creates, so the labels asserted below are the ones a real site answers.
   */
  private function seedChannelField() {
    myapi_test_field_seed_allowed_values([
      MYAPI_PAYMENT_CHANNEL_FIELD => [
        MYAPI_PAYMENT_CHANNEL_BOT        => 'Bot de WhatsApp',
        MYAPI_PAYMENT_CHANNEL_APP        => 'App',
        MYAPI_PAYMENT_CHANNEL_BACKOFFICE => 'Back office',
      ],
    ]);
  }

  public function testChannelLabelResolvesTheThreeKnownKeys() {
    $this->seedChannelField();

    $this->assertSame('Bot de WhatsApp', myapi_payment_channel_label('bot'));
    $this->assertSame('App', myapi_payment_channel_label('app'));
    $this->assertSame('Back office', myapi_payment_channel_label('backoffice'));
  }

  /**
   * Every payment created before the field existed reaches the email with an
   * empty field_canal. It gets the same mark 'bank' and 'file' already use, so
   * the row keeps its shape instead of showing an empty cell.
   */
  public function testChannelLabelFallsBackToThePlaceholder() {
    $this->seedChannelField();

    $this->assertSame('—', myapi_payment_channel_label(NULL));
    $this->assertSame('—', myapi_payment_channel_label(''));
  }

  /**
   * A key dropped from allowed_values after the fact falls back to the raw
   * value rather than to an empty cell — same behaviour as
   * myapi_payment_method_label() — and goes out escaped, because by then it is
   * a string nobody validated.
   */
  public function testChannelLabelFallsBackToTheRawEscapedKey() {
    $this->seedChannelField();

    $this->assertSame('cualquier_cosa', myapi_payment_channel_label('cualquier_cosa'));
    $this->assertSame('&lt;b&gt;x&lt;/b&gt;', myapi_payment_channel_label('<b>x</b>'));
  }

  /**
   * A site where the field does not exist yet — the window between deploying
   * the code and running drush updb — answers NULL from field_info_field().
   * The label must still resolve to something printable.
   */
  public function testChannelLabelSurvivesAMissingField() {
    myapi_test_field_seed_allowed_values([]);

    $this->assertSame('app', myapi_payment_channel_label('app'));
    $this->assertSame('—', myapi_payment_channel_label(NULL));
  }

}
