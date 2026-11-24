<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../myapi.module';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.provider_role.inc';
require_once __DIR__ . '/../../includes/myapi.reservation_form.inc';
require_once __DIR__ . '/../../includes/myapi.provider_account.inc';

/**
 * Unit tests for includes/myapi.provider_account.inc: "Usuarios asociados" on
 * the back-office 'provider' node form — the suggestion label, the form alter
 * on a hand-built form array, the autocomplete route and its access callback
 * (which lives in myapi.module).
 *
 * The db_select() stub does not evaluate JOIN conditions, so "only accounts
 * with the role" is asserted on the recorded query, not on the rows.
 */
class ProviderAccountFormTest extends TestCase {

  protected function setUp(): void {
    $this->reset();
  }

  protected function tearDown(): void {
    $this->reset();
  }

  private function reset() {
    myapi_test_db_seed();
    $GLOBALS['myapi_test_form_errors'] = [];
    $GLOBALS['myapi_test_permissions'] = [];
  }

  /* -------------------------------------------------------------------------
   * Pure half.
   * ---------------------------------------------------------------------- */

  public function testTheSuggestionLabel() {
    $this->assertSame('Ana Díaz (adiaz) — ana@example.com', myapi_provider_account_suggestion_label('adiaz', 'Ana', 'Díaz', 'ana@example.com'));
    $this->assertSame('adiaz — ana@example.com', myapi_provider_account_suggestion_label('adiaz', NULL, NULL, 'ana@example.com'));
    $this->assertSame('Ana (adiaz)', myapi_provider_account_suggestion_label('adiaz', 'Ana', '', NULL));
  }

  /* -------------------------------------------------------------------------
   * The form alter.
   * ---------------------------------------------------------------------- */

  private function form($nid = NULL, $deltas = 1) {
    $widget = ['#required' => TRUE, '#description' => 'Cuentas que operan este proveedor desde la app.'];
    for ($delta = 0; $delta < $deltas; $delta++) {
      $widget[$delta] = [
        'target_id' => [
          '#type'              => 'textfield',
          '#required'          => $delta === 0,
          '#autocomplete_path' => 'entityreference/autocomplete/single/field_provider_users/node/provider/NULL',
        ],
      ];
    }
    $widget['add_more'] = ['#type' => 'submit'];

    return [
      '#node'                => (object) ['nid' => $nid, 'type' => 'provider'],
      'field_provider_users' => ['#language' => 'und', '#weight' => 3, 'und' => $widget],
      'actions'              => ['submit' => ['#submit' => ['node_form_submit']]],
    ];
  }

  public function testEveryRowAsksTheRoleFilteredAutocomplete() {
    $form = $this->form(NULL, 2);
    $form_state = [];
    myapi_provider_account_form_alter($form, $form_state);

    foreach ([0, 1] as $delta) {
      $this->assertSame('admin/content/providers/user-autocomplete', $form['field_provider_users']['und'][$delta]['target_id']['#autocomplete_path']);
    }
    $this->assertTrue($form['field_provider_users']['und']['#required'], 'the field stays required');
    $this->assertTrue($form['field_provider_users']['und'][0]['target_id']['#required']);
    $this->assertArrayNotHasKey('target_id', $form['field_provider_users']['und']['add_more']);
    $this->assertSame(['node_form_submit'], $form['actions']['submit']['#submit']);
    $this->assertSame([], $form_state);
  }

  public function testTheHelpTextLinksToUserCreationOnlyForWhoMayUseIt() {
    $without = $this->form();
    $with = $this->form();
    $form_state = [];

    myapi_provider_account_form_alter($without, $form_state);
    $GLOBALS['myapi_test_permissions'] = ['administer users' => TRUE];
    myapi_provider_account_form_alter($with, $form_state);

    $this->assertStringNotContainsString('<a ', $without['field_provider_users']['und']['#description']);
    $this->assertStringContainsString('rol proveedor', $without['field_provider_users']['und']['#description']);
    $this->assertStringContainsString('href="/admin/people/create"', $with['field_provider_users']['und']['#description']);
  }

  public function testAnotherWidgetLeavesTheFormAlone() {
    $form = $this->form();
    unset($form['field_provider_users']['und'][0]['target_id']['#autocomplete_path']);
    $before = $form;
    $form_state = [];

    myapi_provider_account_form_alter($form, $form_state);

    $this->assertSame($before, $form);
  }

  /* -------------------------------------------------------------------------
   * The route.
   * ---------------------------------------------------------------------- */

  private function ask(...$arguments) {
    return myapi_test_capture(function () use ($arguments) {
      myapi_provider_account_autocomplete(...$arguments);
    });
  }

  public function testAnEmptySearchAnswersAnEmptyObjectWithoutAQuery() {
    foreach ([[], ['  ']] as $arguments) {
      $result = $this->ask(...$arguments);

      $this->assertTrue($result['exited']);
      $this->assertSame(200, $result['status']);
      $this->assertSame('{}', $result['output']);
    }
    $this->assertSame([], myapi_test_db_queries());
  }

  public function testSuggestionsCarryNameAndMailAndAreKeyedByUsername() {
    myapi_test_db_seed([
      'users' => [
        // 'r.name' under its qualified key: the stub would otherwise compare
        // the role condition against the user's own 'name' column.
        ['uid' => 31, 'name' => 'adiaz', 'r.name' => 'proveedor', 'mail' => 'ana@example.com', 'status' => 1, 'first_name' => 'Ana', 'last_name' => '<b>Díaz</b>'],
        ['uid' => 32, 'name' => 'blocked.ana', 'r.name' => 'proveedor', 'mail' => 'b@example.com', 'status' => 0, 'first_name' => 'Ana', 'last_name' => 'B'],
      ],
    ]);

    $result = $this->ask('ana');

    $this->assertSame(
      ['adiaz (31)' => '<div class="reference-autocomplete">Ana &lt;b&gt;Díaz&lt;/b&gt; (adiaz) — ana@example.com</div>'],
      $result['json']
    );
  }

  public function testTheQueryIsNarrowedToActiveAccountsWithTheRole() {
    $this->ask('ana');

    $queries = myapi_test_db_queries('users');
    $this->assertCount(1, $queries);

    $dump = var_export($queries[0], TRUE);
    $this->assertStringContainsString('users_roles', $dump);
    $this->assertStringContainsString('r.name', $dump);
    $this->assertStringContainsString("'proveedor'", $dump);
    $this->assertStringContainsString('u.status', $dump);
    $this->assertStringContainsString('u.mail', $dump);
    $this->assertStringContainsString('%ana%', $dump);
  }

  public function testTheRouteIsOpenToWhoeverMayWriteProviders() {
    $this->assertFalse(myapi_provider_account_access());

    foreach (['create provider content', 'edit any provider content', 'edit own provider content'] as $permission) {
      $GLOBALS['myapi_test_permissions'] = [$permission => TRUE];
      $this->assertTrue(myapi_provider_account_access(), $permission);
    }
  }

  public function testTheRouteIsRegistered() {
    $items = myapi_menu();

    $this->assertSame('myapi_provider_account_autocomplete', $items['admin/content/providers/user-autocomplete']['page callback']);
    $this->assertSame('myapi_provider_account_access', $items['admin/content/providers/user-autocomplete']['access callback']);
    $this->assertSame('includes/myapi.provider_account.inc', $items['admin/content/providers/user-autocomplete']['file']);
  }

}
