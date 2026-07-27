<?php

/**
 * @file
 * Generic deferred mail queue for the myapi module.
 *
 * Isolated transport layer, reusable by any resource: it knows nothing about
 * reservations, payments or bulletins. A caller enqueues a drupal_mail() key, a
 * recipient and the already-resolved params; the worker performs the actual
 * send on the next cron run.
 *
 * Why a queue and not a synchronous drupal_mail(): N recipients are N SMTP
 * round trips. Sent inside the request, each one would add latency to the API
 * response and a stalled SMTP could hang the endpoint entirely. The accepted
 * trade-off is that the mail leaves on the next cron run.
 *
 * The params travel already resolved and escaped (strings, not nids), so the
 * message describes what was true at trigger time: a node deleted or renamed
 * between the trigger and the cron run neither breaks nor alters the send.
 */

/**
 * Queue name for deferred mail sending.
 *
 * The constant itself is defined in myapi.module (always loaded) because
 * hook_cron_queue_info() references it to register the worker; this file only
 * consumes it. The guard keeps a direct include of this file self-sufficient.
 */
if (!defined('MYAPI_MAIL_QUEUE')) {
  define('MYAPI_MAIL_QUEUE', 'myapi_mail_send');
}

/**
 * Maximum number of send attempts per mail item.
 *
 * A permanent SMTP rejection retried forever would repeat the same failing send
 * on every cron run; dropping on the first failure would lose legitimate mail to
 * a transient outage. A bounded counter covers both cases.
 */
define('MYAPI_MAIL_QUEUE_MAX_ATTEMPTS', 3);

/**
 * Enqueues one mail for deferred delivery.
 *
 * One item per recipient, so an invalid address never drags the rest of the
 * batch down. Best-effort: an invalid or empty recipient is logged and skipped,
 * never thrown.
 *
 * @param string $key     drupal_mail() key; must be handled by myapi_mail().
 * @param string $to      Recipient email address.
 * @param array  $params  Params for the formatter, already resolved and escaped.
 *
 * @return bool  TRUE if the item was enqueued, FALSE if the recipient was invalid.
 */
function myapi_mail_queue_enqueue($key, $to, array $params) {
  if (!valid_email_address($to)) {
    watchdog('myapi', 'Mail "@key" skipped: invalid or empty recipient.', [
      '@key' => $key,
    ], WATCHDOG_WARNING);
    return FALSE;
  }

  DrupalQueue::get(MYAPI_MAIL_QUEUE)->createItem([
    'key'      => $key,
    'to'       => $to,
    'params'   => $params,
    'attempts' => 0,
  ]);

  return TRUE;
}

/**
 * Queue worker: sends one mail item.
 *
 * Registered in myapi.module via hook_cron_queue_info().
 *
 * Retry policy: unlike the OneSignal worker, this one does not throw. A failed
 * send is re-enqueued with an incremented attempt counter up to
 * MYAPI_MAIL_QUEUE_MAX_ATTEMPTS, and dropped with an error afterwards, so a
 * permanently rejected address can never block the queue.
 *
 * @param array $item  Queue payload: 'key', 'to', 'params', 'attempts'.
 */
function myapi_mail_queue_worker($item) {
  if (empty($item['key']) || empty($item['to'])) {
    return;
  }

  $params = isset($item['params']) && is_array($item['params']) ? $item['params'] : [];
  $message = drupal_mail('myapi', $item['key'], $item['to'], language_default(), $params);

  if (empty($message['result'])) {
    $attempts = isset($item['attempts']) ? (int) $item['attempts'] + 1 : 1;

    if ($attempts < MYAPI_MAIL_QUEUE_MAX_ATTEMPTS) {
      $item['attempts'] = $attempts;
      DrupalQueue::get(MYAPI_MAIL_QUEUE)->createItem($item);
      watchdog('myapi', 'Mail "@key" to @to failed; retry @n queued.', [
        '@key' => $item['key'],
        '@to'  => $item['to'],
        '@n'   => $attempts,
      ], WATCHDOG_WARNING);
    }
    else {
      watchdog('myapi', 'Mail "@key" to @to dropped after @n attempts.', [
        '@key' => $item['key'],
        '@to'  => $item['to'],
        '@n'   => $attempts,
      ], WATCHDOG_ERROR);
    }
  }
}
