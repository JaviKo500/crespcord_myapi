<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/myapi.request.inc';
require_once __DIR__ . '/../../includes/myapi.response.inc';
require_once __DIR__ . '/../../includes/myapi.i18n.inc';
require_once __DIR__ . '/../../includes/myapi.token.inc';
require_once __DIR__ . '/../../includes/myapi.auth.inc';
require_once __DIR__ . '/../../includes/myapi.services_common.inc';
require_once __DIR__ . '/../../includes/myapi.provider_files.inc';
require_once __DIR__ . '/../../includes/myapi.provider_query.inc';
require_once __DIR__ . '/../../resources/provider.resource.inc';

/**
 * End-to-end unit tests for GET /api/v1/providers (SPEC 83).
 *
 * myapi_provider_dispatch() is called the way hook_menu() calls it, over a
 * fixture `node` table, a fixture my_api_tokens row and a fixture Authorization
 * header. What gets asserted is the JSON body the module prints and the status
 * code it sets — the same bytes the Flutter app receives.
 *
 * THE FIXTURE ROWS ARE THE JOINED ROWS. The query builder of tests/unit records
 * joins and never resolves them, so a provider is seeded flat: its own node
 * columns plus the value each LEFT JOIN would have brought, under the alias the
 * query gives it ('rating_avg', not 'field_rating_avg_value'). A provider with
 * no rating is seeded with that key NULL, which is exactly what a LEFT JOIN
 * answers for a node with no row in the field table.
 *
 * Two things this suite therefore does NOT prove, and both are the database's
 * half rather than the module's:
 *
 *  - that the INNER JOIN on taxonomy_term_data really drops an orphan tid. The
 *    fixture cannot make a recorded join fail to match, so what is asserted is
 *    the SHAPE of that join — INNER, on that table — and the dropping is the
 *    database's to do.
 *  - that MySQL sorts `(x IS NULL)` the way this stub does. The stub answers
 *    1/0 as SQL does, which is what makes the nulls-last assertions meaningful,
 *    but the real ordering is verified against a booted site (SPEC 83, step 9).
 */
class ProviderListEndpointTest extends TestCase {

  /**
   * The plaintext token every fixture request sends.
   */
  const TOKEN = 'a-valid-access-token';

  protected function setUp(): void {
    $this->assertSame('es', myapi_get_lang(), 'suite precondition: language resolves to the default');

    myapi_test_db_seed();
    $GLOBALS['myapi_test_users'] = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $this->clearQueryString();
    unset($_SERVER['HTTP_AUTHORIZATION']);
  }

  protected function tearDown(): void {
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $this->clearQueryString();
    $GLOBALS['myapi_test_users'] = [];
    myapi_test_db_seed();
  }

  private function clearQueryString() {
    unset($_GET['page'], $_GET['limit'], $_GET['order_by'], $_GET['sort'], $_GET['category_id']);
  }

  /* -------------------------------------------------------------------------
   * Fixtures.
   * ---------------------------------------------------------------------- */

  /**
   * A my_api_tokens row for the plaintext token above.
   */
  private function tokenRow(array $overrides = []) {
    return $overrides + [
      'id'                => '1',
      'uid'               => '3',
      'access_token_hash' => myapi_token_hash(self::TOKEN),
      'revoked'           => '0',
      'access_expires_at' => REQUEST_TIME + 1800,
    ];
  }

  /**
   * One provider row, as every LEFT JOIN and the licence join deliver it.
   *
   * Active by default — published, licence expiring tomorrow — so every case
   * that is not about the active rule reads without noise. The optional values
   * are NULL unless overridden, which is the honest default: SPEC 77 creates
   * field_rating_avg and field_rating_count empty and nothing writes them yet,
   * and SPEC 85 creates field_logo empty for every provider already on the site.
   *
   * `logo_uri` is the alias of the SECOND join of the pair — file_managed.uri —
   * so seeding it NULL covers the two ways the logo can be absent at once: no
   * row in field_data_field_logo, and a row pointing at a file_managed that no
   * longer exists. A LEFT JOIN answers NULL for both.
   */
  private function provider($nid, $title, array $overrides = []) {
    return $overrides + [
      'nid'                        => (string) $nid,
      'type'                       => MYAPI_SERVICES_PROVIDER_TYPE,
      'status'                     => '1',
      'title'                      => $title,
      'field_license_expiry_value' => (string) (REQUEST_TIME + 86400),
      'rating_avg'                 => NULL,
      'rating_count'               => NULL,
      'short_description'          => NULL,
      'hourly_rate'                => NULL,
      'logo_uri'                   => NULL,
    ];
  }

  /**
   * One row of field_data_field_categories, joined to its term.
   *
   * 'fc.entity_id' is written QUALIFIED on purpose: the EXISTS correlation and
   * the IN condition name the column that way, while the categories query
   * projects it under the alias 'nid'. The qualified key is the one both
   * resolve, and a flat fixture row cannot carry the same column twice.
   */
  private function categoryRow($nid, $tid, $name, $code = 'code', $delta = 0) {
    return [
      'fc.entity_id'         => (string) $nid,
      'entity_type'          => 'node',
      'deleted'              => '0',
      'delta'                => (string) $delta,
      'field_categories_tid' => (string) $tid,
      // What the join with taxonomy_term_data and field_category_code brings.
      'tid'                  => (string) $tid,
      'name'                 => $name,
      'code'                 => $code,
    ];
  }

  /**
   * Seeds the token row plus the given providers and category rows.
   *
   * One call, because every myapi_test_db_seed() replaces the whole fixture.
   */
  private function seed(array $providers = [], array $categories = [], $uid = 3) {
    $GLOBALS['myapi_test_users'][$uid] = ['uid' => $uid, 'name' => 'user' . $uid, 'status' => 1];

    myapi_test_db_seed([
      'my_api_tokens'                => [$this->tokenRow(['uid' => (string) $uid])],
      'node'                         => $providers,
      'field_data_field_categories'  => $categories,
    ]);
  }

  /**
   * Sends the request as an authenticated, active user.
   */
  private function authenticate() {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
  }

  /**
   * Runs the endpoint the way hook_menu() does.
   */
  private function request() {
    return myapi_test_capture('myapi_provider_dispatch');
  }

  /**
   * Authenticates, seeds and runs, which is what almost every case needs.
   */
  private function listing(array $providers, array $categories = []) {
    $this->authenticate();
    $this->seed($providers, $categories);

    return $this->request();
  }

  private function providers(array $result) {
    return $result['json']['data']['providers'];
  }

  private function ids(array $result) {
    return array_column($this->providers($result), 'id');
  }

  private function pagination(array $result) {
    return $result['json']['data']['pagination'];
  }

  private function queriedTables() {
    return array_column(myapi_test_db_queries(), 'table');
  }

  /* -------------------------------------------------------------------------
   * Method routing and authentication.
   * ---------------------------------------------------------------------- */

  /**
   * Everything that is not GET is 405, and the method is checked BEFORE the
   * token: a POST with a perfectly valid token is still 405, and one with no
   * token at all is 405 too — never 401. The marketplace is read-only over the
   * API.
   */
  public function testEveryMethodOtherThanGetIs405BeforeAuthentication() {
    foreach (['POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'] as $method) {
      // With a valid token.
      $this->authenticate();
      $this->seed([$this->provider(41, 'Plomería Torres')]);
      $_SERVER['REQUEST_METHOD'] = $method;

      $authenticated = $this->request();

      // And with none at all.
      unset($_SERVER['HTTP_AUTHORIZATION']);
      $this->seed([$this->provider(41, 'Plomería Torres')]);

      $anonymous = $this->request();

      $this->assertSame(405, $authenticated['status'], $method);
      $this->assertSame('method_not_allowed', $authenticated['json']['error_code'], $method);
      $this->assertSame('Método no permitido.', $authenticated['json']['error'], $method);
      $this->assertSame(405, $anonymous['status'], $method . ' (anonymous)');
      $this->assertSame('method_not_allowed', $anonymous['json']['error_code'], $method . ' (anonymous)');
      $this->assertSame([], $this->queriedTables(), $method . ': the 405 costs no query');
    }
  }

  /**
   * A lowercase verb is still a GET: myapi_request_method() upper-cases it.
   */
  public function testLowercaseGetIsAccepted() {
    $_SERVER['REQUEST_METHOD'] = 'get';

    $result = $this->listing([$this->provider(41, 'Plomería Torres')]);

    $this->assertSame(200, $result['status']);
  }

  /**
   * No Authorization header: 401 missing_authorization, and not one provider
   * query.
   */
  public function testMissingAuthorizationHeaderIs401() {
    $this->seed([$this->provider(41, 'Plomería Torres')]);

    $result = $this->request();

    $this->assertSame(401, $result['status']);
    $this->assertSame('missing_authorization', $result['json']['error_code']);
    $this->assertSame('No se proporcionó token de acceso.', $result['json']['error']);
    $this->assertNotContains('node', $this->queriedTables());
  }

  /**
   * A header that is not "Bearer <token>" is treated as no header at all.
   */
  public function testMalformedAuthorizationHeaderIs401() {
    foreach (['Token abc', 'Bearer', 'Bearer ', 'abc', 'Bearer a b'] as $header) {
      $this->seed([$this->provider(41, 'Plomería Torres')]);
      $_SERVER['HTTP_AUTHORIZATION'] = $header;

      $result = $this->request();

      $this->assertSame(401, $result['status'], $header);
      $this->assertSame('missing_authorization', $result['json']['error_code'], $header);
    }
  }

  /**
   * A token with no row, a revoked one and an expired one are all 401
   * invalid_token — the code the app tells "log in" from "refresh" by.
   */
  public function testUnknownRevokedAndExpiredTokensAre401InvalidToken() {
    $this->seed([$this->provider(41, 'Plomería Torres')]);
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer some-other-token';

    $unknown = $this->request();

    foreach ([['revoked' => '1'], ['access_expires_at' => REQUEST_TIME - 1]] as $overrides) {
      $this->authenticate();
      $GLOBALS['myapi_test_users'][3] = ['uid' => 3, 'name' => 'user3', 'status' => 1];
      myapi_test_db_seed([
        'my_api_tokens' => [$this->tokenRow($overrides)],
        'node'          => [$this->provider(41, 'Plomería Torres')],
      ]);

      $result = $this->request();

      $this->assertSame(401, $result['status'], json_encode($overrides));
      $this->assertSame('invalid_token', $result['json']['error_code'], json_encode($overrides));
    }

    $this->assertSame(401, $unknown['status']);
    $this->assertSame('invalid_token', $unknown['json']['error_code']);
    $this->assertSame('Token inválido.', $unknown['json']['error']);
  }

  /**
   * A deleted or blocked user is rejected as well, with no 500 on a NULL
   * account.
   */
  public function testTokenOfADeletedOrBlockedUserIs401() {
    $this->listing([$this->provider(41, 'Plomería Torres')]);
    $GLOBALS['myapi_test_users'] = [];

    $deleted = $this->request();

    $this->authenticate();
    $this->seed([$this->provider(41, 'Plomería Torres')]);
    $GLOBALS['myapi_test_users'][3]['status'] = 0;

    $blocked = $this->request();

    $this->assertSame(401, $deleted['status']);
    $this->assertSame('invalid_token', $deleted['json']['error_code']);
    $this->assertSame(401, $blocked['status']);
    $this->assertSame('invalid_token', $blocked['json']['error_code']);
  }

  /**
   * Authentication is the whole authorization: SPEC 83 puts no role,
   * condominium or unit check on the marketplace, so two different users get
   * byte-identical bodies.
   */
  public function testAnyAuthenticatedUserGetsTheSameMarketplace() {
    $providers = [
      $this->provider(41, 'Plomería Torres', ['rating_avg' => '4.75']),
      $this->provider(42, 'Electricidad Ríos'),
    ];

    $this->authenticate();
    $this->seed($providers, [], 3);
    $first = $this->request();

    $this->authenticate();
    $this->seed($providers, [], 41);
    $second = $this->request();

    $this->assertSame(200, $first['status']);
    $this->assertSame($first['output'], $second['output']);
  }

  /* -------------------------------------------------------------------------
   * The category filter.
   * ---------------------------------------------------------------------- */

  /**
   * With a tid, only the providers carrying that term come back — and the
   * `total` counts the filtered set, not the whole marketplace.
   */
  public function testCategoryFilterNarrowsBothTheListAndTheTotal() {
    $_GET['category_id'] = '7';

    $result = $this->listing(
      [
        $this->provider(41, 'Plomería Torres'),
        $this->provider(42, 'Electricidad Ríos'),
        $this->provider(43, 'Plomería Andes'),
      ],
      [
        $this->categoryRow(41, 7, 'Plomería'),
        $this->categoryRow(42, 9, 'Electricidad'),
        $this->categoryRow(43, 7, 'Plomería'),
      ]
    );

    $this->assertSame(200, $result['status']);
    $this->assertSame([43, 41], $this->ids($result));
    $this->assertSame(2, $this->pagination($result)['total']);
  }

  /**
   * THE BUG THE EXISTS EXISTS TO AVOID: a provider carrying the SAME category
   * in two deltas appears once and counts once. A JOIN would answer it twice.
   */
  public function testTheSameCategoryInTwoDeltasDoesNotDuplicateTheProvider() {
    $_GET['category_id'] = '7';

    $result = $this->listing(
      [$this->provider(41, 'Plomería Torres')],
      [
        $this->categoryRow(41, 7, 'Plomería', 'plomeria', 0),
        $this->categoryRow(41, 7, 'Plomería', 'plomeria', 1),
      ]
    );

    $this->assertSame([41], $this->ids($result));
    $this->assertSame(1, $this->pagination($result)['total']);
  }

  /**
   * A provider working in three categories appears once when filtered by any
   * one of them — the row multiplication a JOIN would produce, again.
   */
  public function testAProviderWithThreeCategoriesAppearsOnce() {
    $categories = [
      $this->categoryRow(41, 7, 'Plomería', 'plomeria', 0),
      $this->categoryRow(41, 9, 'Gasfitería', 'gasfiteria', 1),
      $this->categoryRow(41, 11, 'Cerrajería', 'cerrajeria', 2),
    ];

    foreach ([7, 9, 11] as $tid) {
      $_GET['category_id'] = (string) $tid;

      $result = $this->listing([$this->provider(41, 'Plomería Torres')], $categories);

      $this->assertSame([41], $this->ids($result), 'tid ' . $tid);
      $this->assertSame(1, $this->pagination($result)['total'], 'tid ' . $tid);
    }
  }

  /**
   * A tid nobody carries — a term that does not exist, or one of another
   * vocabulary — answers 200 with an empty list and total 0. NOT a 422 and not
   * a 404: the endpoint filters, it does not validate the catalogue.
   */
  public function testAnUnknownCategoryIdIsAnEmptyListAndNotAnError() {
    $_GET['category_id'] = '9999';

    $result = $this->listing(
      [$this->provider(41, 'Plomería Torres')],
      [$this->categoryRow(41, 7, 'Plomería')]
    );

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $this->providers($result));
    $this->assertSame(0, $this->pagination($result)['total']);
    $this->assertSame(0, $this->pagination($result)['total_pages']);
    $this->assertStringContainsString('"providers":[]', $result['output']);
  }

  /**
   * Without the parameter the whole marketplace comes back.
   */
  public function testWithoutTheParameterEveryActiveProviderIsListed() {
    $result = $this->listing([
      $this->provider(41, 'Plomería Torres'),
      $this->provider(42, 'Electricidad Ríos'),
      $this->provider(43, 'Jardinería Sur'),
    ]);

    $this->assertSame(3, $this->pagination($result)['total']);
    $this->assertCount(3, $this->providers($result));
  }

  /**
   * Every malformed category_id is a 422 invalid_field naming the parameter —
   * the ONE 422 of this endpoint. The empty string is in the list on purpose:
   * `?category_id=` is a present-and-broken value here, not an absent one.
   */
  public function testAMalformedCategoryIdIs422InvalidField() {
    foreach (['abc', '-3', '0', '', '1.5', ' 1', '1 ', '+1', 'null'] as $value) {
      $this->authenticate();
      $this->seed([$this->provider(41, 'Plomería Torres')]);
      $_GET['category_id'] = $value;

      $result = $this->request();

      $this->assertSame(422, $result['status'], var_export($value, TRUE));
      $this->assertFalse($result['json']['success'], var_export($value, TRUE));
      $this->assertSame('invalid_field', $result['json']['error_code'], var_export($value, TRUE));
      $this->assertStringContainsString('category_id', $result['json']['error'], var_export($value, TRUE));
    }
  }

  /**
   * `?category_id[]=1` — an array where a scalar is expected — is the same 422
   * and NOT a PHP notice: is_scalar() is checked before the string cast.
   */
  public function testAnArrayCategoryIdIs422AndNotAWarning() {
    $this->authenticate();
    $this->seed([$this->provider(41, 'Plomería Torres')]);
    $_GET['category_id'] = ['1'];

    $result = $this->request();

    $this->assertSame(422, $result['status']);
    $this->assertSame('invalid_field', $result['json']['error_code']);
  }

  /**
   * The 422 is answered BEFORE any listing query: a malformed filter costs the
   * token lookup and nothing else.
   */
  public function testTheMalformedFilterCostsNoListingQuery() {
    $this->authenticate();
    $this->seed([$this->provider(41, 'Plomería Torres')]);
    $_GET['category_id'] = 'abc';

    $this->request();

    $this->assertSame(['my_api_tokens'], $this->queriedTables());
  }

  /* -------------------------------------------------------------------------
   * Ordering.
   * ---------------------------------------------------------------------- */

  /**
   * The default, with no parameters at all: by rating DESCENDING.
   */
  public function testDefaultOrderIsRatingDescending() {
    $result = $this->listing([
      $this->provider(41, 'A', ['rating_avg' => '3.00']),
      $this->provider(42, 'B', ['rating_avg' => '4.75']),
      $this->provider(43, 'C', ['rating_avg' => '1.50']),
    ]);

    $this->assertSame([42, 41, 43], $this->ids($result));
  }

  /**
   * The four combinations of order_by x sort, each ordering by the column and
   * in the direction asked for.
   */
  public function testTheFourOrderCombinations() {
    $providers = [
      $this->provider(41, 'A', ['rating_avg' => '3.00', 'hourly_rate' => '30.00']),
      $this->provider(42, 'B', ['rating_avg' => '4.75', 'hourly_rate' => '10.50']),
      $this->provider(43, 'C', ['rating_avg' => '1.50', 'hourly_rate' => '99.99']),
    ];

    $expected = [
      'rating_avg|desc'  => [42, 41, 43],
      'rating_avg|asc'   => [43, 41, 42],
      'hourly_rate|desc' => [43, 41, 42],
      'hourly_rate|asc'  => [42, 41, 43],
    ];

    foreach ($expected as $key => $ids) {
      list($order_by, $sort) = explode('|', $key);
      $_GET['order_by'] = $order_by;
      $_GET['sort'] = $sort;

      $result = $this->listing($providers);

      $this->assertSame($ids, $this->ids($result), $key);
    }
  }

  /**
   * THE NULLS GO LAST IN BOTH DIRECTIONS, for both columns. A listing sorted by
   * price from low to high that OPENS with the providers publishing no price
   * reads as a server error, not as an order — which is why the first ORDER BY
   * criterion does not depend on `sort`.
   */
  public function testProvidersWithoutAValueAlwaysComeLast() {
    $providers = [
      $this->provider(41, 'Sin nada'),
      $this->provider(42, 'Con todo', ['rating_avg' => '4.75', 'hourly_rate' => '10.50']),
      $this->provider(43, 'Con poco', ['rating_avg' => '1.50', 'hourly_rate' => '99.99']),
    ];

    foreach (['rating_avg', 'hourly_rate'] as $order_by) {
      foreach (['asc', 'desc'] as $sort) {
        $_GET['order_by'] = $order_by;
        $_GET['sort'] = $sort;

        $result = $this->listing($providers);

        $ids = $this->ids($result);
        $this->assertSame(41, end($ids), $order_by . '|' . $sort . ': the one with no value is last');
        $this->assertCount(3, $ids, $order_by . '|' . $sort);
      }
    }
  }

  /**
   * And with several providers missing the value, all of them land after every
   * provider that has one, in both directions.
   */
  public function testEveryProviderWithoutAValueLandsAfterEveryProviderWithOne() {
    $providers = [
      $this->provider(41, 'Sin tarifa A'),
      $this->provider(42, 'Con tarifa', ['hourly_rate' => '10.50']),
      $this->provider(43, 'Sin tarifa B'),
      $this->provider(44, 'Con tarifa alta', ['hourly_rate' => '99.99']),
    ];

    foreach (['asc', 'desc'] as $sort) {
      $_GET['order_by'] = 'hourly_rate';
      $_GET['sort'] = $sort;

      $result = $this->listing($providers);

      $ids = $this->ids($result);
      $this->assertSame(
        $sort === 'asc' ? [42, 44] : [44, 42],
        array_slice($ids, 0, 2),
        $sort . ': the two with a rate lead, in the direction asked for'
      );
      $this->assertEqualsCanonicalizing([41, 43], array_slice($ids, 2), $sort . ': the two nulls at the end');
    }
  }

  /**
   * The tie-break: two providers with the same value always come back in the
   * same order between two identical requests, and the greater nid goes first.
   * Without it, one of them repeats on page 1 and the other is never shown.
   */
  public function testTiesAreBrokenByNidDescendingAndAreStable() {
    $providers = [
      $this->provider(41, 'A', ['rating_avg' => '4.50']),
      $this->provider(77, 'B', ['rating_avg' => '4.50']),
      $this->provider(52, 'C', ['rating_avg' => '4.50']),
    ];

    $first = $this->listing($providers);
    $second = $this->listing($providers);

    $this->assertSame([77, 52, 41], $this->ids($first));
    $this->assertSame($first['output'], $second['output']);
  }

  /**
   * The tie-break direction does NOT follow `sort`: it is always nid DESC, so
   * the pagination stays stable whichever way the listing is ordered.
   */
  public function testTheTieBreakIsAlwaysNidDescending() {
    $providers = [
      $this->provider(41, 'A', ['rating_avg' => '4.50']),
      $this->provider(77, 'B', ['rating_avg' => '4.50']),
    ];

    $_GET['sort'] = 'asc';

    $result = $this->listing($providers);

    $this->assertSame([77, 41], $this->ids($result));
  }

  /**
   * Unknown ordering values fall back to the defaults with a 200, never a 422 —
   * including the uppercase 'DESC', which is the value a client is most likely
   * to send by mistake.
   */
  public function testUnknownOrderValuesFallBackSilently() {
    $providers = [
      $this->provider(41, 'A', ['rating_avg' => '3.00', 'hourly_rate' => '99.99']),
      $this->provider(42, 'B', ['rating_avg' => '4.75', 'hourly_rate' => '10.50']),
    ];
    $default = [42, 41];

    $cases = [
      ['order_by' => 'title'],
      ['order_by' => ''],
      ['order_by' => 'created'],
      ['order_by' => 'RATING_AVG'],
      ['order_by' => 'precio'],
      ['sort' => 'DESC'],
      ['sort' => 'ASC'],
      ['sort' => 'descendente'],
      ['sort' => ''],
      ['sort' => '1'],
      ['order_by' => 'hourly_rate', 'sort' => 'ascendente'],
    ];

    foreach ($cases as $case) {
      $this->clearQueryString();
      foreach ($case as $key => $value) {
        $_GET[$key] = $value;
      }

      $result = $this->listing($providers);

      $this->assertSame(200, $result['status'], json_encode($case));
      // Every case above orders by rating desc, except the last one, which is
      // a valid order_by with an unknown sort: hourly_rate desc.
      $expected = isset($case['order_by']) && $case['order_by'] === 'hourly_rate' ? [41, 42] : $default;
      $this->assertSame($expected, $this->ids($result), json_encode($case));
    }
  }

  /**
   * An array where a string is expected does not fatal and does not order:
   * in_array(..., TRUE) rejects it and the defaults apply.
   */
  public function testArrayOrderValuesAreIgnored() {
    $_GET['order_by'] = ['hourly_rate'];
    $_GET['sort'] = ['asc'];

    $result = $this->listing([
      $this->provider(41, 'A', ['rating_avg' => '3.00']),
      $this->provider(42, 'B', ['rating_avg' => '4.75']),
    ]);

    $this->assertSame(200, $result['status']);
    $this->assertSame([42, 41], $this->ids($result));
  }

  /* -------------------------------------------------------------------------
   * Pagination.
   * ---------------------------------------------------------------------- */

  /**
   * Twenty-five providers, five per page: five items, limit 5, total 25 and
   * five pages.
   */
  public function testLimitCapsThePageAndTravelsInTheBlock() {
    $_GET['limit'] = '5';

    $result = $this->listing($this->manyProviders(25));

    $this->assertCount(5, $this->providers($result));
    $this->assertSame(
      ['total' => 25, 'page' => 1, 'limit' => 5, 'total_pages' => 5],
      $this->pagination($result)
    );
  }

  /**
   * The ceiling: 51 is cut to 50, and the block says 50.
   */
  public function testLimitAboveFiftyIsCutToFifty() {
    $_GET['limit'] = '51';

    $result = $this->listing($this->manyProviders(60));

    $this->assertCount(50, $this->providers($result));
    $this->assertSame(50, $this->pagination($result)['limit']);
  }

  /**
   * Every invalid limit falls back to 20 — AND -1 IS ONE OF THEM. The
   * marketplace is site-wide and has no ceiling, unlike the payments or
   * receipts of a single unit, where -1 does mean "everything".
   */
  public function testInvalidLimitsFallBackToTwentyAndMinusOneDoesNotFetchEverything() {
    foreach (['0', '-1', 'abc', '', '1.5', ' 5'] as $value) {
      $_GET['limit'] = $value;

      $result = $this->listing($this->manyProviders(30));

      $this->assertCount(20, $this->providers($result), var_export($value, TRUE));
      $this->assertSame(20, $this->pagination($result)['limit'], var_export($value, TRUE));
      $this->assertSame(30, $this->pagination($result)['total'], var_export($value, TRUE));
    }

    // And with the parameter absent.
    $this->clearQueryString();
    $result = $this->listing($this->manyProviders(30));
    $this->assertCount(20, $this->providers($result));
  }

  /**
   * Page 2 is the next slice: no item of page 1 repeats and none is skipped.
   */
  public function testPageTwoContinuesWherePageOneEnded() {
    $providers = $this->manyProviders(12);
    $_GET['limit'] = '5';

    $first = $this->listing($providers);
    $_GET['page'] = '2';
    $second = $this->listing($providers);
    $_GET['page'] = '3';
    $third = $this->listing($providers);

    $ids = array_merge($this->ids($first), $this->ids($second), $this->ids($third));

    $this->assertCount(5, $this->ids($first));
    $this->assertCount(5, $this->ids($second));
    $this->assertCount(2, $this->ids($third));
    $this->assertSame($ids, array_unique($ids), 'no item is served twice');
    $this->assertCount(12, $ids, 'no item is skipped');
    $this->assertSame(2, $this->pagination($second)['page']);
  }

  /**
   * Invalid pages answer the FIRST page with a 200, never a 422.
   */
  public function testInvalidPagesAnswerTheFirstPage() {
    $providers = $this->manyProviders(6);
    $_GET['limit'] = '2';
    $expected = NULL;

    foreach (['0', '-1', 'abc', '', '1.5'] as $value) {
      $_GET['page'] = $value;

      $result = $this->listing($providers);

      $this->assertSame(200, $result['status'], var_export($value, TRUE));
      $this->assertSame(1, $this->pagination($result)['page'], var_export($value, TRUE));
      if ($expected === NULL) {
        $expected = $this->ids($result);
      }
      $this->assertSame($expected, $this->ids($result), var_export($value, TRUE));
    }
  }

  /**
   * A page past the last one is an empty list with a 200 — never a 404 — and
   * the block still reports the real total.
   */
  public function testAPageBeyondTheLastOneIsAnEmptyList() {
    $_GET['limit'] = '5';
    $_GET['page'] = '99';

    $result = $this->listing($this->manyProviders(7));

    $this->assertSame(200, $result['status']);
    $this->assertSame([], $this->providers($result));
    $this->assertSame(7, $this->pagination($result)['total']);
    $this->assertSame(2, $this->pagination($result)['total_pages']);
    $this->assertSame(99, $this->pagination($result)['page']);
  }

  /**
   * With nothing to list, total_pages is 0 and not 1.
   */
  public function testAnEmptyMarketplaceAnswersZeroTotalPages() {
    $result = $this->listing([]);

    $this->assertSame(200, $result['status']);
    $this->assertSame(
      ['total' => 0, 'page' => 1, 'limit' => 20, 'total_pages' => 0],
      $this->pagination($result)
    );
    $this->assertSame('{"success":true,"data":{"providers":[],"pagination":'
      . '{"total":0,"page":1,"limit":20,"total_pages":0}}}', $result['output']);
  }

  /**
   * total_pages is ceil(total / limit) for a set that does not fit exactly.
   */
  public function testTotalPagesRoundsUp() {
    $_GET['limit'] = '10';

    $result = $this->listing($this->manyProviders(21));

    $this->assertSame(3, $this->pagination($result)['total_pages']);
  }

  /**
   * N active providers with sequential nids and descending ratings, so every
   * pagination case has a deterministic order to check.
   */
  private function manyProviders($count) {
    $providers = [];
    for ($i = 0; $i < $count; $i++) {
      $providers[] = $this->provider(100 + $i, 'Proveedor ' . $i, [
        'rating_avg' => number_format(5 - ($i / 100), 2, '.', ''),
      ]);
    }

    return $providers;
  }

  /* -------------------------------------------------------------------------
   * The shape of the item.
   * ---------------------------------------------------------------------- */

  /**
   * The full body, compared whole and with types: the contract the app codes
   * against, with a provider that has everything and one that has nothing.
   */
  public function testFullAnswerHasTheDocumentedShape() {
    $result = $this->listing(
      [
        $this->provider(41, 'Plomería Torres', [
          'rating_avg'        => '4.75',
          'rating_count'      => '12',
          'short_description' => 'Destapes y reparaciones, atención en el día.',
          'hourly_rate'       => '25.50',
          'logo_uri'          => 'public://logo-plomeria-torres.png',
        ]),
        $this->provider(42, 'Electricidad Ríos'),
      ],
      [
        $this->categoryRow(41, 7, 'Plomería', 'plomeria', 0),
        $this->categoryRow(41, 9, 'Gasfitería', 'gasfiteria', 1),
      ]
    );

    $this->assertSame(200, $result['status']);
    $this->assertSame([
      'success' => TRUE,
      'data'    => [
        'providers' => [
          [
            'id'         => 41,
            'logo'       => 'https://crespcord.example.com/sites/default/files/logo-plomeria-torres.png',
            'title'      => 'Plomería Torres',
            'categories' => [
              ['id' => 7, 'code' => 'plomeria', 'name' => 'Plomería'],
              ['id' => 9, 'code' => 'gasfiteria', 'name' => 'Gasfitería'],
            ],
            'rating_avg'        => 4.75,
            'rating_count'      => 12,
            'short_description' => 'Destapes y reparaciones, atención en el día.',
            'hourly_rate'       => 25.5,
          ],
          [
            'id'                => 42,
            'logo'              => NULL,
            'title'             => 'Electricidad Ríos',
            'categories'        => [],
            'rating_avg'        => NULL,
            'rating_count'      => 0,
            'short_description' => '',
            'hourly_rate'       => NULL,
          ],
        ],
        'pagination' => ['total' => 2, 'page' => 1, 'limit' => 20, 'total_pages' => 1],
      ],
    ], $result['json']);
  }

  /**
   * Every item carries EXACTLY the eight documented keys, in the documented
   * order — the assertion that catches a column leaking into the listing.
   *
   * `logo` is SECOND, right after `id` and before `title` (SPEC 85): it goes
   * with the visual identity of the provider, not tucked away at the end. The
   * provider with a logo and the one without answer the same eight keys.
   */
  public function testEveryItemHasExactlyEightKeysInOrder() {
    $result = $this->listing([
      $this->provider(41, 'Plomería Torres', [
        'rating_avg' => '4.75',
        'logo_uri'   => 'public://logo-plomeria-torres.png',
      ]),
      $this->provider(42, 'Electricidad Ríos'),
    ]);

    foreach ($this->providers($result) as $provider) {
      $this->assertSame(
        ['id', 'logo', 'title', 'categories', 'rating_avg', 'rating_count', 'short_description', 'hourly_rate'],
        array_keys($provider)
      );
    }
  }

  /**
   * And `data` carries `providers` and `pagination`, and nothing else.
   */
  public function testDataCarriesOnlyProvidersAndPagination() {
    $result = $this->listing([$this->provider(41, 'Plomería Torres')]);

    $this->assertSame(['providers', 'pagination'], array_keys($result['json']['data']));
    $this->assertSame(['total', 'page', 'limit', 'total_pages'], array_keys($this->pagination($result)));
  }

  /**
   * `id` is an int in the printed JSON, not a string: the database answers nids
   * as strings and Dart compares them as ints.
   */
  public function testIdIsPrintedAsAnInteger() {
    $result = $this->listing([$this->provider(41, 'Plomería Torres')]);

    $this->assertSame(41, $this->providers($result)[0]['id']);
    $this->assertStringContainsString('"id":41', $result['output']);
    $this->assertStringNotContainsString('"id":"41"', $result['output']);
  }

  /**
   * A provider with no ratings: rating_avg null and rating_count 0. NOT 0.0 for
   * the average — zero is outside the 1-5 scale and the app would paint five
   * empty stars instead of "not rated yet".
   */
  public function testAProviderWithoutRatingsAnswersNullAndZero() {
    $result = $this->listing([$this->provider(41, 'Plomería Torres')]);

    $this->assertNull($this->providers($result)[0]['rating_avg']);
    $this->assertSame(0, $this->providers($result)[0]['rating_count']);
    $this->assertStringContainsString('"rating_avg":null,"rating_count":0', $result['output']);
  }

  /**
   * A provider with no rate answers hourly_rate null AND IS LISTED. The failure
   * an INNER JOIN would produce: no error, no broken shape, just providers
   * quietly missing from the marketplace.
   */
  public function testAProviderWithoutARateIsListedWithANullRate() {
    $result = $this->listing([
      $this->provider(41, 'Sin tarifa'),
      $this->provider(42, 'Con tarifa', ['hourly_rate' => '25.50']),
    ]);

    $this->assertSame(2, $this->pagination($result)['total']);
    $this->assertEqualsCanonicalizing([41, 42], $this->ids($result));
    $this->assertNull($this->providers($result)[1]['hourly_rate']);
    $this->assertStringContainsString('"hourly_rate":null', $result['output']);
  }

  /**
   * A provider with none of the optional values is listed all the same: six
   * LEFT JOINs and not one of them excludes anybody — the logo's two included.
   */
  public function testAProviderWithNoOptionalValueAtAllIsStillListed() {
    $result = $this->listing([$this->provider(41, 'Recién dado de alta')]);

    $this->assertSame(1, $this->pagination($result)['total']);
    $this->assertSame([
      'id'                => 41,
      'logo'              => NULL,
      'title'             => 'Recién dado de alta',
      'categories'        => [],
      'rating_avg'        => NULL,
      'rating_count'      => 0,
      'short_description' => '',
      'hourly_rate'       => NULL,
    ], $this->providers($result)[0]);
  }

  /* -------------------------------------------------------------------------
   * The logo (SPEC 85).
   * ---------------------------------------------------------------------- */

  /**
   * A provider with a logo answers the ABSOLUTE, DIRECT URL of the file.
   *
   * The three negative assertions are the point of the key: no `api/v1/` and no
   * `/system/files` means the URL is NOT served by PHP, so a bare Flutter
   * Image.network paints it with no Authorization header. That is the whole
   * difference with the gallery of SPEC 82, whose URLs are this module's own
   * download route precisely because those files are private.
   */
  public function testAProviderWithALogoAnswersAnAbsolutePublicUrl() {
    $result = $this->listing([
      $this->provider(41, 'Plomería Torres', ['logo_uri' => 'public://logo-plomeria-torres.png']),
    ]);

    $logo = $this->providers($result)[0]['logo'];

    $this->assertSame('https://crespcord.example.com/sites/default/files/logo-plomeria-torres.png', $logo);
    $this->assertStringStartsWith($GLOBALS['base_url'] . '/', $logo);
    $this->assertStringNotContainsString('api/v1/', $logo);
    $this->assertStringNotContainsString('/system/files', $logo);
  }

  /**
   * A provider with no logo answers null AND IS LISTED — never "", never false
   * and never a missing key.
   *
   * The listed half is the one an INNER JOIN on field_data_field_logo would
   * break, and it would break it in silence: no error, no broken shape, just
   * every provider without a logo quietly gone from the marketplace.
   */
  public function testAProviderWithoutALogoAnswersNullAndIsStillListed() {
    $result = $this->listing([
      $this->provider(41, 'Sin logo'),
      $this->provider(42, 'Con logo', ['logo_uri' => 'public://logo-42.png']),
    ]);

    $this->assertSame(2, $this->pagination($result)['total']);
    $this->assertEqualsCanonicalizing([41, 42], $this->ids($result));

    $without = $this->providers($result)[1];
    $this->assertSame(41, $without['id']);
    $this->assertNull($without['logo']);
    $this->assertArrayHasKey('logo', $without);
    $this->assertStringContainsString('"logo":null', $result['output']);
    $this->assertStringNotContainsString('"logo":""', $result['output']);
    $this->assertStringNotContainsString('"logo":false', $result['output']);
  }

  /**
   * A field row pointing at a file_managed that no longer exists answers null
   * and KEEPS THE PROVIDER LISTED: the second join is as LEFT as the first.
   *
   * The fixture cannot tell the two absences apart — the query builder of
   * tests/unit records joins and never resolves them, so both "no row in
   * field_data_field_logo" and "row pointing at a deleted file" arrive as the
   * same flat row with logo_uri NULL, which is precisely what the two chained
   * LEFT JOINs answer in real SQL. What this case does pin is the OTHER half of
   * the guard, the one the NULL case never exercises: an empty uri is treated as
   * no logo too, so the app never receives "" nor a URL pointing nowhere.
   */
  public function testALogoWhoseFileIsGoneAnswersNullAndKeepsTheProviderListed() {
    $result = $this->listing([
      $this->provider(41, 'Fichero borrado del disco', ['logo_uri' => '']),
      $this->provider(42, 'Sin fila de campo', ['logo_uri' => NULL]),
    ]);

    $this->assertSame(2, $this->pagination($result)['total']);
    $this->assertEqualsCanonicalizing([41, 42], $this->ids($result));

    foreach ($this->providers($result) as $provider) {
      $this->assertNull($provider['logo']);
    }
    $this->assertStringNotContainsString('"logo":""', $result['output']);
  }

  /**
   * THE PAGINATION IS THE SAME AS BEFORE THE LOGO EXISTED.
   *
   * The bug a badly written LEFT JOIN introduces without making any noise: if
   * the pair of joins multiplied rows, the listing would answer repeated
   * providers and a total that no longer matches, and EVERY shape test above
   * would still be green, because each item on its own would be perfectly fine.
   *
   * Six providers, three with a logo and three without, on a page of five: the
   * total, the number of items and the ids must be exactly what they would be
   * with no logo in the world.
   */
  public function testTheLogoJoinsNeitherDuplicateNorLoseProviders() {
    $providers = [];
    for ($i = 0; $i < 6; $i++) {
      $nid = 100 + $i;
      $providers[] = $this->provider($nid, 'Proveedor ' . $i, [
        'rating_avg' => number_format(5 - ($i / 100), 2, '.', ''),
        'logo_uri'   => $i % 2 === 0 ? 'public://logo-' . $nid . '.png' : NULL,
      ]);
    }

    $_GET['limit'] = '5';
    $result = $this->listing($providers);

    $this->assertSame(6, $this->pagination($result)['total']);
    $this->assertSame(2, $this->pagination($result)['total_pages']);
    $this->assertCount(5, $this->providers($result));
    $ids = $this->ids($result);
    $this->assertSame($ids, array_unique($ids), 'no provider is answered twice');
    $this->assertSame([100, 101, 102, 103, 104], $ids);
  }

  /**
   * No short description answers "" and never null.
   */
  public function testAProviderWithoutAShortDescriptionAnswersAnEmptyString() {
    $result = $this->listing([$this->provider(41, 'Plomería Torres')]);

    $this->assertSame('', $this->providers($result)[0]['short_description']);
    $this->assertStringContainsString('"short_description":""', $result['output']);
  }

  /**
   * The two decimals travel as JSON NUMBERS and never as the strings the
   * decimal columns answer.
   *
   * A round value prints without a decimal part — '25.00' travels as 25 — which
   * is what json_encode() does with any float that has nothing after the point,
   * and exactly what `amount` already does in payments, receipts and expenses.
   * It is still a number: what would break the app is a quoted "25.00", and
   * that is what the last two assertions pin. Written down in docs/provider.md
   * because a Dart `as double` over a whole number throws.
   */
  public function testTheTwoDecimalsTravelAsJsonNumbers() {
    $result = $this->listing([
      $this->provider(41, 'Plomería Torres', ['rating_avg' => '4.50', 'hourly_rate' => '25.00']),
    ]);

    $this->assertSame(4.5, $this->providers($result)[0]['rating_avg']);
    $this->assertSame(25, $this->providers($result)[0]['hourly_rate']);
    $this->assertStringContainsString('"rating_avg":4.5', $result['output']);
    $this->assertStringContainsString('"hourly_rate":25', $result['output']);
    $this->assertStringNotContainsString('"rating_avg":"', $result['output']);
    $this->assertStringNotContainsString('"hourly_rate":"', $result['output']);
  }

  /**
   * `rating_count` is an int in the printed JSON, not the string the column
   * answers.
   */
  public function testRatingCountIsPrintedAsAnInteger() {
    $result = $this->listing([$this->provider(41, 'X', ['rating_count' => '12'])]);

    $this->assertSame(12, $this->providers($result)[0]['rating_count']);
    $this->assertStringContainsString('"rating_count":12', $result['output']);
    $this->assertStringNotContainsString('"rating_count":"12"', $result['output']);
  }

  /**
   * The title and the short description travel as PLAIN TEXT: the markup is
   * stripped and the ampersand is an ampersand, not `&amp;`. The consumer is a
   * Flutter Text widget, which decodes no entity and would paint the escaped
   * form verbatim. No tag survives either, so nothing is lost by not escaping.
   */
  public function testTitleAndShortDescriptionArePlainText() {
    $result = $this->listing([
      $this->provider(41, 'Plomería <b>Torres</b> & Hijos', [
        'short_description' => 'Destapes & reparaciones <script>alert(1)</script>',
      ]),
    ]);

    $item = $this->providers($result)[0];
    $this->assertSame('Plomería Torres & Hijos', $item['title']);
    // The body of the tag is text and text is kept; the TAG is what is gone.
    $this->assertSame('Destapes & reparaciones alert(1)', $item['short_description']);
    $this->assertStringNotContainsString('<script>', $result['output']);
    $this->assertStringNotContainsString('&amp;', $result['output']);
  }

  /**
   * Nothing of the detail leaks into the listing: no phone, no address, no long
   * description, no tags and no image.
   */
  public function testNoDetailFieldTravels() {
    $result = $this->listing([
      $this->provider(41, 'Plomería Torres', [
        // Columns a careless SELECT * would drag along.
        'field_phone_value'         => '0999999999',
        'field_address_value'       => 'Av. Siempre Viva 742',
        'field_services_desc_value' => 'Una descripción larguísima',
        'field_tags_tid'            => '55',
        'field_gallery_fid'         => '77',
      ]),
    ]);

    foreach (['0999999999', 'Siempre Viva', 'larguísima', 'field_tags', 'gallery', 'phone', 'address'] as $needle) {
      $this->assertStringNotContainsString($needle, $result['output'], $needle);
    }
  }

  /* -------------------------------------------------------------------------
   * The categories of the item.
   * ---------------------------------------------------------------------- */

  /**
   * A category travels with EXACTLY three keys — id, code, name — and none
   * more: no description, no icon_id, no icon_url. The icon is already in the
   * app from the categories grid, and repeating it on every provider of every
   * page is dead weight.
   */
  public function testACategoryHasExactlyThreeKeys() {
    $result = $this->listing(
      [$this->provider(41, 'Plomería Torres')],
      [$this->categoryRow(41, 7, 'Plomería', 'plomeria')]
    );

    $category = $this->providers($result)[0]['categories'][0];
    $this->assertSame(['id', 'code', 'name'], array_keys($category));
    $this->assertSame(['id' => 7, 'code' => 'plomeria', 'name' => 'Plomería'], $category);
    $this->assertStringNotContainsString('icon', $result['output']);
    $this->assertStringNotContainsString('description":"', str_replace('short_description":"', '', $result['output']));
  }

  /**
   * The categories come out in DELTA order, the order the operator dragged
   * them into the form — never alphabetical. The fixture puts them the other
   * way round on purpose.
   */
  public function testCategoriesFollowTheDeltaOrderAndNotTheAlphabet() {
    $result = $this->listing(
      [$this->provider(41, 'Plomería Torres')],
      [
        $this->categoryRow(41, 7, 'Plomería', 'plomeria', 0),
        $this->categoryRow(41, 9, 'Cerrajería', 'cerrajeria', 1),
        $this->categoryRow(41, 11, 'Albañilería', 'albanileria', 2),
      ]
    );

    $this->assertSame(
      ['Plomería', 'Cerrajería', 'Albañilería'],
      array_column($this->providers($result)[0]['categories'], 'name')
    );
  }

  /**
   * Each provider gets ITS OWN categories: the grouping by nid is what this
   * catches, and the failure would show one company's trades under another's
   * name.
   */
  public function testEachProviderGetsItsOwnCategories() {
    $result = $this->listing(
      [
        $this->provider(41, 'Plomería Torres'),
        $this->provider(42, 'Electricidad Ríos'),
        $this->provider(43, 'Sin categorías'),
      ],
      [
        $this->categoryRow(41, 7, 'Plomería', 'plomeria'),
        $this->categoryRow(42, 9, 'Electricidad', 'electricidad'),
        $this->categoryRow(42, 11, 'Domótica', 'domotica', 1),
      ]
    );

    $by_id = [];
    foreach ($this->providers($result) as $provider) {
      $by_id[$provider['id']] = array_column($provider['categories'], 'name');
    }

    $this->assertSame(['Plomería'], $by_id[41]);
    $this->assertSame(['Electricidad', 'Domótica'], $by_id[42]);
    $this->assertSame([], $by_id[43]);
  }

  /**
   * A provider with no categories answers [] — a JSON array, not an object or
   * a null — and is listed all the same.
   */
  public function testAProviderWithoutCategoriesAnswersAnEmptyArray() {
    $result = $this->listing([$this->provider(41, 'Sin categorías')]);

    $this->assertSame([], $this->providers($result)[0]['categories']);
    $this->assertStringContainsString('"categories":[]', $result['output']);
  }

  /**
   * A term with no field_category_code travels as code "" and is NOT hidden:
   * exactly what /api/v1/service-categories already answers for that term.
   */
  public function testACategoryWithoutACodeTravelsWithAnEmptyCode() {
    $categories = [$this->categoryRow(41, 7, 'Plomería')];
    unset($categories[0]['code']);

    $result = $this->listing([$this->provider(41, 'Plomería Torres')], $categories);

    $this->assertSame(
      ['id' => 7, 'code' => '', 'name' => 'Plomería'],
      $this->providers($result)[0]['categories'][0]
    );
  }

  /**
   * The name and the code of a category travel as plain text, the same rule
   * every string of this resource follows.
   */
  public function testCategoryNameAndCodeArePlainText() {
    $result = $this->listing(
      [$this->provider(41, 'Plomería Torres')],
      [$this->categoryRow(41, 7, 'Plomería & Gas', 'plomeria&gas')]
    );

    $category = $this->providers($result)[0]['categories'][0];
    $this->assertSame('Plomería & Gas', $category['name']);
    $this->assertSame('plomeria&gas', $category['code']);
  }

  /**
   * An ORPHAN tid is dropped by the INNER JOIN on taxonomy_term_data, and this
   * is the shape assertion that stands for it: the fixture builder records
   * joins without resolving them, so it cannot make one fail to match. What
   * CAN be pinned here is that the join is INNER and on that table — a LEFT one
   * would answer a category with a null name instead of omitting it — and that
   * the code join is LEFT, which is what lets a term with no code through.
   */
  public function testTheCategoryQueryJoinsTheTermInnerAndTheCodeLeft() {
    $this->listing(
      [$this->provider(41, 'Plomería Torres')],
      [$this->categoryRow(41, 7, 'Plomería')]
    );

    $queries = myapi_test_db_queries();
    $categories_query = end($queries);

    $this->assertSame('field_data_field_categories', $categories_query['table']);
    $this->assertSame(
      ['taxonomy_term_data', 'field_data_field_category_code'],
      array_column($categories_query['joins'], 'table')
    );
    $this->assertSame(['INNER', 'LEFT'], array_column($categories_query['joins'], 'type'));
    $this->assertSame([['field' => 'fc.delta', 'direction' => 'ASC']], $categories_query['order']);
  }

  /* -------------------------------------------------------------------------
   * The active-provider rule.
   * ---------------------------------------------------------------------- */

  /**
   * An unpublished provider is not listed and does not count — with a filter
   * and without one. Suspending a provider by hand in the back office has to be
   * respected by the marketplace.
   */
  public function testAnUnpublishedProviderIsNeitherListedNorCounted() {
    $providers = [
      $this->provider(41, 'Activo'),
      $this->provider(42, 'Despublicado', ['status' => '0']),
    ];
    $categories = [
      $this->categoryRow(41, 7, 'Plomería'),
      $this->categoryRow(42, 7, 'Plomería'),
    ];

    $unfiltered = $this->listing($providers, $categories);

    $_GET['category_id'] = '7';
    $filtered = $this->listing($providers, $categories);

    $this->assertSame([41], $this->ids($unfiltered));
    $this->assertSame(1, $this->pagination($unfiltered)['total']);
    $this->assertSame([41], $this->ids($filtered));
    $this->assertSame(1, $this->pagination($filtered)['total']);
  }

  /**
   * A provider whose licence expired is not listed either — the second half of
   * the rule, and the half a query written without the licence join would
   * silently lose.
   */
  public function testAProviderWithAnExpiredLicenceIsNotListed() {
    $result = $this->listing([
      $this->provider(41, 'Vigente'),
      $this->provider(42, 'Caducado', ['field_license_expiry_value' => (string) (REQUEST_TIME - 1)]),
    ]);

    $this->assertSame([41], $this->ids($result));
    $this->assertSame(1, $this->pagination($result)['total']);
  }

  /**
   * The boundary: a licence expiring exactly now STILL counts, because the
   * comparison is >= and not >. Same boundary myapi_services_provider_is_active()
   * draws in PHP — with >, a provider would vanish from one endpoint a day
   * before the other.
   */
  public function testALicenceExpiringExactlyNowIsStillActive() {
    $result = $this->listing([
      $this->provider(41, 'Vence hoy', ['field_license_expiry_value' => (string) REQUEST_TIME]),
    ]);

    $this->assertSame([41], $this->ids($result));
    $this->assertSame(1, $this->pagination($result)['total']);
  }

  /**
   * A provider with NO licence value is not listed: production drops it with
   * the INNER JOIN, and the fixture models the same fact as the NULL a LEFT
   * JOIN would answer — which no comparison accepts either. Same answer
   * myapi_services_provider_is_active() gives to a NULL expiry.
   */
  public function testAProviderWithNoLicenceAtAllIsNotListed() {
    $result = $this->listing([
      $this->provider(41, 'Con habilitación'),
      $this->provider(42, 'Sin habilitación', ['field_license_expiry_value' => NULL]),
    ]);

    $this->assertSame([41], $this->ids($result));
    $this->assertSame(1, $this->pagination($result)['total']);
  }

  /**
   * Only nodes of the `provider` bundle are listed: a service_request does not
   * sneak into the marketplace.
   */
  public function testOnlyProviderNodesAreListed() {
    $result = $this->listing([
      $this->provider(41, 'Proveedor'),
      $this->provider(42, 'Solicitud', ['type' => MYAPI_SERVICES_REQUEST_TYPE]),
    ]);

    $this->assertSame([41], $this->ids($result));
  }

  /**
   * THE COUNT AND THE PAGE APPLY THE RULE ALIKE. With more inactive providers
   * than a page holds, `total` still counts only the active ones — if only one
   * of the two queries carried the rule, the total would say one thing and the
   * rows another.
   */
  public function testTheTotalCountsExactlyWhatThePagesReturn() {
    $providers = [];
    for ($i = 0; $i < 6; $i++) {
      $providers[] = $this->provider(100 + $i, 'Activo ' . $i);
      $providers[] = $this->provider(200 + $i, 'Despublicado ' . $i, ['status' => '0']);
      $providers[] = $this->provider(300 + $i, 'Caducado ' . $i, [
        'field_license_expiry_value' => (string) (REQUEST_TIME - 3600),
      ]);
    }

    $_GET['limit'] = '4';
    $first = $this->listing($providers);
    $_GET['page'] = '2';
    $second = $this->listing($providers);

    $ids = array_merge($this->ids($first), $this->ids($second));

    $this->assertSame(6, $this->pagination($first)['total']);
    $this->assertSame(2, $this->pagination($first)['total_pages']);
    $this->assertCount(6, $ids);
    $this->assertSame($ids, array_unique($ids));
    foreach ($ids as $id) {
      $this->assertLessThan(200, $id, 'only the active providers are paged through');
    }
  }

  /* -------------------------------------------------------------------------
   * The cost of the request.
   * ---------------------------------------------------------------------- */

  /**
   * FOUR QUERIES, whatever the page size: the token, the count, the page and
   * the categories of that page — never one query per provider. Twenty
   * providers with categories cost the same four as one.
   */
  public function testTheRequestCostsFourQueriesWhateverThePageSize() {
    $providers = [];
    $categories = [];
    for ($i = 0; $i < 20; $i++) {
      $providers[] = $this->provider(100 + $i, 'Proveedor ' . $i);
      $categories[] = $this->categoryRow(100 + $i, 7, 'Plomería');
      $categories[] = $this->categoryRow(100 + $i, 9, 'Gasfitería', 'gasfiteria', 1);
    }

    $this->listing($providers, $categories);

    $this->assertSame(
      ['my_api_tokens', 'node', 'node', 'field_data_field_categories'],
      $this->queriedTables()
    );
  }

  /**
   * An empty page runs no category query at all: there is no nid to ask about,
   * and an unbounded read over field_data_field_categories is exactly what the
   * early return avoids.
   */
  public function testAnEmptyPageRunsNoCategoryQuery() {
    $result = $this->listing([]);

    $this->assertSame(200, $result['status']);
    $this->assertSame(['my_api_tokens', 'node', 'node'], $this->queriedTables());
  }

  /**
   * The count query carries the active rule and the bundle, and does NOT drag
   * the four field joins along: they add columns, never rows.
   */
  public function testTheCountQueryIsNarrowedButNotJoinedToTheOptionalFields() {
    $this->listing([$this->provider(41, 'Plomería Torres')]);

    $queries = myapi_test_db_queries();
    $count = $queries[1];

    $this->assertTrue($count['count'], 'the second query is the COUNT');
    $this->assertSame(['field_data_field_license_expiry'], array_column($count['joins'], 'table'));
    $this->assertSame(['INNER'], array_column($count['joins'], 'type'));

    $conditions = [];
    foreach ($count['conditions'] as $condition) {
      $conditions[$condition['field']] = [$condition['value'], $condition['operator']];
    }
    $this->assertSame([MYAPI_SERVICES_PROVIDER_TYPE, '='], $conditions['n.type']);
    $this->assertSame([1, '='], $conditions['n.status']);
    $this->assertSame([REQUEST_TIME, '>='], $conditions['l.field_license_expiry_value']);
  }

  /**
   * The page query left-joins every optional field — the four of SPEC 83 and
   * the two of the logo (SPEC 85) — and inner-joins only the licence: the shape
   * assertion behind "a provider with no rate, or no logo, is listed".
   */
  public function testThePageQueryLeftJoinsEveryOptionalField() {
    $this->listing([$this->provider(41, 'Plomería Torres')]);

    $queries = myapi_test_db_queries();
    $page = $queries[2];

    $joins = [];
    foreach ($page['joins'] as $join) {
      $joins[$join['table']] = $join['type'];
    }

    $this->assertSame([
      'field_data_field_license_expiry'   => 'INNER',
      'field_data_field_rating_avg'       => 'LEFT',
      'field_data_field_rating_count'     => 'LEFT',
      'field_data_field_short_description' => 'LEFT',
      'field_data_field_hourly_rate'      => 'LEFT',
      // Both halves of the logo, and the second as LEFT as the first: a file
      // deleted from disk cannot drop the provider from the listing.
      'field_data_field_logo'             => 'LEFT',
      'file_managed'                      => 'LEFT',
    ], $joins);
  }

  /**
   * And the three ORDER BY criteria, in order: the nulls-last expression first
   * — always ASC, whatever `sort` says — then the requested column, then the
   * nid tie-break, always DESC.
   */
  public function testTheOrderByIsThreeCriteriaAndTheFirstIgnoresSort() {
    foreach ([['rating_avg', 'ra.field_rating_avg_value'], ['hourly_rate', 'hr.field_hourly_rate_value']] as $case) {
      list($order_by, $column) = $case;

      foreach (['asc' => 'ASC', 'desc' => 'DESC'] as $sort => $direction) {
        $_GET['order_by'] = $order_by;
        $_GET['sort'] = $sort;

        $this->listing([$this->provider(41, 'Plomería Torres')]);

        $queries = myapi_test_db_queries();
        $page = $queries[2];

        $this->assertSame([
          ['field' => 'order_is_null', 'direction' => 'ASC'],
          ['field' => $column, 'direction' => $direction],
          ['field' => 'n.nid', 'direction' => 'DESC'],
        ], $page['order'], $order_by . '|' . $sort);
        $this->assertSame(['order_is_null' => '(' . $column . ' IS NULL)'], $page['expressions'], $order_by . '|' . $sort);
      }
    }
  }

}
