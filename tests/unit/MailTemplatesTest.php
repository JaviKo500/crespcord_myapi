<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.mail.inc';

/**
 * Unit tests for the HTML email templates (SPECS 07, 48, 50, 68 and 71,
 * covered by SPEC 121).
 *
 * THE ONE OUTPUT OF THIS MODULE NOBODY EVER LOOKS AT AGAIN. An API response is
 * read by the app on every request and a back-office screen is read by an
 * operator every day; an email is composed once, sent once, and read by
 * somebody who cannot report a broken one back. Five of these templates had no
 * test at all.
 *
 * Three properties are worth naming, and each one is a way these files fail
 * silently:
 *
 *  - THE Content-Type HEADER IS THE WHOLE FEATURE. Drupal 7's DefaultMailSystem
 *    runs every body through drupal_html_to_text(); these keys are mapped to
 *    MyapiHtmlMailSystem so the markup survives, and each formatter sets
 *    'text/html' itself. A formatter that forgot it sends a wall of visible
 *    tags to a resident.
 *  - THE VALUES ARRIVE ALREADY ESCAPED, so the templates print them as they
 *    are. That is a contract between the notifier and the template, and the
 *    tests below assert both halves of it: what the template prints untouched,
 *    and the two places where it deliberately does NOT (nl2br over a
 *    description, and the catalogue copy that carries a literal <br>).
 *  - THE SUBJECT IS DECODED. A subject line is not HTML, so '&amp;' in it
 *    would be read literally by a mail client; every formatter runs
 *    decode_entities() over the escaped values before composing it. That is
 *    the one transformation that would be invisible in a body test.
 *
 * The layout itself — table-based, inline styles, no external assets — is
 * asserted only where it carries meaning (the document is a full HTML page,
 * the button points at the link, the logo is there). Pinning the palette would
 * be pinning a design decision to a test.
 */
class MailTemplatesTest extends TestCase {

  /**
   * Runs a formatter the way myapi_mail() does and answers the message.
   */
  private function format($formatter, array $params, $key = NULL) {
    $message = ['subject' => '', 'body' => [], 'headers' => []];

    if ($key === NULL) {
      $formatter($message, $params);
    }
    else {
      $formatter($message, $params, $key);
    }

    return $message;
  }

  /**
   * The body of a formatted message, as one string.
   */
  private function body(array $message) {
    return implode('', $message['body']);
  }

  /**
   * The params of a claim email, already escaped as the notifier hands them
   * over.
   */
  private function claimParams(array $overrides = []) {
    return $overrides + [
      'nid'            => 500,
      'type_label'     => 'Reclamo',
      'type_noun'      => 'reclamo',
      'subject'        => 'Fuga de agua en el pasillo',
      'subject_short'  => 'Fuga de agua en el pasillo',
      'condominium'    => 'Torre Andaluc&iacute;a',
      'status'         => 'Recibido',
      'reception_date' => '15/06/2026 10:30',
      'description'    => "Se ve agua desde ayer.\nEn el piso 3.",
      'name'           => 'Pablo',
    ];
  }

  /* -------------------------------------------------------------------------
   * The password reset email (SPEC 07).
   * ---------------------------------------------------------------------- */

  /**
   * The formatter sets the translated subject, one HTML body and the header
   * that keeps the markup alive.
   */
  public function testThePasswordResetFormatterSetsSubjectBodyAndHtmlHeader() {
    $message = $this->format('myapi_mail_format_password_reset', [
      'link' => 'https://crespcord.example.com/reset/abc',
      'minutes' => 30,
      'name' => 'Pablo',
      'language' => 'es',
    ]);

    $this->assertSame(myapi_t('password_reset_email_subject', [], 'es'), $message['subject']);
    $this->assertCount(1, $message['body']);
    $this->assertSame('text/html; charset=UTF-8', $message['headers']['Content-Type']);
  }

  /**
   * The body is a full HTML document with the branding, the greeting, the
   * expiry notice and the button pointing at the reset link.
   */
  public function testThePasswordResetBodyCarriesTheLinkAndTheExpiry() {
    $params = [
      'link' => 'https://crespcord.example.com/reset/abc',
      'minutes' => 30,
      'name' => 'Pablo',
      'language' => 'es',
    ];

    $html = myapi_mail_password_reset_html($params);

    $this->assertStringStartsWith('<!DOCTYPE html>', $html);
    $this->assertStringContainsString('<html lang="es"', $html);
    $this->assertStringContainsString('href="https://crespcord.example.com/reset/abc"', $html);
    $this->assertStringContainsString('30', $html, 'the expiry minutes');
    $this->assertStringContainsString('Pablo', $html);
    $this->assertStringContainsString(myapi_mail_logo_url(), $html);
  }

  /**
   * IT IS TRANSLATED, and the language travels into the <html lang> too — the
   * catalogue is what decides the copy, not the template.
   */
  public function testThePasswordResetEmailIsTranslated() {
    $params = ['link' => 'https://x/y', 'minutes' => 30, 'name' => 'Pablo', 'language' => 'en'];

    $english = myapi_mail_password_reset_html($params);
    $params['language'] = 'es';
    $spanish = myapi_mail_password_reset_html($params);

    $this->assertStringContainsString('<html lang="en"', $english);
    $this->assertNotSame($english, $spanish, 'the two languages produce different copy');
    $this->assertStringContainsString(check_plain(myapi_t('password_reset_email_intro', [], 'en')), $english);
  }

  /**
   * THE LINK IS ESCAPED. It is built by the endpoint from a Drupal variable
   * and a token, and it lands inside an href — the one value of this template
   * that a template could break by trusting.
   */
  public function testThePasswordResetLinkIsEscaped() {
    $html = myapi_mail_password_reset_html([
      'link' => 'https://x/y?a=1&b=2"><script>alert(1)</script>',
      'minutes' => 30,
      'name' => 'Pablo',
      'language' => 'es',
    ]);

    $this->assertStringNotContainsString('<script>', $html);
    $this->assertStringContainsString('&amp;b=2', $html);
  }

  /* -------------------------------------------------------------------------
   * The reservation emails (SPECS 48 and 50).
   * ---------------------------------------------------------------------- */

  /**
   * The resident's email has two variants behind one formatter, and the KEY is
   * what chooses: the subject, the wording and the closing line.
   */
  public function testTheResidentReservationEmailHasTwoVariants() {
    $params = [
      'name' => 'Pablo Cordero',
      'area' => 'Piscina &amp; Jard&iacute;n',
      'condominium' => 'Torre Andaluc&iacute;a',
      'unit' => 'A-101',
      'date' => '15/06/2026',
      'schedule' => '10:00 - 11:30',
      'duration' => '1h 30min',
      'nid' => 800,
      'cancel_reason' => '',
    ];

    $created = $this->format('myapi_mail_format_reservation_user', $params, 'reservation_created_user');
    $cancelled = $this->format('myapi_mail_format_reservation_user', $params, 'reservation_cancelled_user');

    $this->assertStringStartsWith('Reserva confirmada — ', $created['subject']);
    $this->assertStringStartsWith('Reserva cancelada — ', $cancelled['subject']);
    $this->assertStringContainsString('ha sido confirmada', $this->body($created));
    $this->assertStringContainsString('cancelada', $this->body($cancelled));
    $this->assertSame('text/html; charset=UTF-8', $created['headers']['Content-Type']);
  }

  /**
   * THE SUBJECT IS DECODED and the body is not. A subject line is plain text
   * for a mail client, so '&amp;' in it would be read literally; the same
   * value inside the HTML body stays escaped.
   */
  public function testTheSubjectIsDecodedAndTheBodyIsNot() {
    $params = [
      'name' => 'Pablo', 'area' => 'Piscina &amp; Jard&iacute;n', 'condominium' => 'Torre',
      'unit' => 'A-101', 'date' => '15/06/2026', 'schedule' => '10:00 - 11:30',
      'duration' => '1h 30min', 'nid' => 800, 'cancel_reason' => '',
    ];

    $message = $this->format('myapi_mail_format_reservation_user', $params, 'reservation_created_user');

    $this->assertStringContainsString('Piscina & Jardín', $message['subject']);
    $this->assertStringNotContainsString('&amp;', $message['subject']);
    $this->assertStringContainsString('Piscina &amp; Jard&iacute;n', $this->body($message), 'the body keeps the escaping');
  }

  /**
   * The body prints the six data lines the resident needs and the reservation
   * number.
   */
  public function testTheResidentEmailPrintsItsDataLines() {
    $html = myapi_mail_reservation_user_html([
      'name' => 'Pablo Cordero', 'area' => 'Piscina', 'condominium' => 'Torre Andalucía',
      'unit' => 'A-101', 'date' => '15/06/2026', 'schedule' => '10:00 - 11:30',
      'duration' => '1h 30min', 'nid' => 800, 'cancel_reason' => '',
    ], FALSE);

    foreach (['Pablo Cordero', 'Piscina', 'Torre Andalucía', 'A-101', '15/06/2026', '10:00 - 11:30', '1h 30min', '800'] as $value) {
      $this->assertStringContainsString($value, $html, $value);
    }
    $this->assertStringStartsWith('<!DOCTYPE html>', $html);
  }

  /**
   * THE 'Motivo' LINE APPEARS ONLY ON A CANCELLATION THAT HAS ONE. An empty
   * string is what keeps it out, which is exactly what the notifier passes on
   * a creation.
   */
  public function testTheReasonLineAppearsOnlyWhenThereIsOne() {
    $params = [
      'name' => 'Pablo', 'area' => 'Piscina', 'condominium' => 'Torre', 'unit' => 'A-101',
      'date' => '15/06/2026', 'schedule' => '10:00 - 11:30', 'duration' => '1h 30min',
      'nid' => 800, 'cancel_reason' => 'Mantenimiento imprevisto',
    ];

    $with = myapi_mail_reservation_user_html($params, TRUE);
    $this->assertStringContainsString('Mantenimiento imprevisto', $with);

    $params['cancel_reason'] = '';
    $without = myapi_mail_reservation_user_html($params, TRUE);
    $this->assertStringNotContainsString('Motivo', $without);

    // THE TEMPLATE DOES NOT GATE THE LINE BY THE VARIANT — it prints a reason
    // whenever it is given one, and it is the NOTIFIER that passes '' on a
    // creation (myapi_reservation_enqueue_user_mail()). Pinned as it is, with
    // the two halves next to each other, so that moving the guard becomes a
    // decision: today the creation email is clean because of the caller, not
    // because of the template.
    $params['cancel_reason'] = 'Mantenimiento imprevisto';
    $creation = myapi_mail_reservation_user_html($params, FALSE);
    $this->assertStringContainsString('Mantenimiento imprevisto', $creation, 'the template prints what it is given');
  }

  /**
   * The 'backend' email carries the nid in its SUBJECT — the operator sorts
   * their inbox by it — and both of its variants share one formatter.
   */
  public function testTheBackendReservationEmailCarriesTheNidInTheSubject() {
    $params = [
      'nid' => 800, 'user' => 'Pablo Cordero', 'email' => 'p@example.com', 'unit' => 'A-101',
      'area' => 'Piscina', 'condominium' => 'Torre', 'date' => '15/06/2026',
      'schedule' => '10:00 – 11:30', 'duration' => '1h 30min', 'status' => 'Confirmada',
      'created' => '15/06/2026 09:00', 'node_url' => 'https://x/node/800',
      'cancelled_by' => 'Usuario', 'cancel_reason' => '',
    ];

    $created = $this->format('myapi_mail_format_reservation_admin', $params, 'reservation_created_admin');
    $cancelled = $this->format('myapi_mail_format_reservation_admin', $params, 'reservation_cancelled_admin');

    $this->assertStringStartsWith('Nueva reserva #800 — ', $created['subject']);
    $this->assertStringStartsWith('Reserva cancelada #800 — ', $cancelled['subject']);
    $this->assertStringContainsString('https://x/node/800', $this->body($created));
    $this->assertStringContainsString('p@example.com', $this->body($created));
  }

  /**
   * The shared HTML builder takes its lines from the caller, so a template
   * change reaches every reservation email at once.
   */
  public function testTheSharedReservationBuilderRendersWhatItIsGiven() {
    $html = myapi_mail_reservation_html(
      'Título',
      'Encabezado',
      'Una frase de contexto.',
      ['Área' => 'Piscina', 'Fecha' => '15/06/2026'],
      'Un pie de página',
      ['label' => 'Ver más', 'url' => 'https://x/node/800']
    );

    $this->assertStringStartsWith('<!DOCTYPE html>', $html);
    $this->assertStringContainsString('<title>Título</title>', $html);
    $this->assertStringContainsString('Encabezado', $html);
    $this->assertStringContainsString('Una frase de contexto.', $html);
    $this->assertStringContainsString('Área', $html);
    $this->assertStringContainsString('Piscina', $html);
    $this->assertStringContainsString('Un pie de página', $html);
    $this->assertStringContainsString('https://x/node/800', $html);
    $this->assertStringContainsString('Ver más', $html);
  }

  /**
   * The button is OPTIONAL: without it the document renders the same lines and
   * no link at all.
   */
  public function testTheButtonIsOptional() {
    $html = myapi_mail_reservation_html('T', 'H', 'I', ['A' => 'B'], 'F');

    $this->assertStringContainsString('B', $html);
    $this->assertStringNotContainsString('<a href', $html);
  }

  /* -------------------------------------------------------------------------
   * The claim emails (SPECS 68 and 71).
   * ---------------------------------------------------------------------- */

  /**
   * The requester's acknowledgement names the type and carries the context
   * sentence of a claim that was just received.
   */
  public function testTheClaimAcknowledgementNamesTheTypeAndTheSubject() {
    $message = $this->format('myapi_mail_format_claim_created_user', $this->claimParams());

    $this->assertSame('Reclamo recibido — Fuga de agua en el pasillo', $message['subject']);
    $this->assertStringContainsString('Hemos recibido tu reclamo', $this->body($message));
    $this->assertSame('text/html; charset=UTF-8', $message['headers']['Content-Type']);
  }

  /**
   * The neighbour's email is the SAME data block with a different context
   * sentence and a different subject — showing a neighbour less than the
   * requester would be pointless, since the claim is public.
   */
  public function testTheNeighbourEmailIsTheSameBlockWithAnotherIntro() {
    $params = $this->claimParams();

    $requester = $this->format('myapi_mail_format_claim_created_user', $params);
    $neighbour = $this->format('myapi_mail_format_claim_published_neighbour', $params);

    $this->assertSame('Nuevo reclamo en tu condominio — Fuga de agua en el pasillo', $neighbour['subject']);
    $this->assertStringContainsString('Se publicó un nuevo reclamo en tu condominio.', $this->body($neighbour));

    foreach (['Fuga de agua en el pasillo', 'Reclamo', 'Recibido', '15/06/2026 10:30'] as $value) {
      $this->assertStringContainsString($value, $this->body($requester), $value);
      $this->assertStringContainsString($value, $this->body($neighbour), $value);
    }
  }

  /**
   * THE DESCRIPTION KEEPS ITS LINE BREAKS. field_description is a textarea and
   * the breaks are part of what the resident wrote, so the template runs
   * nl2br() over the already-escaped value — the one deliberate exception to
   * "print what you were given".
   */
  public function testTheDescriptionKeepsItsLineBreaks() {
    $html = myapi_mail_claim_html($this->claimParams(), 'Intro.');

    $this->assertStringContainsString('<br', $html);
    $this->assertStringContainsString('Se ve agua desde ayer.', $html);
    $this->assertStringContainsString('En el piso 3.', $html);
  }

  /**
   * AN EMPTY DESCRIPTION DROPS ITS LINE rather than printing an empty block.
   */
  public function testAnEmptyDescriptionDropsItsLine() {
    $html = myapi_mail_claim_html($this->claimParams(['description' => '']), 'Intro.');

    $this->assertStringNotContainsString('Descripción', $html);
  }

  /**
   * The subject of the claim travels WHOLE in the data block; only the mail
   * subject line is the short one.
   */
  public function testTheSubjectTravelsWholeInTheBody() {
    $long = str_repeat('Fuga de agua en el pasillo del tercer piso. ', 5);
    $params = $this->claimParams(['subject' => $long, 'subject_short' => 'Fuga de agua en el pasi…']);

    $message = $this->format('myapi_mail_format_claim_created_user', $params);

    $this->assertStringContainsString('Fuga de agua en el pasi…', $message['subject']);
    $this->assertStringContainsString($long, $this->body($message), 'the body is not truncated');
  }

  /**
   * The transaction email has two variants behind one formatter, and both
   * carry the operator's comment WHOLE — there is no 200-character push budget
   * to respect in an email.
   */
  public function testTheTransactionEmailHasTwoVariantsAndCarriesTheCommentWhole() {
    $comment = str_repeat('Se envió al proveedor y quedamos a la espera de su visita. ', 6);
    $params = [
      'nid' => 500, 'type_label' => 'Reclamo', 'type_noun' => 'reclamo',
      'subject' => 'Fuga de agua', 'subject_short' => 'Fuga de agua',
      'status' => 'En proceso', 'date' => '15/06/2026 11:00', 'comment' => $comment,
      'name' => 'Pablo',
    ];

    $requester = $this->format('myapi_mail_format_claim_transaction_user', $params, 'claim_transaction_requester');
    $neighbour = $this->format('myapi_mail_format_claim_transaction_user', $params, 'claim_transaction_neighbour');

    $this->assertSame('Novedad en tu reclamo — Fuga de agua', $requester['subject']);
    $this->assertSame('Novedad en un reclamo de tu condominio — Fuga de agua', $neighbour['subject']);
    $this->assertStringContainsString($comment, $this->body($requester));
    $this->assertStringContainsString($comment, $this->body($neighbour));
  }

  /**
   * The back-office claim email is the one whose subject carries the NID and
   * the CONDOMINIUM instead of the claim's subject — it is the one an operator
   * files by.
   */
  public function testTheBackOfficeClaimEmailIsKeyedByNidAndCondominium() {
    $params = $this->claimParams([
      'visibility' => 'Pública', 'requester' => 'Pablo Cordero', 'email' => 'p@example.com',
      'node_url' => 'https://x/node/500', 'attachments' => '2 archivos',
    ]);

    $message = $this->format('myapi_mail_format_claim_created_admin', $params);

    $this->assertSame('Nuevo reclamo #500 — Torre Andalucía', $message['subject']);
    $this->assertStringContainsString('https://x/node/500', $this->body($message));
    $this->assertStringContainsString('p@example.com', $this->body($message));
    $this->assertStringContainsString('2 archivos', $this->body($message));
  }

  /**
   * The closing email is its own formatter and its own body, because the thing
   * being announced is different: the claim is done.
   */
  public function testTheClosingEmailIsItsOwnMessage() {
    $params = [
      'nid' => 500, 'type_label' => 'Reclamo', 'type_noun' => 'reclamo',
      'subject' => 'Fuga de agua', 'subject_short' => 'Fuga de agua',
      'requester' => 'Pablo Cordero', 'email' => 'p@example.com',
      'condominium' => 'Torre Andalucía', 'status' => 'Cerrado',
      // The closing note travels under 'comment', the same key the transaction
      // email uses — it IS the closing transaction's comment.
      'comment' => 'Resuelto por el conserje', 'date' => '16/06/2026 09:00',
      'node_url' => 'https://x/node/500',
    ];

    $message = $this->format('myapi_mail_format_claim_closed_admin', $params);

    $this->assertNotSame('', $message['subject']);
    $this->assertStringContainsString('500', $message['subject']);
    $this->assertStringContainsString('Resuelto por el conserje', $this->body($message));
    $this->assertSame('text/html; charset=UTF-8', $message['headers']['Content-Type']);
  }

  /**
   * The closing body carries the reason and the link, and stays a full HTML
   * document.
   */
  public function testTheClosingBodyCarriesTheReasonAndTheLink() {
    $html = myapi_mail_claim_closed_admin_html([
      'nid' => 500, 'type_label' => 'Reclamo', 'type_noun' => 'reclamo',
      'subject' => 'Fuga de agua', 'requester' => 'Pablo Cordero', 'email' => 'p@example.com',
      'condominium' => 'Torre Andalucía', 'status' => 'Cerrado',
      'comment' => 'Resuelto por el conserje', 'date' => '16/06/2026 09:00',
      'node_url' => 'https://x/node/500',
    ]);

    $this->assertStringStartsWith('<!DOCTYPE html>', $html);
    $this->assertStringContainsString('Resuelto por el conserje', $html);
    $this->assertStringContainsString('https://x/node/500', $html);
  }

  /* -------------------------------------------------------------------------
   * The contract, stated across every template.
   * ---------------------------------------------------------------------- */

  /**
   * EVERY FORMATTER SETS THE HTML CONTENT TYPE. Without it the body is run
   * through drupal_html_to_text() and the resident reads the markup.
   */
  public function testEveryFormatterSetsTheHtmlContentType() {
    $claim = $this->claimParams([
      'visibility' => 'Pública', 'requester' => 'Pablo', 'email' => 'p@example.com',
      'node_url' => 'https://x/node/500',
    ]);
    $reservation = [
      'name' => 'Pablo', 'area' => 'Piscina', 'condominium' => 'Torre', 'unit' => 'A-101',
      'date' => '15/06/2026', 'schedule' => '10:00 - 11:30', 'duration' => '1h 30min',
      'nid' => 800, 'cancel_reason' => '',
    ];
    $transaction = [
      'nid' => 500, 'type_label' => 'Reclamo', 'type_noun' => 'reclamo',
      'subject' => 'Fuga', 'subject_short' => 'Fuga', 'status' => 'En proceso',
      'date' => '15/06/2026', 'comment' => 'Comentario', 'name' => 'Pablo',
    ];

    $messages = [
      'password_reset' => $this->format('myapi_mail_format_password_reset', [
        'link' => 'https://x/y', 'minutes' => 30, 'name' => 'Pablo', 'language' => 'es',
      ]),
      'reservation_user'  => $this->format('myapi_mail_format_reservation_user', $reservation, 'reservation_created_user'),
      'claim_created'     => $this->format('myapi_mail_format_claim_created_user', $claim),
      'claim_neighbour'   => $this->format('myapi_mail_format_claim_published_neighbour', $claim),
      'claim_transaction' => $this->format('myapi_mail_format_claim_transaction_user', $transaction, 'claim_transaction_requester'),
      'claim_admin'       => $this->format('myapi_mail_format_claim_created_admin', $claim),
    ];

    foreach ($messages as $name => $message) {
      $this->assertSame('text/html; charset=UTF-8', $message['headers']['Content-Type'], $name);
      $this->assertNotSame('', $message['subject'], $name);
      $this->assertCount(1, $message['body'], $name . ': one body part');
      $this->assertStringStartsWith('<!DOCTYPE html>', $this->body($message), $name);
    }
  }

  /**
   * THE TEMPLATES PRINT WHAT THEY ARE GIVEN. Every value arrives escaped from
   * the notifier, so a template that escaped again would render '&amp;amp;' in
   * front of the reader. The two documented exceptions are elsewhere: nl2br()
   * over a description, and the catalogue copy of the reset signoff.
   */
  public function testTheTemplatesDoNotEscapeTwice() {
    $html = myapi_mail_claim_html($this->claimParams([
      'condominium' => 'Torre &amp; Cía',
      'subject'     => 'Fuga &lt;b&gt;grave&lt;/b&gt;',
      'description' => '',
    ]), 'Intro.');

    $this->assertStringContainsString('Torre &amp; Cía', $html);
    $this->assertStringNotContainsString('&amp;amp;', $html);
    $this->assertStringContainsString('Fuga &lt;b&gt;grave&lt;/b&gt;', $html);
    $this->assertStringNotContainsString('<b>grave</b>', $html, 'and nothing becomes live markup');
  }
}
