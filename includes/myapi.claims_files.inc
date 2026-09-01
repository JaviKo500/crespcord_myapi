<?php

/**
 * @file
 * Ownership and access rules for the files of a claim (SPEC 65).
 *
 * Since SPEC 65 the two file fields of the claims data model — field_images
 * and field_attachment — live in private://, so nothing serves them without
 * an explicit decision. That decision has TWO consumers, and this file is
 * what they share:
 *
 *   - myapi_file_download() in myapi.module, hook_file_download(): the
 *     back-office operator opening node/%/edit or the claims listing. Drupal
 *     hands it a URI and expects headers, -1 or NULL.
 *   - myapi_claim_file_download() in resources/claim.resource.inc,
 *     GET /api/v1/claims/%/files/%: the Flutter app with a Bearer token. It
 *     already knows the claim it is asking about; what it needs from here is
 *     whether the fid really belongs to that claim.
 *
 * The two ask different questions of the same fact — which claim owns this
 * file — so the resolution lives here once, and neither consumer reimplements
 * it (Regla 3 de CLAUDE.md).
 *
 * Why a new include and not includes/myapi.claims_common.inc: that file's own
 * @file declares it deliberately neutral — no Field API, no database, only
 * pure functions, which is what makes it testable from tests/unit. Resolving
 * the owner of a fid is a query. Putting it there would break the very
 * property that justifies that file's existence.
 *
 * MAINTENANCE RULE — if a future spec adds another file field to 'reclamo' or
 * to 'claim_transaction', two things must happen or the file is born public
 * and unreachable by both consumers: create the field with
 * 'settings' => ['uri_scheme' => 'private'], and add its name to the list
 * myapi_claims_file_claim_nid() walks below. Same note in docs/claim.md.
 */

/**
 * Resolves the claim that owns a file, or NULL when no claim does.
 *
 * A fid can hang from four places, and all four end at a 'reclamo':
 *
 *   field_images     of a 'reclamo'           → that nid
 *   field_attachment of a 'reclamo'           → that nid
 *   field_images     of a 'claim_transaction' → field_claim → the claim's nid
 *   field_attachment of a 'claim_transaction' → field_claim → the claim's nid
 *
 * Anything else — a payment receipt in private://comprobantes_pago, an area
 * photo, a file of another module — resolves to NULL, and NULL is what makes
 * both consumers keep their hands off files that are not theirs.
 *
 * A file shared by a claim and one of its own transactions resolves through
 * whichever field is found first; both paths lead to the same nid, so the
 * answer is the same either way. A file shared across DIFFERENT claims — which
 * no form in this module produces — would resolve to one of them and the
 * membership check would answer 404 for the other: it fails closed.
 *
 * @param mixed $fid  file_managed.fid, as it arrives from a route argument or
 *                    from a database column (string or int).
 *
 * @return int|NULL  The nid of the owning claim, or NULL.
 */
function myapi_claims_file_claim_nid($fid) {
  $fid = (int) $fid;

  if ($fid <= 0) {
    return NULL;
  }

  module_load_include('inc', 'myapi', 'includes/myapi.building_admin');

  // The two file fields of the data model. See the maintenance rule above
  // before adding a third one anywhere else.
  foreach (['field_images', 'field_attachment'] as $field_name) {
    $query = db_select('field_data_' . $field_name, 'f');
    $query->condition('f.entity_type', 'node');
    $query->condition('f.deleted', 0);
    $query->condition('f.' . $field_name . '_fid', $fid);

    // Only the two claim bundles: the same field could be attached to another
    // content type by a future spec, and that file would not be a claim's.
    $query->innerJoin('node', 'n', 'n.nid = f.entity_id');
    $query->condition('n.type', [MYAPI_BUILDING_ADMIN_CLAIM_TYPE, MYAPI_BUILDING_ADMIN_TRANSACTION_TYPE], 'IN');

    // LEFT and not INNER: a 'reclamo' has no field_claim, and that is the
    // common case. For a 'claim_transaction' this is the hop to its claim.
    $query->leftJoin('field_data_field_claim', 'fcl', "fcl.entity_id = n.nid AND fcl.entity_type = 'node' AND fcl.deleted = 0");

    $query->addField('n', 'nid', 'nid');
    $query->addField('n', 'type', 'type');
    $query->addField('fcl', 'field_claim_target_id', 'claim_nid');
    // Deterministic when the same file is referenced more than once, which is
    // the only case where more than one row can come back.
    $query->orderBy('n.nid', 'ASC');
    $query->range(0, 1);

    $row = $query->execute()->fetchObject();

    if (!$row) {
      continue;
    }

    if ($row->type === MYAPI_BUILDING_ADMIN_CLAIM_TYPE) {
      return (int) $row->nid;
    }

    // A transaction whose field_claim is empty owns no claim: no nid to check
    // access against, so it is treated as not ours rather than as accessible.
    if ($row->claim_nid !== NULL) {
      return (int) $row->claim_nid;
    }
  }

  return NULL;
}

/**
 * Resolves a stream URI into its file_managed.fid, or NULL.
 *
 * Only hook_file_download() needs this: Drupal hands it a URI, while
 * everything else in this file — and the whole API — speaks fids. The endpoint
 * gets its fid from the route and never calls this.
 *
 * An unknown URI answers NULL, which is the normal outcome for most calls:
 * hook_file_download() fires for EVERY private file of the site, including
 * image-style derivatives (private://styles/...), which have no row in
 * file_managed at all.
 *
 * @param mixed $uri  The stream wrapper URI Drupal passed to the hook.
 *
 * @return int|NULL  The fid, or NULL when no file has that exact URI.
 */
function myapi_claims_file_fid_by_uri($uri) {
  if (!is_string($uri) || $uri === '') {
    return NULL;
  }

  $fid = db_select('file_managed', 'fm')
    ->fields('fm', ['fid'])
    ->condition('fm.uri', $uri)
    ->range(0, 1)
    ->execute()
    ->fetchField();

  return $fid === FALSE ? NULL : (int) $fid;
}

/**
 * Whether a back-office account may read the files of a claim (SPEC 65).
 *
 * The rule is the one SPEC 56 already applies to admin/content/claims and to
 * node/%/edit, reused rather than reinvented — the three roles of
 * myapi_claims_admin_roles(), with 'administrador edificio' scoped to its
 * assigned condominiums. Granting by role alone would trade one leak for a
 * smaller one: a building admin could see, by pasting URLs, the photos of
 * claims of buildings they do not administer.
 *
 * The scoping is delegated to myapi_building_admin_access_decision(), the same
 * function hook_node_access() uses, so the two cannot drift apart. Its
 * 'ignore' — "this node is out of the rule" — becomes TRUE here and not
 * "someone else decides", because in this context there IS nobody else: the
 * account already holds the role and the file already belongs to a claim, so
 * the only remaining question is the condominium. 'deny' becomes FALSE.
 *
 * uid 1 is let in explicitly, same guard and same reason as
 * myapi_claims_admin_access(): the superuser bypass lives inside
 * user_access(), which this never calls.
 *
 * This function answers for the BACK OFFICE only. The app's endpoint asks a
 * different question — myapi_claim_base_query(), the visibility rule of
 * SPEC 64 — and never calls this.
 *
 * @param mixed  $claim_nid  The nid of the owning claim, as returned by
 *                           myapi_claims_file_claim_nid().
 * @param object $account    The account to decide for. Anonymous (uid 0, no
 *                           roles) falls through to FALSE.
 *
 * @return bool  TRUE when the account may read that claim's files.
 */
function myapi_claims_file_access($claim_nid, $account) {
  if (!is_object($account)) {
    return FALSE;
  }

  if ((int) $account->uid === 1) {
    return TRUE;
  }

  // $account->roles is rid => name in Drupal 7; names are compared, never rids.
  $roles = (!empty($account->roles) && is_array($account->roles))
    ? array_values($account->roles)
    : [];

  $admin_roles = myapi_claims_admin_roles();

  if (!array_intersect($roles, $admin_roles)) {
    return FALSE;
  }

  module_load_include('inc', 'myapi', 'includes/myapi.building_admin');

  // 'administrator' and 'backend' see every claim of the site, exactly as they
  // do in the back-office listing. Taken as "the admin roles minus the scoped
  // one" so the list stays declared in myapi_claims_admin_roles() alone.
  if (array_intersect($roles, array_diff($admin_roles, [MYAPI_BUILDING_ADMIN_ROLE]))) {
    return TRUE;
  }

  // Only 'administrador edificio' left.
  $claim = node_load((int) $claim_nid);

  if (!is_object($claim)) {
    return FALSE;
  }

  $decision = myapi_building_admin_access_decision(
    myapi_building_admin_node_condominium($claim),
    myapi_building_admin_condominium_ids($account)
  );

  return $decision !== 'deny';
}

/**
 * The whole body of hook_file_download() for this module (SPEC 65).
 *
 * Lives here and not in myapi.module because CLAUDE.md keeps business logic
 * out of the .module; myapi_file_download() is the three-line stub Drupal
 * requires, and this is what it delegates to.
 *
 * Returns exactly the three values Drupal 7 expects from the hook:
 *
 *   array  — headers. The URI is a claim file AND the account may read it.
 *   -1     — a hard deny. The URI is a claim file the account may NOT read.
 *   NULL   — not ours. Somebody else decides, and if nobody does, Drupal
 *            answers 403 on its own. This is the answer for payment receipts
 *            (private://comprobantes_pago), for image-style derivatives and
 *            for every private file of every other module — which is what
 *            keeps the rest of the site behaving exactly as before.
 *
 * Cost on a foreign URI is one query: myapi_claims_file_fid_by_uri() misses,
 * or the fid resolves to no claim, and it is over. The hook fires for every
 * private file of the site, so that path has to stay cheap.
 *
 * Image-style derivatives are handled by core, not here: image_style_deliver()
 * checks access on the SOURCE file, which this does resolve, and then writes
 * the derivative. private://styles/... never reaches this function with a row
 * in file_managed, so it answers NULL and core keeps the decision.
 *
 * @param mixed  $uri      The URI Drupal passed to the hook.
 * @param object $account  The account to decide for — the current user in
 *                         production.
 *
 * @return array|int|NULL  Headers, -1, or NULL.
 */
function myapi_claims_file_download_headers($uri, $account) {
  $fid = myapi_claims_file_fid_by_uri($uri);

  if ($fid === NULL) {
    return NULL;
  }

  $claim_nid = myapi_claims_file_claim_nid($fid);

  if ($claim_nid === NULL) {
    return NULL;
  }

  if (!myapi_claims_file_access($claim_nid, $account)) {
    return -1;
  }

  $file = file_load($fid);

  if (!is_object($file)) {
    return NULL;
  }

  // The same header block the API endpoints send, from the same function: a
  // photo travels 'inline' — the back office renders these on screen, and
  // forcing a download on every node/%/edit thumbnail would make the forms
  // unusable — while a type that could carry script never does. One decision,
  // one place, both audiences.
  module_load_include('inc', 'myapi', 'includes/myapi.file_download');

  return myapi_file_download_headers($file);
}
