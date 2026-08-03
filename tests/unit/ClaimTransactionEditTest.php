<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.claim_transaction_admin.inc';
require_once __DIR__ . '/../../includes/myapi.building_admin.inc';

/**
 * Unit tests for editing a 'claim_transaction' from the claim timeline
 * (SPEC 59), whose pure logic lives in
 * includes/myapi.claim_transaction_admin.inc.
 *
 * Covers the two decidable pieces of the spec, both of them free of Drupal:
 *   - myapi_claim_transaction_transaction_form_alter()      — the FAPI walk
 *     that disables field_claim and appends the redirect submit handler;
 *   - myapi_claim_transaction_edit_form_submit_redirect()   — the redirect
 *     back to the claim, read from the saved node.
 *
 * Deliberately NOT tested here: myapi_claim_transaction_edit_link(). It is
 * node_load() + node_access() + l(), i.e. three Drupal calls and no branch of
 * its own — exactly what tests/unit avoids across this repo, and the same
 * reason BuildingAdminTest leaves myapi_node_access() out. Injecting a node
 * loader and an access checker would leave the test verifying its own mocks
 * instead of the behaviour, so the link's visibility rule is verified in the
 * manual acceptance matrix of the spec instead, and it is written down there
 * rather than skipped silently.
 *
 * The second require_once is not redundant with the first: the include under
 * test pulls its own dependencies with module_load_include(), which the
 * bootstrap stubs into a no-op, so myapi_building_admin_field_target_id() —
 * called from inside the redirect handler — has to be required here for real.
 * That is the design constraint tests/README.md already documents.
 *
 * Two tests here are guards against a future edit rather than checks of
 * today's behaviour: testEveryDeltaOfEveryLanguageIsDisabled() fails the
 * moment somebody disables only the first target_id — invisible on a
 * monolingual site — and testSubmitHandlerIsAppendedLast() fails if the
 * handler is ever prepended, which would run it before node_form_submit() has
 * saved the node and left $form_state['node'] behind.
 */
class ClaimTransactionEditTest extends TestCase {

  /**
   * Builds an entityreference_autocomplete widget for field_claim.
   *
   * The shape is the real one: langcode => delta => 'target_id' => textfield.
   * Unlike an options_select ('field_status', SPEC 57), the element that has
   * to be disabled is nested one level deeper than the delta.
   *
   * @param array $languages
   *   Langcode => list of deltas, each delta TRUE for a normal target_id
   *   element or FALSE for a delta built by some other widget.
   */
  private function claimWidget(array $languages) {
    $field = array('#theme' => 'field_multiple_value_form');

    foreach ($languages as $langcode => $deltas) {
      $field[$langcode] = array('#language' => $langcode);
      foreach ($deltas as $delta => $has_target_id) {
        $field[$langcode][$delta] = $has_target_id
          ? array('target_id' => array('#type' => 'textfield', '#default_value' => 'Reclamo (42)'))
          : array('value' => array('#type' => 'textfield'));
      }
    }

    return $field;
  }

  /**
   * Builds a node form in edit mode, with the field_claim widget.
   */
  private function editForm(array $languages = array('und' => array(0 => TRUE))) {
    $node = new stdClass();
    $node->nid = 101;
    $node->type = 'claim_transaction';

    return array(
      '#node' => $node,
      'field_claim' => $this->claimWidget($languages),
      '#submit' => array('node_form_submit'),
    );
  }

  /**
   * Builds a saved node as node_form_submit() leaves it in $form_state.
   *
   * @param array|null $items
   *   The stored field_claim value, or NULL for a node without the field.
   */
  private function savedNode($items) {
    $node = new stdClass();
    $node->nid = 101;
    $node->type = 'claim_transaction';
    if ($items !== NULL) {
      $node->field_claim = $items;
    }

    return $node;
  }

  /* -------------------------------------------------------------------------
   * The form alter — myapi_claim_transaction_transaction_form_alter().
   * ---------------------------------------------------------------------- */

  /**
   * The native title element as node_form() builds it, for the SPEC 60 cases.
   */
  private function titleElement() {
    return array(
      '#type' => 'textfield',
      '#title' => 'Título',
      '#required' => TRUE,
      '#default_value' => '',
    );
  }

  /**
   * node/add/claim_transaction: the native creation path is left untouched.
   *
   * This is the acceptance criterion of the spec turned into a test — nothing
   * disabled, no submit handler added, the form identical to what came in.
   *
   * The fixture carries no 'title' element on purpose: hiding it is the one
   * thing SPEC 60 added to this alter in creation mode, and it has its own
   * tests below. Everything ELSE still has to come out identical, which is what
   * this asserts.
   */
  public function testCreationModeLeavesTheFormUntouched() {
    $node = new stdClass();
    $node->type = 'claim_transaction';

    $form = array(
      '#node' => $node,
      'field_claim' => $this->claimWidget(array('und' => array(0 => TRUE))),
      '#submit' => array('node_form_submit'),
    );
    $before = $form;

    myapi_claim_transaction_transaction_form_alter($form, $form_state);

    $this->assertSame($before, $form);
  }

  /**
   * No '#node' at all: same result, and no PHP notice — empty() covers both
   * the missing key and the missing nid.
   */
  public function testFormWithoutNodeIsLeftUntouched() {
    $form = array(
      'field_claim' => $this->claimWidget(array('und' => array(0 => TRUE))),
      '#submit' => array('node_form_submit'),
    );
    $before = $form;

    myapi_claim_transaction_transaction_form_alter($form, $form_state);

    $this->assertSame($before, $form);
  }

  /* -------------------------------------------------------------------------
   * The native title field (SPEC 60).
   *
   * myapi_claim_transaction_set_title() regenerates the title on every save, so
   * whatever the operator typed would be overwritten. These four cases pin the
   * consequence: the field is hidden in BOTH modes — the only part of this
   * alter that runs above the edit-mode guard — and its #required goes with it,
   * or Drupal would refuse to save a form nobody can fill in.
   * ---------------------------------------------------------------------- */

  public function testCreationModeHidesTheTitleField() {
    $node = new stdClass();
    $node->type = 'claim_transaction';

    $form = array('#node' => $node, 'title' => $this->titleElement());

    myapi_claim_transaction_transaction_form_alter($form, $form_state);

    $this->assertFalse($form['title']['#access']);
    $this->assertFalse($form['title']['#required']);
  }

  public function testEditModeHidesTheTitleField() {
    $form = $this->editForm();
    $form['title'] = $this->titleElement();

    myapi_claim_transaction_transaction_form_alter($form, $form_state);

    $this->assertFalse($form['title']['#access']);
    $this->assertFalse($form['title']['#required']);
  }

  /**
   * A form with no title element at all (another module removed it, or a
   * bundle without has_title): no key is invented and nothing else breaks.
   */
  public function testMissingTitleElementIsNotCreated() {
    $form = $this->editForm();

    myapi_claim_transaction_transaction_form_alter($form, $form_state);

    $this->assertArrayNotHasKey('title', $form);
    $this->assertTrue($form['field_claim']['und'][0]['target_id']['#disabled']);
  }

  /**
   * Hiding the title does not touch anything else in the element — the label
   * and the default value survive, which is what lets Drupal resubmit the
   * stored title of an existing transaction while the field is inaccessible.
   */
  public function testHidingTheTitleKeepsTheRestOfTheElement() {
    $form = $this->editForm();
    $form['title'] = $this->titleElement();
    $form['title']['#default_value'] = 'Reclamo #128 · En proceso · 03/08/2026 14:30';

    myapi_claim_transaction_transaction_form_alter($form, $form_state);

    $this->assertSame('Reclamo #128 · En proceso · 03/08/2026 14:30', $form['title']['#default_value']);
    $this->assertSame('textfield', $form['title']['#type']);
  }

  /**
   * The ordinary case: one language, one delta.
   */
  public function testEditModeDisablesTheSingleTargetId() {
    $form = $this->editForm();

    myapi_claim_transaction_transaction_form_alter($form, $form_state);

    $this->assertTrue($form['field_claim']['und'][0]['target_id']['#disabled']);
    $this->assertSame(
      'myapi_claim_transaction_edit_form_submit_redirect',
      end($form['#submit'])
    );
  }

  /**
   * The Save button carries its own #submit list, and that is the one Drupal
   * actually runs.
   *
   * form_execute_handlers() uses the triggering element's '#submit' when it
   * has one and IGNORES the form-level list entirely; node_form() always gives
   * its Save button array('node_form_submit'). A handler appended only to
   * $form['#submit'] therefore never runs on a node form — which is why saving
   * used to land on Drupal's native node/<nid>. Appending to both lists is
   * safe: only one of them ever executes.
   */
  public function testSubmitHandlerIsAlsoAppendedToTheSaveButton() {
    $form = $this->editForm();
    $form['actions']['submit']['#submit'] = array('node_form_submit');

    myapi_claim_transaction_transaction_form_alter($form, $form_state);

    $this->assertSame(
      array('node_form_submit', 'myapi_claim_transaction_edit_form_submit_redirect'),
      $form['actions']['submit']['#submit']
    );
  }

  /**
   * A form whose Save button has no #submit of its own is left alone there —
   * the form-level list is what Drupal will run in that case.
   */
  public function testButtonWithoutSubmitListIsNotGivenOne() {
    $form = $this->editForm();
    $form['actions']['submit'] = array('#type' => 'submit', '#value' => 'Guardar');

    myapi_claim_transaction_transaction_form_alter($form, $form_state);

    $this->assertArrayNotHasKey('#submit', $form['actions']['submit']);
    $this->assertSame(
      'myapi_claim_transaction_edit_form_submit_redirect',
      end($form['#submit'])
    );
  }

  /**
   * The include is registered in $form_state so Drupal reloads it when the
   * form comes back from its cache.
   *
   * This is a regression guard, and the bug it guards against was seen live:
   * the node form has managed_file fields, so it IS cached, the POST does not
   * re-run hook_form_alter(), and the cached #submit pointed at a function
   * whose file had never been loaded — "Call to undefined function
   * myapi_claim_transaction_edit_form_submit_redirect()". Appending the
   * handler without recording its file is what caused it.
   */
  public function testEditModeRegistersTheIncludeForTheCachedForm() {
    $form = $this->editForm();
    $form_state = array();

    myapi_claim_transaction_transaction_form_alter($form, $form_state);

    $this->assertArrayHasKey(
      'myapi:includes/myapi.claim_transaction_admin.inc',
      $form_state['build_info']['files']
    );
  }

  /**
   * Nothing is registered on the creation path, where no handler is appended
   * either: the two go together.
   */
  public function testCreationModeRegistersNothing() {
    $node = new stdClass();
    $node->type = 'claim_transaction';

    $form = array('#node' => $node, '#submit' => array('node_form_submit'));
    $form_state = array();

    myapi_claim_transaction_transaction_form_alter($form, $form_state);

    $this->assertSame(array(), $form_state);
  }

  /**
   * Every delta of every language, not just the first one. A regression here
   * would pass unnoticed on a monolingual, cardinality-1 site.
   */
  public function testEveryDeltaOfEveryLanguageIsDisabled() {
    $form = $this->editForm(array(
      'und' => array(0 => TRUE, 1 => TRUE),
      'es' => array(0 => TRUE, 1 => TRUE, 2 => TRUE),
    ));

    myapi_claim_transaction_transaction_form_alter($form, $form_state);

    foreach (array('und' => 2, 'es' => 3) as $langcode => $count) {
      for ($delta = 0; $delta < $count; $delta++) {
        $this->assertTrue(
          $form['field_claim'][$langcode][$delta]['target_id']['#disabled'],
          sprintf('field_claim[%s][%d] should be disabled', $langcode, $delta)
        );
      }
    }
  }

  /**
   * A delta built by a different widget (no 'target_id'): the key is not
   * created out of thin air, and the walk keeps going for the rest.
   */
  public function testDeltaWithoutTargetIdIsSkippedWithoutBreakingTheWalk() {
    $form = $this->editForm(array('und' => array(0 => FALSE, 1 => TRUE)));

    myapi_claim_transaction_transaction_form_alter($form, $form_state);

    $this->assertArrayNotHasKey('target_id', $form['field_claim']['und'][0]);
    $this->assertTrue($form['field_claim']['und'][1]['target_id']['#disabled']);
  }

  /**
   * '#properties' among the children are not langcodes or deltas — which is
   * exactly what element_children() is there to guarantee.
   */
  public function testHashPropertiesAreNotWalkedAsLangcodesOrDeltas() {
    $form = $this->editForm();

    myapi_claim_transaction_transaction_form_alter($form, $form_state);

    $this->assertSame('field_multiple_value_form', $form['field_claim']['#theme']);
    $this->assertSame('und', $form['field_claim']['und']['#language']);
    $this->assertArrayNotHasKey('#disabled', $form['field_claim']['und']);
  }

  /**
   * No field_claim in the form (another module removed it, or field_access
   * hid it): nothing to disable, no warning — and the redirect handler is
   * still added, because it resolves the claim from the saved node, not from
   * the form.
   */
  public function testMissingFieldClaimStillAddsTheSubmitHandler() {
    $node = new stdClass();
    $node->nid = 101;

    $form = array('#node' => $node, '#submit' => array('node_form_submit'));

    myapi_claim_transaction_transaction_form_alter($form, $form_state);

    $this->assertSame(
      array('node_form_submit', 'myapi_claim_transaction_edit_form_submit_redirect'),
      $form['#submit']
    );
  }

  /**
   * Pre-existing handlers survive and the new one goes LAST: the order is
   * what guarantees it runs after node_form_submit() has saved the node.
   */
  public function testSubmitHandlerIsAppendedLast() {
    $form = $this->editForm();
    $form['#submit'] = array('node_form_submit', 'some_other_module_submit');

    myapi_claim_transaction_transaction_form_alter($form, $form_state);

    $this->assertSame(
      array(
        'node_form_submit',
        'some_other_module_submit',
        'myapi_claim_transaction_edit_form_submit_redirect',
      ),
      $form['#submit']
    );
  }

  /**
   * Called twice on the same form. Drupal never invokes the same
   * hook_form_alter() twice, so this documents today's behaviour rather than
   * requiring it: the handler is listed twice and the redirect it produces is
   * idempotent anyway.
   */
  public function testCallingItTwiceRepeatsTheHandler() {
    $form = $this->editForm();

    myapi_claim_transaction_transaction_form_alter($form, $form_state);
    myapi_claim_transaction_transaction_form_alter($form, $form_state);

    $this->assertSame(
      array(
        'node_form_submit',
        'myapi_claim_transaction_edit_form_submit_redirect',
        'myapi_claim_transaction_edit_form_submit_redirect',
      ),
      $form['#submit']
    );
    $this->assertTrue($form['field_claim']['und'][0]['target_id']['#disabled']);
  }

  /* -------------------------------------------------------------------------
   * The redirect — myapi_claim_transaction_edit_form_submit_redirect().
   * ---------------------------------------------------------------------- */

  /**
   * The ordinary case: back to the claim's edit form, not to node/<nid>.
   */
  public function testRedirectsToTheClaimEditForm() {
    $form_state = array(
      'node' => $this->savedNode(array('und' => array(array('target_id' => 42)))),
    );

    myapi_claim_transaction_edit_form_submit_redirect(array(), $form_state);

    $this->assertSame('node/42/edit', $form_state['redirect']);
  }

  /**
   * Multi-delta field_claim: the first target_id wins, which is the semantics
   * of myapi_building_admin_field_target_id().
   */
  public function testMultiDeltaFieldClaimTakesTheFirstTarget() {
    $form_state = array(
      'node' => $this->savedNode(array(
        'und' => array(
          array('target_id' => 42),
          array('target_id' => 77),
        ),
      )),
    );

    myapi_claim_transaction_edit_form_submit_redirect(array(), $form_state);

    $this->assertSame('node/42/edit', $form_state['redirect']);
  }

  /**
   * field_claim present but empty (the corrupt-data case): Drupal falls back
   * to its native destination, and nothing here errors.
   */
  public function testEmptyFieldClaimLeavesNoRedirect() {
    $form_state = array('node' => $this->savedNode(array()));

    myapi_claim_transaction_edit_form_submit_redirect(array(), $form_state);

    $this->assertArrayNotHasKey('redirect', $form_state);
  }

  /**
   * field_claim missing from the node altogether: same, no error.
   */
  public function testMissingFieldClaimLeavesNoRedirect() {
    $form_state = array('node' => $this->savedNode(NULL));

    myapi_claim_transaction_edit_form_submit_redirect(array(), $form_state);

    $this->assertArrayNotHasKey('redirect', $form_state);
  }

  /**
   * A redirect set by an earlier submit handler survives when field_claim
   * does not resolve: "never overwritten" is a stronger promise than "never
   * set".
   */
  public function testExistingRedirectIsNotOverwrittenWhenTheClaimIsUnresolved() {
    $form_state = array(
      'node' => $this->savedNode(array()),
      'redirect' => 'admin/content',
    );

    myapi_claim_transaction_edit_form_submit_redirect(array(), $form_state);

    $this->assertSame('admin/content', $form_state['redirect']);
  }

  /**
   * No node in $form_state at all: no notice, no redirect. The handler runs
   * at the end of a chain another module could have altered.
   */
  public function testMissingNodeInFormStateIsHarmless() {
    $form_state = array();

    myapi_claim_transaction_edit_form_submit_redirect(array(), $form_state);

    $this->assertArrayNotHasKey('redirect', $form_state);
  }

  /**
   * A non-object where the node should be: same, guarded by the is_object()
   * check myapi_building_admin_field_target_ids() already makes.
   */
  public function testNonObjectNodeInFormStateIsHarmless() {
    $form_state = array('node' => 'not a node');

    myapi_claim_transaction_edit_form_submit_redirect(array(), $form_state);

    $this->assertArrayNotHasKey('redirect', $form_state);
  }

}
