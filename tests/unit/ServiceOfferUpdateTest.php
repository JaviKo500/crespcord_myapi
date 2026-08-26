<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.provider_role.inc';
require_once __DIR__ . '/../../includes/myapi.service_offer.inc';
require_once __DIR__ . '/../../includes/myapi.service_request_query.inc';
require_once __DIR__ . '/../../resources/service_offer.resource.inc';

/**
 * Unit tests for editing an offer — PUT /api/v1/service-offers/{id} (SPEC 105).
 *
 * THE PUT IS A TOTAL REPLACEMENT, and that is the one thing this class exists
 * to pin down: an optional field the provider leaves out of the body is a field
 * the provider deleted. myapi_service_offer_apply_values() is where that lives,
 * and it is the function SPEC 105 extracted out of
 * myapi_service_offer_build_node() so the POST and the PUT can never drift.
 *
 * ServiceOfferCreateTest is the net under that extraction and is NOT touched:
 * it asserts the other half of the same function — that on a NEW node an
 * undeclared optional is not written at all — and it still passes untouched.
 */
class ServiceOfferUpdateTest extends TestCase {

  /**
   * The twelve values of a body that declares everything, so that every
   * optional field has something to lose.
   */
  private function fullValues(array $overrides = []) {
    $body = $overrides + [
      'message'        => 'Puedo pasar el jueves por la mañana.',
      'amount_type'    => 'fixed',
      'amount'         => 150.5,
      'tax_included'   => TRUE,
      'valid_until'    => date('Y-m-d H:i', REQUEST_TIME + 7200),
      'available_from' => date('Y-m-d H:i', REQUEST_TIME + 3600),
      'duration'       => 3,
      'duration_unit'  => 'hours',
      'includes'       => 'Mano de obra.',
      'excludes'       => 'El calentador.',
      'warranty_days'  => 90,
      'requires_visit' => TRUE,
    ];

    $result = myapi_service_offer_validate_body($body);
    $this->assertTrue($result['ok'], 'the fixture body must be valid');

    return $result['values'];
  }

  /**
   * The minimum a body may be: three keys, nine values NULL and requires_visit
   * FALSE. This is the body that deletes everything else.
   */
  private function minimalValues() {
    $result = myapi_service_offer_validate_body([
      'message'     => 'Corrijo el precio.',
      'amount_type' => 'fixed',
      'amount'      => 120,
    ]);
    $this->assertTrue($result['ok'], 'the fixture body must be valid');

    return $result['values'];
  }

  /**
   * A stored offer, the way node_load() hands one back: EVERY field property
   * exists, filled or as an empty array. That is what makes the deletion branch
   * of apply_values() reachable, and what a brand new node never looks like.
   */
  private function storedOffer() {
    $node = new stdClass();
    $node->nid = 901;
    $node->type = 'service_offer';
    $node->uid = 7;
    $node->status = 1;
    $node->created = 1787000000;
    $node->title = 'Oferta de Plomería Torres — solicitud #128';
    $node->language = LANGUAGE_NONE;
    $node->field_request[LANGUAGE_NONE][0]['target_id'] = 128;
    $node->field_provider[LANGUAGE_NONE][0]['target_id'] = 41;
    $node->field_offer_status[LANGUAGE_NONE][0]['value'] = 'sent';

    myapi_service_offer_apply_values($node, $this->fullValues());

    return $node;
  }

  /* -------------------------------------------------------------------------
   * The extraction (step 1) — apply_values() on a node that already has values.
   * ---------------------------------------------------------------------- */

  /**
   * A body that declares everything writes all twelve, on a stored node exactly
   * as on a new one.
   */
  public function testAFullBodyWritesEveryColumnOnAStoredOffer() {
    $node = $this->storedOffer();

    $this->assertSame('Puedo pasar el jueves por la mañana.', $node->field_offer_message[LANGUAGE_NONE][0]['value']);
    $this->assertSame('fixed', $node->field_offer_amount_type[LANGUAGE_NONE][0]['value']);
    $this->assertSame(150.5, $node->field_offer_amount[LANGUAGE_NONE][0]['value']);
    $this->assertSame(1, $node->field_offer_tax_included[LANGUAGE_NONE][0]['value']);
    $this->assertSame(3, $node->field_offer_duration[LANGUAGE_NONE][0]['value']);
    $this->assertSame('hours', $node->field_offer_duration_unit[LANGUAGE_NONE][0]['value']);
    $this->assertSame('Mano de obra.', $node->field_offer_includes[LANGUAGE_NONE][0]['value']);
    $this->assertSame('El calentador.', $node->field_offer_excludes[LANGUAGE_NONE][0]['value']);
    $this->assertSame(90, $node->field_offer_warranty_days[LANGUAGE_NONE][0]['value']);
    $this->assertSame(1, $node->field_offer_requires_visit[LANGUAGE_NONE][0]['value']);
  }

  /**
   * THE HEART OF THE TOTAL REPLACEMENT: every optional field the second body
   * leaves out is EMPTIED, not left with what it had. An offer that had 90 days
   * of warranty ends the request with none.
   */
  public function testTheOptionalsTheBodyOmitsAreEmptied() {
    $node = $this->storedOffer();

    myapi_service_offer_apply_values($node, $this->minimalValues());

    foreach (['field_offer_tax_included', 'field_offer_valid_until',
      'field_offer_available_from', 'field_offer_duration',
      'field_offer_duration_unit', 'field_offer_includes',
      'field_offer_excludes', 'field_offer_warranty_days'] as $field) {
      $this->assertSame([], $node->{$field}, $field . ' must be emptied when the body omits it');
    }
  }

  /**
   * The two the body can never omit are overwritten, and amount follows
   * amount_type: dropping to 'on_site_quote' without an amount empties it.
   */
  public function testTheDeclaredValuesAreOverwritten() {
    $node = $this->storedOffer();

    myapi_service_offer_apply_values($node, $this->minimalValues());

    $this->assertSame('Corrijo el precio.', $node->field_offer_message[LANGUAGE_NONE][0]['value']);
    $this->assertSame(120.0, $node->field_offer_amount[LANGUAGE_NONE][0]['value']);

    $quote = myapi_service_offer_validate_body([
      'message'     => 'Necesito verlo antes de dar precio.',
      'amount_type' => 'on_site_quote',
    ]);
    $this->assertTrue($quote['ok']);
    myapi_service_offer_apply_values($node, $quote['values']);

    $this->assertSame('on_site_quote', $node->field_offer_amount_type[LANGUAGE_NONE][0]['value']);
    $this->assertSame([], $node->field_offer_amount, 'amount must be emptied when the body stops declaring it');
  }

  /**
   * requires_visit is the exception in both directions: ALWAYS written, as 0 or
   * 1, and never emptied. An absent requires_visit is FALSE and not NULL.
   */
  public function testRequiresVisitIsAlwaysWrittenAndNeverEmptied() {
    $node = $this->storedOffer();
    $this->assertSame(1, $node->field_offer_requires_visit[LANGUAGE_NONE][0]['value']);

    myapi_service_offer_apply_values($node, $this->minimalValues());

    $this->assertSame(0, $node->field_offer_requires_visit[LANGUAGE_NONE][0]['value']);
  }

  /**
   * apply_values() writes the twelve of the body and NOTHING ELSE. The seven
   * the server fixed the day the offer was born are still exactly what they
   * were — the PUT never rewrites history.
   */
  public function testWhatTheServerFixedIsNotTouched() {
    $node = $this->storedOffer();

    myapi_service_offer_apply_values($node, $this->minimalValues());

    $this->assertSame(901, $node->nid);
    $this->assertSame(7, $node->uid);
    $this->assertSame(1787000000, $node->created);
    $this->assertSame('Oferta de Plomería Torres — solicitud #128', $node->title);
    $this->assertSame(128, $node->field_request[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame(41, $node->field_provider[LANGUAGE_NONE][0]['target_id']);
    $this->assertSame('sent', $node->field_offer_status[LANGUAGE_NONE][0]['value']);

    // The three chat fields are never written, here either.
    foreach (['field_firebase_path', 'field_chat_opened_at', 'field_last_message_at'] as $field) {
      $this->assertFalse(property_exists($node, $field), $field . ' must stay empty');
    }
  }

  /**
   * Only 'value' is ever written, never 'format' — the rule every write path of
   * this module follows.
   */
  public function testOnlyValueIsWritten() {
    $node = $this->storedOffer();

    $this->assertSame(['value'], array_keys($node->field_offer_message[LANGUAGE_NONE][0]));
    $this->assertSame(['value'], array_keys($node->field_offer_includes[LANGUAGE_NONE][0]));
  }
}
