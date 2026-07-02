<?php

/**
 * @file
 * Custom mail system for HTML email in the myapi module.
 *
 * Drupal 7's DefaultMailSystem::format() always runs the body through
 * drupal_html_to_text(), which would strip the markup of the password reset
 * email. This class only overrides format() to skip that conversion; actual
 * sending still goes through DefaultMailSystem::mail(). Registered for the
 * 'myapi_password_reset' mail key only (see myapi.install), so every other
 * mail sent by this site keeps the default plain-text behavior.
 */

class MyapiHtmlMailSystem extends DefaultMailSystem {

  /**
   * {@inheritdoc}
   */
  public function format(array $message) {
    $message['body'] = implode("\n\n", $message['body']);
    return $message;
  }

}
