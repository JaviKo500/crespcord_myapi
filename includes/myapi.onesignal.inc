<?php

/**
 * @file
 * OneSignal REST API integration for the myapi module.
 *
 * Isolated transport layer: it knows nothing about bulletins or the
 * myapi_notifications table. It receives a list of OneSignal External User IDs
 * plus a title/body/data payload and performs the HTTP call. Push is
 * best-effort: any failure is logged to watchdog and never throws, so a slow or
 * down OneSignal never breaks the caller (node save / cron).
 *
 * Credentials are read from Drupal variables, never hardcoded:
 *   - myapi_onesignal_app_id        App ID of the OneSignal project.
 *   - myapi_onesignal_rest_api_key  REST API Key (secret).
 * Set them in settings.php ($conf['myapi_onesignal_app_id'] = '...';) or with
 * `drush vset`.
 */

/**
 * OneSignal REST API endpoint for creating notifications.
 */
define('MYAPI_ONESIGNAL_ENDPOINT', 'https://onesignal.com/api/v1/notifications');

/**
 * Maximum number of external ids OneSignal accepts per request.
 */
define('MYAPI_ONESIGNAL_MAX_EXTERNAL_IDS', 2000);

/**
 * Maximum length (in characters) of the push body. A longer body is truncated
 * and an ellipsis is appended so the banner stays compact on the device; the
 * total, ellipsis included, never exceeds this limit. The inbox row keeps the
 * full text — only the push is shortened.
 */
define('MYAPI_ONESIGNAL_MAX_BODY_LENGTH', 150);

/**
 * Truncates a push body to MYAPI_ONESIGNAL_MAX_BODY_LENGTH characters, appending
 * "..." when it had to cut. Multibyte-safe (drupal_strlen/drupal_substr), so an
 * accented body is never split mid-character. A trailing space before the cut is
 * trimmed for tidiness. The result, ellipsis included, never exceeds the limit.
 *
 * @param string $body  Plain-text push body.
 *
 * @return string  Body within the limit.
 */
function myapi_onesignal_truncate_body($body) {
  $body = (string) $body;
  if (drupal_strlen($body) <= MYAPI_ONESIGNAL_MAX_BODY_LENGTH) {
    return $body;
  }
  $ellipsis = '...';
  $keep = MYAPI_ONESIGNAL_MAX_BODY_LENGTH - drupal_strlen($ellipsis);
  return rtrim(drupal_substr($body, 0, $keep)) . $ellipsis;
}

/**
 * Whether both OneSignal credentials (app id + REST API key) are configured.
 *
 * @return bool
 */
function myapi_onesignal_is_configured() {
  return variable_get('myapi_onesignal_app_id', '') !== ''
    && variable_get('myapi_onesignal_rest_api_key', '') !== '';
}

/**
 * Sends a push notification through OneSignal, targeting users by their
 * External User ID (the app calls OneSignal.login(<uid>), so the external id is
 * the Drupal uid as a string).
 *
 * Best-effort: returns FALSE and logs a watchdog message on any problem
 * (missing config, HTTP error) but never throws.
 *
 * @param array  $external_ids  Drupal uids (int or string) to target.
 * @param string $title          Notification heading (bulletin subject).
 * @param string $body           Notification content (bulletin message).
 * @param array  $data           Deep-link payload forwarded verbatim to the app
 *                               (e.g. ['target' => 'bulletin', 'id' => 812,
 *                               'notification_type' => 'bulletin']).
 *
 * @return bool  TRUE if every chunk was accepted (2xx), FALSE otherwise.
 */
function myapi_onesignal_send(array $external_ids, $title, $body, array $data) {
  $app_id = variable_get('myapi_onesignal_app_id', '');
  $rest_api_key = variable_get('myapi_onesignal_rest_api_key', '');

  if ($app_id === '' || $rest_api_key === '') {
    watchdog('myapi', 'OneSignal push skipped: myapi_onesignal_app_id and/or myapi_onesignal_rest_api_key are not set.', [], WATCHDOG_WARNING);
    return FALSE;
  }

  $external_ids = array_values(array_filter($external_ids, 'strlen'));
  if (empty($external_ids)) {
    return FALSE;
  }

  // Keep the push banner compact; the inbox already stored the full text.
  $body = myapi_onesignal_truncate_body($body);

  $ok = TRUE;

  // Chunk to OneSignal's per-request cap.
  foreach (array_chunk($external_ids, MYAPI_ONESIGNAL_MAX_EXTERNAL_IDS) as $chunk) {
    $payload = [
      'app_id'                    => $app_id,
      'include_external_user_ids' => array_map('strval', $chunk),
      'headings'                  => ['en' => (string) $title, 'es' => (string) $title],
      'contents'                  => ['en' => (string) $body, 'es' => (string) $body],
      'data'                      => $data,
    ];

    $response = drupal_http_request(MYAPI_ONESIGNAL_ENDPOINT, [
      'method'  => 'POST',
      'headers' => [
        'Content-Type'  => 'application/json; charset=utf-8',
        'Authorization' => 'Basic ' . $rest_api_key,
      ],
      'data'    => drupal_json_encode($payload),
      'timeout' => 30,
    ]);

    $code = isset($response->code) ? (int) $response->code : 0;
    if ($code < 200 || $code >= 300) {
      $ok = FALSE;
      watchdog('myapi', 'OneSignal push failed: HTTP @code @error', [
        '@code'  => $code ?: 'none',
        '@error' => isset($response->error) ? $response->error : (isset($response->data) ? $response->data : ''),
      ], WATCHDOG_ERROR);
    }
  }

  return $ok;
}

/**
 * Queue worker for the OneSignal push queue (MYAPI_ONESIGNAL_QUEUE).
 *
 * Registered in myapi.module via hook_cron_queue_info(). Each queue item is one
 * batch of external ids plus the notification payload.
 *
 * Retry policy: if credentials are missing there is nothing to retry, so the
 * item is dropped with a warning. On a genuine transport failure the worker
 * throws, so the Queue API leaves the item claimed and retries it on the next
 * cron (accepted trade-off: a push may be delivered twice, the inbox never is).
 *
 * @param array $item  Queue payload: 'external_ids', 'title', 'body', 'data'.
 *
 * @throws Exception  On a transport failure, to trigger a Queue API retry.
 */
function myapi_onesignal_queue_worker($item) {
  if (empty($item['external_ids'])) {
    return;
  }

  if (!myapi_onesignal_is_configured()) {
    watchdog('myapi', 'OneSignal push item dropped: credentials not configured.', [], WATCHDOG_WARNING);
    return;
  }

  $ok = myapi_onesignal_send(
    $item['external_ids'],
    isset($item['title']) ? $item['title'] : '',
    isset($item['body']) ? $item['body'] : '',
    isset($item['data']) && is_array($item['data']) ? $item['data'] : []
  );

  if (!$ok) {
    throw new Exception('OneSignal push failed; the Queue API will retry on the next cron.');
  }
}
