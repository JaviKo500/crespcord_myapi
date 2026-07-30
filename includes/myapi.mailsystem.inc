<?php

/**
 * @file
 * Custom mail system for HTML email in the myapi module.
 *
 * Drupal 7's DefaultMailSystem::format() always runs the body through
 * drupal_html_to_text(), which would strip the markup of this module's HTML
 * emails. This class overrides two things and nothing else:
 *
 * - format(): joins the body parts and skips the html-to-text conversion, so
 *   the HTML document reaches the recipient as authored.
 * - mail(): hands the message over to the site's configured default mail
 *   system instead of inheriting DefaultMailSystem::mail(), which calls PHP's
 *   mail() and therefore needs a local MTA. This site sends through the smtp
 *   module (default-system = HTMLMailSystem__SmtpMailSystem) and has no
 *   sendmail binary, so inheriting the default delivery meant every myapi mail
 *   failed three times and was dropped. Delegating keeps delivery wherever the
 *   site administrator points it — SMTP today, anything else tomorrow — while
 *   this class keeps owning the formatting.
 *
 * Registered for the mail keys listed in myapi_html_mail_keys() (see
 * myapi.install), so every other mail sent by this site is untouched.
 */

class MyapiHtmlMailSystem extends DefaultMailSystem {

  /**
   * {@inheritdoc}
   */
  public function format(array $message) {
    $message['body'] = implode("\n\n", $message['body']);
    return $message;
  }

  /**
   * {@inheritdoc}
   *
   * Delivery is delegated to the site's default mail system; only the
   * formatting above belongs to this class. Note that the delegate's own
   * format() is deliberately NOT called: drupal_mail() already formatted the
   * message through this class, and running the site default's format() on top
   * would convert the HTML to plain text — the very thing this class exists to
   * avoid.
   *
   * Falls back to the inherited PHP mail() delivery when the site default is
   * missing, unusable, or is this very class (which would recurse forever).
   */
  public function mail(array $message) {
    $mail_system = variable_get('mail_system', array());
    $class = isset($mail_system['default-system'])
      ? $mail_system['default-system']
      : 'DefaultMailSystem';

    if ($class !== get_class($this) && class_exists($class)) {
      $delegate = new $class();
      if ($delegate instanceof MailSystemInterface) {
        return $delegate->mail($message);
      }
    }

    return parent::mail($message);
  }

}
