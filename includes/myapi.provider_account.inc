<?php

/**
 * @file
 * "Usuarios asociados" on the back-office 'provider' node form.
 *
 * field_provider_users (SPEC 77) used to be the stock entityreference
 * autocomplete: it searched every account of the site by user name only.
 *
 * - The autocomplete of the field is pointed at this module's own route, which
 *   only offers ACTIVE accounts holding the 'proveedor' role, matches on user
 *   name, e-mail, first name and last name, and shows
 *   "First Last (username) — mail" instead of a bare user name.
 * - The field's help text links to admin/people/create, in a new tab so the
 *   provider form being filled is not lost, for the account that does not
 *   exist yet. Accounts are NOT created from this form.
 *
 * WHAT IT DOES NOT DO.
 * - The role filter is on the SUGGESTIONS, not on the save: a reference to an
 *   account without the role that is already stored, or typed by hand as
 *   'name (uid)', is still accepted by entityreference's own validation.
 *   Enforcing it would lock the edit form of any provider saved before this
 *   file existed.
 * - The api/v1 endpoints are untouched: none of them reaches a node form.
 *
 * Pure helper first (exercised by tests/unit/ProviderAccountFormTest.php with
 * no site booted), Drupal-facing helpers after.
 */

/* ---------------------------------------------------------------------------
 * Pure helper.
 * ------------------------------------------------------------------------ */

/**
 * The text of one suggestion: "First Last (username) — mail".
 *
 * Falls back to the bare user name when the profile has no name, and drops
 * the e-mail part when the account has none.
 *
 * @param string $name
 *   The user name.
 * @param string|null $first_name
 * @param string|null $last_name
 * @param string|null $mail
 *
 * @return string
 *   Raw text; the caller escapes it.
 */
function myapi_provider_account_suggestion_label($name, $first_name, $last_name, $mail) {
  $full_name = trim(trim((string) $first_name) . ' ' . trim((string) $last_name));
  $label = $full_name !== '' ? $full_name . ' (' . $name . ')' : (string) $name;

  $mail = trim((string) $mail);
  if ($mail !== '') {
    $label .= ' — ' . $mail;
  }

  return $label;
}

/* ---------------------------------------------------------------------------
 * Drupal-facing helpers.
 * ------------------------------------------------------------------------ */

/**
 * The route the field's autocomplete asks.
 *
 * @return string
 */
function myapi_provider_account_autocomplete_path() {
  return 'admin/content/providers/user-autocomplete';
}

/**
 * Language code the field is rendered under on a form, or NULL.
 *
 * NULL when the field is not the entityreference autocomplete this file was
 * written for — removed from the form, or switched to another widget from the
 * field UI.
 *
 * @param array $form
 *   The node form.
 *
 * @return string|null
 */
function myapi_provider_account_field_langcode(array $form) {
  $field = MYAPI_PROVIDER_USERS_FIELD;

  if (empty($form[$field]['#language'])) {
    return NULL;
  }

  $langcode = $form[$field]['#language'];

  return isset($form[$field][$langcode][0]['target_id']['#autocomplete_path']) ? $langcode : NULL;
}

/**
 * Shapes "Usuarios asociados" on the 'provider' node form.
 *
 * Called from myapi_form_provider_node_form_alter(), on create and on edit.
 * Drupal runs form alters again on every rebuild, so the rows added with
 * "Añadir otro elemento" get the same autocomplete.
 *
 * When the field is not the autocomplete widget, the form is left exactly as
 * it was.
 *
 * The link to admin/people/create is only shown to whoever may use that page.
 *
 * @param array $form
 *   The node form, by reference.
 * @param array $form_state
 *   The form state, by reference. Unused; kept to mirror the hook.
 */
function myapi_provider_account_form_alter(&$form, &$form_state) {
  $langcode = myapi_provider_account_field_langcode($form);
  if ($langcode === NULL) {
    return;
  }

  $widget = &$form[MYAPI_PROVIDER_USERS_FIELD][$langcode];

  foreach (element_children($widget) as $delta) {
    if (isset($widget[$delta]['target_id']['#autocomplete_path'])) {
      $widget[$delta]['target_id']['#autocomplete_path'] = myapi_provider_account_autocomplete_path();
    }
  }

  $description = 'Cuentas que operan este proveedor desde la app. Busca por nombre, usuario o correo: solo aparecen cuentas activas con el rol proveedor.';

  if (user_access('administer users')) {
    $link = l('Crear usuario', 'admin/people/create', ['attributes' => ['target' => '_blank']]);
    $description .= ' ¿La persona no tiene cuenta? ' . $link . ' (se abre en otra pestaña), márcale el rol proveedor y luego búscala aquí.';
  }

  $widget['#description'] = $description;
}

/**
 * Page callback of admin/content/providers/user-autocomplete.
 *
 * Answers Drupal's autocomplete contract through myapi_respond_autocomplete(),
 * not the API envelope. The search string is every path segment, glued back
 * the way entityreference's own callback does. Never an error: an empty
 * string answers an empty object.
 */
function myapi_provider_account_autocomplete() {
  $string = trim(implode('/', func_get_args()));

  $matches = $string !== '' ? myapi_provider_account_matches($string) : [];

  module_load_include('inc', 'myapi', 'includes/myapi.response');
  myapi_respond_autocomplete($matches);
}

/**
 * Active accounts with the 'proveedor' role matching a string.
 *
 * Matches user name, e-mail, first name or last name. The e-mail is matched
 * and printed on purpose: the route is only open to whoever may write
 * 'provider' nodes, and the accounts offered are provider accounts, which
 * that operator manages.
 *
 * The key is 'username (uid)', the exact text entityreference's own element
 * validation reads the uid back from.
 *
 * @param string $string
 *   What the operator typed.
 *
 * @return array
 *   'username (uid)' => escaped HTML, at most 10, ordered by user name.
 */
function myapi_provider_account_matches($string) {
  module_load_include('inc', 'myapi', 'includes/myapi.provider_role');
  module_load_include('inc', 'myapi', 'includes/myapi.reservation_form');

  $like = '%' . db_like($string) . '%';

  $query = db_select('users', 'u')
    ->fields('u', ['uid', 'name', 'mail'])
    ->condition('u.status', 1);
  $query->innerJoin('users_roles', 'ur', 'ur.uid = u.uid');
  $query->innerJoin('role', 'r', 'r.rid = ur.rid');
  $query->condition('r.name', MYAPI_PROVIDER_ROLE);
  $query->leftJoin('field_data_field_nombre', 'fn', "fn.entity_id = u.uid AND fn.entity_type = 'user' AND fn.deleted = 0");
  $query->addField('fn', 'field_nombre_value', 'first_name');
  $query->leftJoin('field_data_field_apellidos', 'fa', "fa.entity_id = u.uid AND fa.entity_type = 'user' AND fa.deleted = 0");
  $query->addField('fa', 'field_apellidos_value', 'last_name');
  $query->condition(db_or()
    ->condition('u.name', $like, 'LIKE')
    ->condition('u.mail', $like, 'LIKE')
    ->condition('fn.field_nombre_value', $like, 'LIKE')
    ->condition('fa.field_apellidos_value', $like, 'LIKE'));
  $query->orderBy('u.name');
  $query->range(0, 10);

  $matches = [];
  foreach ($query->execute() as $row) {
    $label = myapi_provider_account_suggestion_label($row->name, $row->first_name, $row->last_name, $row->mail);
    $matches[myapi_reservation_form_autocomplete_key($row->name, $row->uid)] = '<div class="reference-autocomplete">' . check_plain($label) . '</div>';
  }

  return $matches;
}
