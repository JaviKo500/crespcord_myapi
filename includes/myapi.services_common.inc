<?php

/**
 * @file
 * Catalogues and pure helpers of the services marketplace (SPEC 77).
 *
 * Single source of truth for the machine names of the five bundles and the
 * category vocabulary, for the two status catalogues, for the star scale, and
 * for the three rules the rest of the module will decide with: which status
 * transition is legal, whether closing a request needs a rating, and whether a
 * provider is active.
 *
 * SPEC 77 creates the structure only — no endpoint, no back-office page, no
 * notification. Nothing here is called from a resources/*.resource.inc yet;
 * myapi.install reads the catalogues to build the allowed_values of the
 * list_text fields, which is what keeps the field configuration and the
 * business rules from drifting apart the way two hand-written lists would.
 *
 * Everything below is PURE: no database, no Drupal, no globals. That is what
 * lets tests/unit/ServicesInstallTest.php require this file and exercise the
 * whole decision surface with no site booted — same split as
 * includes/myapi.building_admin.inc (SPEC 49).
 *
 * Every define() is guarded so a PHPUnit require_once of this file next to one
 * that already defined the same constant does not emit a redefinition notice
 * (same pattern as MYAPI_BUILDING_ADMIN_ROLE).
 */

// Machine name of the provider bundle: a company or person offering services.
if (!defined('MYAPI_SERVICES_PROVIDER_TYPE')) {
  define('MYAPI_SERVICES_PROVIDER_TYPE', 'provider');
}

// Machine name of the service request bundle, created by a resident.
if (!defined('MYAPI_SERVICES_REQUEST_TYPE')) {
  define('MYAPI_SERVICES_REQUEST_TYPE', 'service_request');
}

// Machine name of the offer bundle: a provider's proposal on a request.
if (!defined('MYAPI_SERVICES_OFFER_TYPE')) {
  define('MYAPI_SERVICES_OFFER_TYPE', 'service_offer');
}

// Machine name of the rating bundle: the resident's score on the awarded
// provider, filed when the request is closed.
if (!defined('MYAPI_SERVICES_RATING_TYPE')) {
  define('MYAPI_SERVICES_RATING_TYPE', 'service_rating');
}

// Machine name of the timeline bundle: one entry per status change of a
// request, with an optional comment. Mirrors 'claim_transaction' (SPEC 55).
if (!defined('MYAPI_SERVICES_TRANSACTION_TYPE')) {
  define('MYAPI_SERVICES_TRANSACTION_TYPE', 'service_transaction');
}

// Machine name of the marketplace category vocabulary. This is the first
// vocabulary this module creates; the banks vocabulary (SPEC 76) is read, not
// created.
if (!defined('MYAPI_SERVICES_CATEGORY_VOCABULARY')) {
  define('MYAPI_SERVICES_CATEGORY_VOCABULARY', 'service_category');
}

// Machine name of the provider tag vocabulary (SPEC 81). Unlike the category
// vocabulary above — closed, curated and carrying a stable field_category_code
// the app hangs logic on — this one is free: terms are born as the operator
// types them into the provider form, and nothing but their name is stored.
if (!defined('MYAPI_SERVICES_TAG_VOCABULARY')) {
  define('MYAPI_SERVICES_TAG_VOCABULARY', 'provider_tag');
}

// The status a request is born in.
if (!defined('MYAPI_SERVICES_REQUEST_STATUS_OPEN')) {
  define('MYAPI_SERVICES_REQUEST_STATUS_OPEN', 'open');
}

// The second status a request can be BORN in: the resident went straight to a
// provider of their choice, with no bidding round. It is not reachable from any
// other status — see myapi_services_request_transitions() — and closing from it
// requires a rating exactly like 'assigned' does, because there is a provider
// who did the job.
if (!defined('MYAPI_SERVICES_REQUEST_STATUS_DIRECT')) {
  define('MYAPI_SERVICES_REQUEST_STATUS_DIRECT', 'direct');
}

// At least one offer received, none awarded yet.
if (!defined('MYAPI_SERVICES_REQUEST_STATUS_OFFERED')) {
  define('MYAPI_SERVICES_REQUEST_STATUS_OFFERED', 'offered');
}

// An offer has been awarded. Closing from here requires a rating — see
// myapi_services_close_requires_rating(), which says the same of 'direct'.
if (!defined('MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED')) {
  define('MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED', 'assigned');
}

// Terminal: the request is finished, with or without an award.
if (!defined('MYAPI_SERVICES_REQUEST_STATUS_CLOSED')) {
  define('MYAPI_SERVICES_REQUEST_STATUS_CLOSED', 'closed');
}

// Terminal: the resident (or the operator) called it off.
if (!defined('MYAPI_SERVICES_REQUEST_STATUS_CANCELLED')) {
  define('MYAPI_SERVICES_REQUEST_STATUS_CANCELLED', 'cancelled');
}

// The status an offer is born in, and the only one POST
// /api/v1/service-requests/%/offers ever writes (SPEC 100): 'sent' is not a
// field of the request body, it is what creating an offer MEANS.
if (!defined('MYAPI_SERVICES_OFFER_STATUS_SENT')) {
  define('MYAPI_SERVICES_OFFER_STATUS_SENT', 'sent');
}

// The awarded offer. Named here alongside 'sent' because the two are read
// together wherever a LIVE offer is what matters — the uniqueness gate of
// SPEC 100 and the rejection sweep of SPEC 95 — and 'rejected' / 'withdrawn'
// are dead offers that block nothing. Awarding itself is still another spec:
// nothing in this module writes this value yet.
if (!defined('MYAPI_SERVICES_OFFER_STATUS_SELECTED')) {
  define('MYAPI_SERVICES_OFFER_STATUS_SELECTED', 'selected');
}

/**
 * The machine names of the five node bundles this feature installs.
 *
 * Order is the order they are created in: 'provider' before 'service_request'
 * before 'service_offer', because the entityreference selection settings of a
 * field name a target bundle and the bundle has to exist for the autocomplete
 * to resolve it. The installer relies on this order; so does the uninstall,
 * which walks it to delete the types.
 *
 * @return array
 *   List of node type machine names.
 */
function myapi_services_node_types() {
  return [
    MYAPI_SERVICES_PROVIDER_TYPE,
    MYAPI_SERVICES_REQUEST_TYPE,
    MYAPI_SERVICES_OFFER_TYPE,
    MYAPI_SERVICES_RATING_TYPE,
    MYAPI_SERVICES_TRANSACTION_TYPE,
  ];
}

/**
 * The status catalogue of a service request.
 *
 * Read twice by the installer: once for the allowed_values of the
 * field_request_status instance on 'service_request', and once for the
 * instance of the SAME field on 'service_transaction' — a timeline entry
 * records a status out of this very list, exactly as field_status is shared
 * between 'reclamo' and 'claim_transaction' (SPEC 55).
 *
 * Keys are stable and English (they travel in the API); labels are Spanish and
 * only ever seen in the back office. This is the same criterion as
 * field_area_category (SPEC 32) and field_status (SPEC 55).
 *
 * Order is lifecycle order, with one deliberate detail: the TWO statuses a
 * request can be born in — 'open' and 'direct' — come first, then the bidding
 * chain, then the two terminals. 'direct' sits next to 'open' and not next to
 * 'assigned' because it is an entry point, not a step of the round; that it
 * behaves like 'assigned' when closing is a rule of
 * myapi_services_close_requires_rating(), not a place in the list.
 *
 * @return array
 *   Status key => Spanish label, in lifecycle order.
 */
function myapi_services_request_statuses() {
  return [
    MYAPI_SERVICES_REQUEST_STATUS_OPEN      => 'Abierta',
    MYAPI_SERVICES_REQUEST_STATUS_DIRECT    => 'Proveedor directo',
    MYAPI_SERVICES_REQUEST_STATUS_OFFERED   => 'Con ofertas',
    MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED  => 'Asignada',
    MYAPI_SERVICES_REQUEST_STATUS_CLOSED    => 'Cerrada',
    MYAPI_SERVICES_REQUEST_STATUS_CANCELLED => 'Cancelada',
  ];
}

/**
 * The status catalogue of an offer.
 *
 * Deliberately a SEPARATE field from the request's status, not a shared one
 * with a wider catalogue: the two lists have no value in common, which is the
 * same reason SPEC 32 kept field_area_status and field_reservation_status
 * apart. Sharing would offer 'Abierta' in the offer form.
 *
 * @return array
 *   Status key => Spanish label.
 */
function myapi_services_offer_statuses() {
  return [
    'sent'      => 'Enviada',
    'selected'  => 'Seleccionada',
    'rejected'  => 'Rechazada',
    'withdrawn' => 'Retirada',
  ];
}

/**
 * How the amount of an offer is to be read (SPEC 100).
 *
 * The catalogue that turns "a number" into a quote: the same 150 means a closed
 * price, a guess, an hourly rate or nothing at all, and without this the
 * resident is comparing figures that do not mean the same thing. It is the ONE
 * new field POST /api/v1/service-requests/%/offers makes mandatory beyond the
 * message, precisely because the number is unreadable without it.
 *
 * 'on_site_quote' is the value that carries NO amount, and the endpoint enforces
 * both halves of that: an amount is required for the other three and forbidden
 * for this one. That rule lives in myapi_service_offer_validate_body(), not
 * here — this file stays pure catalogue.
 *
 * Keys are stable and English (they travel in the API); labels are Spanish and
 * only ever seen in the back office, the same criterion as every catalogue
 * above. The installer reads this for the allowed_values of
 * field_offer_amount_type and never retypes the list.
 *
 * @return array
 *   Amount type key => Spanish label.
 */
function myapi_services_offer_amount_types() {
  return [
    'fixed'         => 'Precio cerrado',
    'estimate'      => 'Estimado',
    'hourly'        => 'Por hora',
    'on_site_quote' => 'A presupuestar en sitio',
  ];
}

/**
 * The unit the estimated duration of an offer is expressed in (SPEC 100).
 *
 * Two values and no more: below an hour nothing in this marketplace is worth
 * quoting, and above a handful of days the honest answer is a date, which is
 * what field_offer_available_from already carries. Weeks and months were left
 * out for that reason and not by omission — adding one later is a value in this
 * list plus an update, and it does not change a single caller.
 *
 * Meaningless on its own: a duration without its unit says nothing and a unit
 * without its duration says less, so the endpoint takes the two together or
 * neither, and the response serves them as ONE object. Same criterion as the
 * amount above.
 *
 * @return array
 *   Duration unit key => Spanish label.
 */
function myapi_services_offer_duration_units() {
  return [
    'hours' => 'Horas',
    'days'  => 'Días',
  ];
}

/**
 * The star scale of a rating: 1 to 5, no zero and no half stars.
 *
 * Keys are integers because the field is list_integer, so the stored value is
 * the number itself and averaging it needs no mapping.
 *
 * @return array
 *   Number of stars => its label (the number as a string).
 */
function myapi_services_star_values() {
  return [
    1 => '1',
    2 => '2',
    3 => '3',
    4 => '4',
    5 => '5',
  ];
}

/**
 * Which status a request may move to, from each status.
 *
 * The graph agreed for this module:
 *
 *   open ──(first offer)──> offered ──(award)──> assigned
 *                              │                    │
 *                              └────> closed <──────┘
 *                                       ▲
 *   direct ─────────────────────────────┘
 *
 *   open | direct | offered | assigned ──> cancelled
 *
 * - open → offered      automatic, on the first offer.
 * - offered → assigned  the resident picks an offer.
 * - offered → closed    closing with no award, allowed by the contract (7.2.4).
 * - assigned → closed   closing after the job; needs a rating (see below).
 * - direct → closed     closing a direct job; needs a rating too (see below).
 * - anything → cancelled, except from a terminal status.
 *
 * 'closed' and 'cancelled' are TERMINAL: they map to an empty list, and
 * nothing reopens a request. That is a decision, not an omission — reopening
 * would need its own rules about the offers and the chat of the closed round.
 *
 * 'direct' is a ROOT: no status leads to it. A request either is born with a
 * provider already chosen or it goes through the bidding round, and the two
 * paths never cross — 'open' → 'direct' would leave the offers already
 * received with nothing to resolve them, and 'assigned' → 'direct' would
 * contradict the offer the resident already awarded. The two ways in and out
 * of it live in the same graph so nothing has to restate them.
 *
 * SPEC 77 does not enforce any of this: no endpoint and no form validation
 * reads this catalogue yet. It is written here now so the flow spec has one
 * place to read it from instead of restating the graph in prose.
 *
 * @return array
 *   Status key => list of the statuses reachable from it.
 */
function myapi_services_request_transitions() {
  return [
    MYAPI_SERVICES_REQUEST_STATUS_OPEN => [
      MYAPI_SERVICES_REQUEST_STATUS_OFFERED,
      MYAPI_SERVICES_REQUEST_STATUS_CANCELLED,
    ],
    MYAPI_SERVICES_REQUEST_STATUS_DIRECT => [
      MYAPI_SERVICES_REQUEST_STATUS_CLOSED,
      MYAPI_SERVICES_REQUEST_STATUS_CANCELLED,
    ],
    MYAPI_SERVICES_REQUEST_STATUS_OFFERED => [
      MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED,
      MYAPI_SERVICES_REQUEST_STATUS_CLOSED,
      MYAPI_SERVICES_REQUEST_STATUS_CANCELLED,
    ],
    MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED => [
      MYAPI_SERVICES_REQUEST_STATUS_CLOSED,
      MYAPI_SERVICES_REQUEST_STATUS_CANCELLED,
    ],
    MYAPI_SERVICES_REQUEST_STATUS_CLOSED    => [],
    MYAPI_SERVICES_REQUEST_STATUS_CANCELLED => [],
  ];
}

/**
 * Tells whether a request may move from one status to another.
 *
 * An unknown status — a value written by hand into the field, or one left
 * behind by a catalogue that shrank — answers FALSE rather than throwing: the
 * caller's job is to refuse the transition, not to crash on a bad row.
 *
 * @param string $from
 *   Current status key.
 * @param string $to
 *   Wanted status key.
 *
 * @return bool
 *   TRUE when the transition is in the graph.
 */
function myapi_services_transition_allowed($from, $to) {
  $transitions = myapi_services_request_transitions();

  if (!isset($transitions[$from])) {
    return FALSE;
  }

  return in_array($to, $transitions[$from], TRUE);
}

/**
 * Tells whether closing a request from this status requires a rating first.
 *
 * The rule agreed for this module: a rating is demanded when — and only when —
 * there is a provider who did the job, which happens in exactly two statuses:
 * 'assigned' (the provider of the awarded offer) and 'direct' (the provider the
 * resident picked with no round). Closing from 'offered' is the "no award" path
 * of the contract (7.2.4) and there is nobody to score.
 *
 * The two paths differ in what the rating can hang off, and that is why
 * field_rating_offer is OPTIONAL on 'service_rating': an assigned request has
 * an offer to point at, a direct one has none, and the reference that always
 * exists is field_rating_provider. Making a direct close demand a rating and
 * leaving the offer required would have demanded a rating that could not be
 * saved.
 *
 * @param string $status
 *   The status the request is in when the close is attempted.
 *
 * @return bool
 *   TRUE when a rating is required to close.
 */
function myapi_services_close_requires_rating($status) {
  return $status === MYAPI_SERVICES_REQUEST_STATUS_ASSIGNED
    || $status === MYAPI_SERVICES_REQUEST_STATUS_DIRECT;
}

/**
 * Tells whether a provider is active, from its published flag and licence.
 *
 * A provider is active when its node is published AND its licence has not
 * expired: node.status = 1 AND field_license_expiry >= now. The two halves
 * cover different cases on purpose — unpublishing suspends a provider by hand,
 * the expiry suspends it by itself — and both must hold.
 *
 * The comparison is >=, so the licence is valid throughout its expiry
 * timestamp and not one second less.
 *
 * A missing or non-numeric expiry answers FALSE: the field is required, so an
 * empty one means a node saved outside the form or a field removed by hand,
 * and the safe reading of "no licence on record" is "not active".
 *
 * Pure on purpose: the three places that will ask this — broadcasting a
 * request, creating an offer, listing a category — are three different Drupal
 * contexts, and the rule must not be restated in any of them. The Drupal-side
 * wrapper that loads the node and calls this belongs to the flow spec, not to
 * SPEC 77.
 *
 * @param int $status
 *   The node's published flag (node.status).
 * @param int|null $license_expiry
 *   Unix timestamp stored in field_license_expiry, or NULL when unset.
 * @param int $now
 *   The instant to compare against, normally REQUEST_TIME. Explicit so the
 *   test does not depend on the clock.
 *
 * @return bool
 *   TRUE when the provider may appear in the marketplace and offer.
 */
function myapi_services_provider_is_active($status, $license_expiry, $now) {
  if (!$status) {
    return FALSE;
  }

  if ($license_expiry === NULL || $license_expiry === '' || !is_numeric($license_expiry)) {
    return FALSE;
  }

  return (int) $license_expiry >= (int) $now;
}
