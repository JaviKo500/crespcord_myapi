<?php

/**
 * @file
 * The SQL half of "an active provider" (SPEC 83).
 *
 * One function, and it exists so that the rule is written ONCE in SQL. After
 * this file, the rule has exactly TWO homes and no more:
 *
 *   - myapi_services_provider_is_active() in includes/myapi.services_common.inc
 *     — the PHP half. Pure, decides over values already loaded, and is what a
 *     caller holding a node object asks.
 *   - myapi_provider_apply_active_conditions() below — the SQL half. Decides
 *     inside a query, over rows that are never loaded, and is what a caller
 *     counting or listing providers asks.
 *
 * MAINTENANCE RULE — the two halves must move together. A spec that changes
 * what "active" means has to change BOTH, and neither may be restated a third
 * time: a query that writes the WHERE by hand is the divergence this file
 * exists to prevent, and its first symptom is a category card promising "3
 * providers" over a listing that returns 4. Same note in
 * docs/services-install.md and in the docblock of
 * myapi_services_provider_is_active().
 *
 * Why an include and not includes/myapi.services_common.inc: that file's own
 * @file declares it a catalogue of PURE helpers with no database, which is what
 * lets tests/unit require it with no site booted. This one builds a query. Same
 * reason includes/myapi.provider_files.inc was born separate (SPEC 82).
 *
 * Two consumers today, and that is what makes this an include rather than a
 * private function of either one (Regla 3 de CLAUDE.md):
 * myapi_service_category_provider_counts() in
 * resources/service_category.resource.inc (SPEC 79) and the two list queries of
 * resources/provider.resource.inc (SPEC 83).
 */

/**
 * Narrows a query to the ACTIVE providers, joining what the rule needs.
 *
 * Adds three things to the query it is given, in place:
 *
 *   - an INNER JOIN with field_data_field_license_expiry, aliased 'l';
 *   - the condition that the node is published (status = 1);
 *   - the condition that the licence has not expired
 *     (field_license_expiry_value >= REQUEST_TIME).
 *
 * The join is INNER and not LEFT on purpose, and it is half the rule: a
 * provider with NO licence row at all — a node saved outside the form, or a
 * field emptied by hand — is dropped by the join, which is the same answer
 * myapi_services_provider_is_active() gives to a NULL expiry. Making it LEFT
 * would let those providers through with a NULL value that no comparison
 * rejects.
 *
 * The comparison is >= and not >, so a licence is valid throughout its expiry
 * timestamp and not one second less — the same boundary the PHP half draws.
 *
 * What this function does NOT add is the bundle: the caller states
 * `n.type = MYAPI_SERVICES_PROVIDER_TYPE` itself. "Active" is a rule about a
 * provider's standing, not about which nodes are providers, and the two
 * consumers reach the node table by different routes.
 *
 * THE ALIAS 'l' IS RESERVED. A caller that already joins something under that
 * alias must rename its own; nothing else in the query is touched.
 *
 * @param SelectQueryInterface $query
 *   The query to narrow. Modified in place — the same object the caller keeps
 *   building on. No type hint on purpose: every query helper of this module
 *   documents the type and hints none, which is what lets the unit suite pass
 *   its own fixture builder (see includes/myapi.claim_query.inc).
 * @param string $node_alias
 *   The alias the `node` table carries in that query. A parameter and not the
 *   literal 'n', because a shared function that dictates how the caller names
 *   its tables breaks the second time somebody uses it.
 *
 * @return void
 *   Nothing: the query is the output.
 */
function myapi_provider_apply_active_conditions($query, $node_alias = 'n') {
  $query->innerJoin(
    'field_data_field_license_expiry',
    'l',
    "l.entity_type = 'node' AND l.entity_id = " . $node_alias . ".nid AND l.deleted = 0"
  );
  $query->condition($node_alias . '.status', 1);
  $query->condition('l.field_license_expiry_value', REQUEST_TIME, '>=');
}
