<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.provider_query.inc';
require_once __DIR__ . '/../../includes/myapi.provider_role.inc';
require_once __DIR__ . '/../../includes/myapi.notification.inc';
require_once __DIR__ . '/../../includes/myapi.mail_queue.inc';
require_once __DIR__ . '/../../includes/myapi.user.inc';
require_once __DIR__ . '/../../includes/myapi.mail.inc';
require_once __DIR__ . '/../../includes/myapi.service_request_notification.inc';

/**
 * Unit tests for the notifications a new service request fires (SPEC 109).
 *
 * Three layers, and the boundary between them is what this file is about:
 *
 * WHAT RUNS PURE: the push title and body, the params of both emails and the
 * two formatters. They are where a mail key stops being a string and becomes a
 * subject, a set of labelled lines and (or not) a button, so they are asserted
 * character by character.
 *
 * WHAT RUNS OVER THE FIXTURE: the two recipient resolvers. They are the half of
 * the spec a reader cannot check by reading — "an expired licence receives
 * nothing" is a promise about something that does NOT happen — so each
 * exclusion gets a row in the fixture that would be returned if the condition
 * were dropped.
 *
 * WHAT DOES NOT RUN: the fan-out itself. myapi_notification_create() inserts,
 * and db_insert() throws in tests/unit by design (SPEC 75), so what is asserted
 * about myapi_service_request_notify_created() is the audience decision it
 * makes BEFORE inserting — visible in the queries it records — plus the promise
 * that it swallows the failure instead of propagating it, which is the whole
 * reason the endpoint can call it without guarding the 201.
 */
class ServiceRequestNotificationTest extends TestCase {

  const NID = 1420;
  const CATEGORY = 12;
  const OTHER_CATEGORY = 13;
  const CONDO = 87;
  const UNIT = 55;
  const PROVIDER = 553;
  const OTHER_PROVIDER = 554;
  const REQUESTER = 42;

  /**
   * A fixed instant, so the formatted date is assertable to the minute. Built
   * with mktime() in the same timezone format_date()'s stub reads.
   */
  private function startTime() {
    return mktime(9, 30, 0, 9, 3, 2026);
  }

  protected function setUp(): void {
    myapi_test_db_seed();
    $GLOBALS['myapi_test_watchdog'] = [];
  }

  /**
   * Extracts the label => value pairs of a mail's data table, in display order.
   *
   * Anchored on the label cell's own colour, the same reader
   * PaymentAdminMailTest and ReservationAdminMailTest use, because the three
   * emails share the HTML shell.
   */
  private function lines($html) {
    preg_match_all('~<tr><td style="[^"]*color:#907050[^"]*">(.*?)</td><td[^>]*>(.*?)</td></tr>~s', $html, $matches);

    return array_combine($matches[1], $matches[2]);
  }

  /* -------------------------------------------------------------------------
   * Push title and body (step 5).
   * ---------------------------------------------------------------------- */

  public function testThePushTitleOfAnOpenRequestIsTheGenericOne() {
    $this->assertSame('Nueva solicitud de servicio', myapi_service_request_push_title(FALSE));
  }

  /**
   * A direct request is already the provider's, and the title is the only thing
   * they read before deciding to open the app.
   */
  public function testThePushTitleOfADirectRequestSaysItIsForThem() {
    $this->assertSame('Nueva solicitud directa para ti', myapi_service_request_push_title(TRUE));
  }

  public function testThePushBodyCarriesTheFourLabelledLines() {
    $body = myapi_service_request_push_body(
      'Fuga en el calentador',
      'Plomería',
      'Los Robles',
      myapi_service_request_date_label($this->startTime())
    );

    $this->assertSame(
      "Fuga en el calentador\nCategoría: Plomería\nCondominio: Los Robles\nInicio: 03/09/2026 09:30",
      $body
    );
  }

  /**
   * The body is the same for both cases: what changes between an open and a
   * direct request is who is told, not what they are told.
   */
  public function testTheBodyDoesNotDependOnTheKindOfRequest() {
    $arguments = ['Fuga', 'Plomería', 'Los Robles', '03/09/2026 09:30'];

    $this->assertSame(
      call_user_func_array('myapi_service_request_push_body', $arguments),
      call_user_func_array('myapi_service_request_push_body', $arguments)
    );
  }

  /**
   * A deleted category or a condominium with no title costs a dash, never an
   * empty line and never an error.
   */
  public function testAnUnresolvableValueIsDrawnAsADash() {
    $body = myapi_service_request_push_body('Fuga', NULL, '', myapi_service_request_date_label(NULL));

    $this->assertSame("Fuga\nCategoría: —\nCondominio: —\nInicio: —", $body);
  }

  public function testTheDateLabelOfAnEmptyFieldIsADash() {
    $this->assertSame('—', myapi_service_request_date_label(NULL));
    $this->assertSame('—', myapi_service_request_date_label(0));
  }

  /**
   * The provider learns nothing about the home or the person behind the
   * request until they open the detail endpoint.
   */
  public function testThePushBodyNamesNeitherTheUnitNorTheRequester() {
    $body = myapi_service_request_push_body('Fuga', 'Plomería', 'Los Robles', '03/09/2026 09:30');

    $this->assertStringNotContainsString('Casa 12', $body);
    $this->assertStringNotContainsString('Ana', $body);
    $this->assertStringNotContainsString('Vivienda', $body);
    $this->assertStringNotContainsString('Solicitante', $body);
  }

  /* -------------------------------------------------------------------------
   * The provider email (steps 5 and 6).
   * ---------------------------------------------------------------------- */

  private function providerParams($extra = []) {
    return myapi_service_request_provider_mail_params(self::NID, $extra + [
      'title'         => 'Fuga en el calentador',
      'category'      => 'Plomería',
      'desired_start' => '03/09/2026 09:30',
      'condominium'   => 'Los Robles',
      'is_direct'     => FALSE,
    ]);
  }

  public function testTheProviderParamsCarryTheFourValuesEscaped() {
    $params = $this->providerParams(['title' => 'Fuga en el baño A&B']);

    $this->assertSame(self::NID, $params['nid']);
    $this->assertSame('Fuga en el baño A&amp;B', $params['title']);
    $this->assertSame('Plomería', $params['category']);
    $this->assertSame('03/09/2026 09:30', $params['desired_start']);
    $this->assertSame('Los Robles', $params['condominium']);
    $this->assertFalse($params['is_direct']);
  }

  public function testTheProviderParamsFallBackToTheDash() {
    $params = $this->providerParams(['category' => NULL, 'condominium' => '']);

    $this->assertSame('—', $params['category']);
    $this->assertSame('—', $params['condominium']);
  }

  public function testTheSubjectOfTheOpenProviderMail() {
    $message = ['body' => [], 'headers' => []];

    myapi_mail_format_service_request_provider($message, $this->providerParams());

    $this->assertSame('Nueva solicitud de servicio — Fuga en el calentador', $message['subject']);
  }

  public function testTheSubjectOfTheDirectProviderMail() {
    $message = ['body' => [], 'headers' => []];

    myapi_mail_format_service_request_provider($message, $this->providerParams(['is_direct' => TRUE]));

    $this->assertSame('Nueva solicitud directa — Fuga en el calentador', $message['subject']);
  }

  /**
   * A subject is plain text, so the escaped title is decoded back: a request
   * titled 'A&B' must not read 'A&amp;B' in the inbox list.
   */
  public function testTheProviderSubjectDecodesTheEscapedTitle() {
    $message = ['body' => [], 'headers' => []];

    myapi_mail_format_service_request_provider($message, $this->providerParams(['title' => 'Fuga A&B']));

    $this->assertSame('Nueva solicitud de servicio — Fuga A&B', $message['subject']);
  }

  public function testTheProviderMailDrawsExactlyFourLines() {
    $html = myapi_mail_service_request_provider_html($this->providerParams());

    $this->assertSame(
      [
        'Asunto'          => 'Fuga en el calentador',
        'Categoría'       => 'Plomería',
        'Fecha de inicio' => '03/09/2026 09:30',
        'Condominio'      => 'Los Robles',
      ],
      $this->lines($html)
    );
  }

  /**
   * No button, unlike every other admin-facing email of this module: a provider
   * has no back office to land on.
   */
  public function testTheProviderMailHasNoButton() {
    $html = myapi_mail_service_request_provider_html($this->providerParams());

    $this->assertStringNotContainsString('border-radius:10px;background-color:#4A3326', $html);
    $this->assertStringContainsString('Revisa la solicitud en la app.', $html);
  }

  public function testTheProviderMailNamesNeitherTheUnitNorTheRequester() {
    $html = myapi_mail_service_request_provider_html($this->providerParams());

    $this->assertStringNotContainsString('Vivienda', $html);
    $this->assertStringNotContainsString('Solicitante', $html);
    $this->assertStringNotContainsString('Descripción', $html);
  }

  /* -------------------------------------------------------------------------
   * The back office email (steps 5 and 6).
   * ---------------------------------------------------------------------- */

  private function adminParams($extra = []) {
    return myapi_service_request_admin_mail_params(self::NID, $extra + [
      'title'           => 'Fuga en el calentador',
      'provider_name'   => NULL,
      'category'        => 'Plomería',
      'desired_start'   => '03/09/2026 09:30',
      'unit'            => 'Casa 12',
      'condominium'     => 'Los Robles',
      'requester'       => 'Ana Pérez',
      'requester_email' => 'ana@example.com',
      'description'     => 'El calentador gotea desde el lunes.',
      'created'         => '28/08/2026 10:15',
      'is_direct'       => FALSE,
    ]);
  }

  public function testTheTypeOfAnOpenRequestIsAbierta() {
    $this->assertSame('Abierta', $this->adminParams()['type']);
  }

  public function testTheTypeOfADirectRequestNamesTheProvider() {
    $params = $this->adminParams(['is_direct' => TRUE, 'provider_name' => 'Plomería Sur']);

    $this->assertSame('Directa a Plomería Sur', $params['type']);
  }

  /**
   * A direct request whose provider was deleted in between still reads as
   * direct: that it is direct is what the operator triages by.
   */
  public function testADirectRequestWithNoResolvableProviderStillReadsAsDirect() {
    $params = $this->adminParams(['is_direct' => TRUE, 'provider_name' => NULL]);

    $this->assertSame('Directa a —', $params['type']);
  }

  public function testTheAdminSubjectCarriesTheNidAndTheCondominium() {
    $message = ['body' => [], 'headers' => []];

    myapi_mail_format_service_request_admin($message, $this->adminParams());

    $this->assertSame('Nueva solicitud de servicio #1420 — Los Robles', $message['subject']);
  }

  public function testTheAdminMailDrawsTheTenLinesInOrder() {
    $html = myapi_mail_service_request_admin_html($this->adminParams());

    $this->assertSame(
      [
        'Asunto'                => 'Fuga en el calentador',
        'Tipo'                  => 'Abierta',
        'Categoría'             => 'Plomería',
        'Fecha de inicio'       => '03/09/2026 09:30',
        'Vivienda'              => 'Casa 12',
        'Condominio'            => 'Los Robles',
        'Solicitante'           => 'Ana Pérez',
        'Email del solicitante' => 'ana@example.com',
        'Descripción'           => 'El calentador gotea desde el lunes.',
        'Creada el'             => '28/08/2026 10:15',
      ],
      $this->lines($html)
    );
  }

  public function testTheAdminButtonOpensTheNode() {
    $html = myapi_mail_service_request_admin_html($this->adminParams());

    $this->assertStringContainsString('>Ver solicitud</a>', $html);
    $this->assertStringContainsString('href="https://crespcord.example.com/node/1420"', $html);
  }

  public function testAnUnresolvableAdminValueIsDrawnAsADash() {
    $params = $this->adminParams(['unit' => NULL, 'requester_email' => '', 'description' => NULL]);
    $lines = $this->lines(myapi_mail_service_request_admin_html($params));

    $this->assertSame('—', $lines['Vivienda']);
    $this->assertSame('—', $lines['Email del solicitante']);
    $this->assertSame('—', $lines['Descripción']);
  }

  /* -------------------------------------------------------------------------
   * Recipient resolution over the fixture (step 4).
   *
   * The fixture rows are the rows the JOINs would produce — the builder records
   * joins and never resolves them — so a provider is one flat row per category
   * delta, carrying its licence column alongside its node columns.
   * ---------------------------------------------------------------------- */

  private function providerRow($extra = []) {
    return $extra + [
      'nid'                        => self::PROVIDER,
      'title'                      => 'Plomería Sur',
      'type'                       => MYAPI_SERVICES_PROVIDER_TYPE,
      'status'                     => 1,
      'field_categories_tid'       => self::CATEGORY,
      'field_license_expiry_value' => REQUEST_TIME + 86400,
    ];
  }

  public function testAnActiveProviderOfTheCategoryIsReturnedKeyedByNid() {
    myapi_test_db_seed(['node' => [$this->providerRow()]]);

    $this->assertSame(
      [self::PROVIDER => 'Plomería Sur'],
      myapi_service_request_active_providers_for_category(self::CATEGORY)
    );
  }

  public function testAnUnpublishedProviderIsNotReturned() {
    myapi_test_db_seed(['node' => [$this->providerRow(['status' => 0])]]);

    $this->assertSame([], myapi_service_request_active_providers_for_category(self::CATEGORY));
  }

  /**
   * The boundary of the licence is >=, the same one the PHP half of the rule
   * draws: valid throughout its expiry timestamp and not one second less.
   */
  public function testAProviderWithAnExpiredLicenceIsNotReturned() {
    myapi_test_db_seed(['node' => [
      $this->providerRow(['field_license_expiry_value' => REQUEST_TIME - 1]),
    ]]);

    $this->assertSame([], myapi_service_request_active_providers_for_category(self::CATEGORY));
  }

  public function testALicenceExpiringThisVerySecondIsStillValid() {
    myapi_test_db_seed(['node' => [
      $this->providerRow(['field_license_expiry_value' => REQUEST_TIME]),
    ]]);

    $this->assertArrayHasKey(
      self::PROVIDER,
      myapi_service_request_active_providers_for_category(self::CATEGORY)
    );
  }

  public function testAProviderOfAnotherCategoryIsNotReturned() {
    myapi_test_db_seed(['node' => [
      $this->providerRow(['field_categories_tid' => self::OTHER_CATEGORY]),
    ]]);

    $this->assertSame([], myapi_service_request_active_providers_for_category(self::CATEGORY));
  }

  public function testANodeOfAnotherBundleIsNotReturned() {
    myapi_test_db_seed(['node' => [$this->providerRow(['type' => 'condominio'])]]);

    $this->assertSame([], myapi_service_request_active_providers_for_category(self::CATEGORY));
  }

  /**
   * field_categories has unlimited cardinality, so the same provider can arrive
   * as two rows. Keying by nid is what makes it ONE notification and not two.
   */
  public function testAProviderCarryingTheCategoryTwiceIsReturnedOnce() {
    myapi_test_db_seed(['node' => [$this->providerRow(), $this->providerRow()]]);

    $this->assertCount(1, myapi_service_request_active_providers_for_category(self::CATEGORY));
  }

  public function testEveryActiveProviderOfTheCategoryIsReturned() {
    myapi_test_db_seed(['node' => [
      $this->providerRow(),
      $this->providerRow(['nid' => self::OTHER_PROVIDER, 'title' => 'Electro Norte']),
    ]]);

    $this->assertSame(
      [self::PROVIDER => 'Plomería Sur', self::OTHER_PROVIDER => 'Electro Norte'],
      myapi_service_request_active_providers_for_category(self::CATEGORY)
    );
  }

  public function testAnInvalidCategoryResolvesToNobodyWithoutQuerying() {
    myapi_test_db_seed(['node' => [$this->providerRow()]]);

    $this->assertSame([], myapi_service_request_active_providers_for_category(0));
    $this->assertSame([], myapi_service_request_active_providers_for_category(NULL));
    $this->assertSame([], myapi_test_db_queries());
  }

  /* -- The accounts of a provider ----------------------------------------- */

  private function accountRow($extra = []) {
    return $extra + [
      'entity_type' => 'node',
      'entity_id'   => self::PROVIDER,
      'deleted'     => 0,
      'uid'         => 7,
      'status'      => 1,
    ];
  }

  public function testTheActiveAccountsOfAProviderAreReturned() {
    myapi_test_db_seed(['field_data_' . MYAPI_PROVIDER_USERS_FIELD => [
      $this->accountRow(),
      $this->accountRow(['uid' => 8]),
    ]]);

    $this->assertSame([7, 8], myapi_service_request_provider_uids(self::PROVIDER));
  }

  public function testABlockedAccountIsNotReturned() {
    myapi_test_db_seed(['field_data_' . MYAPI_PROVIDER_USERS_FIELD => [
      $this->accountRow(['status' => 0]),
    ]]);

    $this->assertSame([], myapi_service_request_provider_uids(self::PROVIDER));
  }

  public function testAnAccountOfAnotherProviderIsNotReturned() {
    myapi_test_db_seed(['field_data_' . MYAPI_PROVIDER_USERS_FIELD => [
      $this->accountRow(['entity_id' => self::OTHER_PROVIDER]),
    ]]);

    $this->assertSame([], myapi_service_request_provider_uids(self::PROVIDER));
  }

  public function testADeletedFieldRowIsNotReturned() {
    myapi_test_db_seed(['field_data_' . MYAPI_PROVIDER_USERS_FIELD => [
      $this->accountRow(['deleted' => 1]),
    ]]);

    $this->assertSame([], myapi_service_request_provider_uids(self::PROVIDER));
  }

  public function testTheAnonymousUserIsNotReturned() {
    myapi_test_db_seed(['field_data_' . MYAPI_PROVIDER_USERS_FIELD => [
      $this->accountRow(['uid' => 0]),
    ]]);

    $this->assertSame([], myapi_service_request_provider_uids(self::PROVIDER));
  }

  /**
   * The same account listed twice on the field is one recipient, not two: the
   * duplication is a data accident, unlike the two-providers case, which is a
   * documented consequence and DOES produce two notices.
   */
  public function testAnAccountListedTwiceIsReturnedOnce() {
    myapi_test_db_seed(['field_data_' . MYAPI_PROVIDER_USERS_FIELD => [
      $this->accountRow(),
      $this->accountRow(),
    ]]);

    $this->assertSame([7], myapi_service_request_provider_uids(self::PROVIDER));
  }

  public function testAProviderWithNoAccountsResolvesToNobody() {
    myapi_test_db_seed();

    $this->assertSame([], myapi_service_request_provider_uids(self::PROVIDER));
  }

  /* -------------------------------------------------------------------------
   * The audience decision of the orchestrator (step 7).
   *
   * Asserted through the queries it records, because the fan-out itself cannot
   * run here: myapi_notification_create() inserts, and db_insert() throws in
   * tests/unit by design.
   * ---------------------------------------------------------------------- */

  private function requestNode() {
    $node = (object) [
      'nid'     => self::NID,
      'title'   => 'Fuga en el calentador',
      'created' => REQUEST_TIME,
    ];
    $node->field_desired_start[LANGUAGE_NONE][0]['value'] = $this->startTime();
    $node->field_description[LANGUAGE_NONE][0]['value'] = 'Gotea desde el lunes.';

    return $node;
  }

  private function notify($assigned_provider_id = NULL) {
    myapi_service_request_notify_created($this->requestNode(), [
      'unit_id'              => self::UNIT,
      'condominium_id'       => self::CONDO,
      'category_id'          => self::CATEGORY,
      'assigned_provider_id' => $assigned_provider_id,
      'requester_uid'        => self::REQUESTER,
    ]);
  }

  private function queriedTables() {
    return array_column(myapi_test_db_queries(), 'table');
  }

  /**
   * An open request asks the category who serves it.
   */
  public function testAnOpenRequestLooksUpTheProvidersOfItsCategory() {
    myapi_test_db_seed(['node' => [$this->providerRow()]]);

    $this->notify();

    $categories = array_filter(myapi_test_db_queries(), function ($query) {
      foreach ($query['joins'] as $join) {
        if ($join['table'] === 'field_data_field_categories') {
          return TRUE;
        }
      }
      return FALSE;
    });

    $this->assertNotEmpty($categories);
  }

  /**
   * A direct request never asks: its audience is the awarded provider and
   * nobody else, and the provider was already validated by SPEC 90 two steps
   * earlier in this same request.
   */
  public function testADirectRequestNeverLooksUpTheCategory() {
    myapi_test_db_seed(['node' => [$this->providerRow()]]);

    $this->notify(self::PROVIDER);

    foreach (myapi_test_db_queries() as $query) {
      foreach ($query['joins'] as $join) {
        $this->assertNotSame('field_data_field_categories', $join['table']);
      }
    }
  }

  /**
   * A category nobody serves is a no-op and not an error: no notification, no
   * mail, nothing in watchdog, and the 201 of the endpoint unchanged.
   */
  public function testACategoryWithNoActiveProviderNotifiesNobody() {
    myapi_test_db_seed(['node' => [$this->providerRow(['status' => 0])]]);

    $this->notify();

    $this->assertNotContains('myapi_notifications', $this->queriedTables());
    $this->assertSame([], $GLOBALS['myapi_test_watchdog']);
  }

  /**
   * THE PROMISE THE ENDPOINT RELIES ON. The fan-out fails here on purpose —
   * db_insert() throws in tests/unit — and the trigger still returns normally,
   * with the failure in watchdog. Without this, a broken queue would take the
   * 201 (and the request the resident just created) down with it.
   */
  public function testAFailingFanOutIsLoggedAndNeverPropagates() {
    myapi_test_db_seed([
      'node' => [$this->providerRow()],
      'field_data_' . MYAPI_PROVIDER_USERS_FIELD => [$this->accountRow()],
    ]);

    $this->notify();

    $this->assertNotSame([], $GLOBALS['myapi_test_watchdog']);
    $this->assertStringContainsString('myapi_notifications', $GLOBALS['myapi_test_watchdog'][0]['text']);
  }

}
