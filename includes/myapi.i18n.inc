<?php

/**
 * @file
 * Internationalization helpers for the myapi module.
 *
 * Resolves the request language from the Accept-Language header and translates
 * system messages (errors and optional success messages) through a small
 * in-code catalogue. Only 'es' and 'en' are supported; 'es' is the default.
 */

/**
 * Resolves the request language from the Accept-Language header.
 *
 * Takes the first two characters of Accept-Language (lowercased) and validates
 * them against the whitelist. Falls back to 'es' when the header is missing or
 * the language is not supported. The result is cached in a static variable so
 * the language is resolved only once per request.
 *
 * @return string  Either 'es' or 'en'.
 */
function myapi_get_lang() {
  static $lang;

  if (isset($lang)) {
    return $lang;
  }

  $allowed = ['es', 'en'];
  $default = 'es';

  $header = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
  $candidate = strtolower(substr($header, 0, 2));

  $lang = in_array($candidate, $allowed, TRUE) ? $candidate : $default;

  return $lang;
}

/**
 * Translates a catalogue key into the resolved (or given) language.
 *
 * Looks up $key in the in-code catalogue for the target language and applies
 * the @placeholder replacements with strtr(). When the key does not exist in
 * the catalogue, the key itself is returned as a safe fallback so the response
 * never breaks (and missing keys are easy to spot in tests).
 *
 * @param string $key           Stable catalogue key (English, snake_case).
 * @param array  $replacements  Placeholder map, e.g. ['@field' => 'username'].
 * @param string $lang          Target language; defaults to myapi_get_lang().
 *
 * @return string  Translated, placeholder-substituted message.
 */
function myapi_t($key, $replacements = [], $lang = NULL) {
  if ($lang === NULL) {
    $lang = myapi_get_lang();
  }

  $catalogue = [
    'en' => [
      'invalid_credentials'    => 'Invalid username or password.',
      'invalid_token'          => 'Invalid token.',
      'unauthorized'           => 'Unauthorized.',
      'token_expired'          => 'Token has expired.',
      'user_not_found'         => 'User not found.',
      'logout_success'         => 'Logged out successfully.',
      'missing_authorization'  => 'No access token provided.',
      'missing_token'          => 'Token is required.',
      'method_not_allowed'  => 'Method not allowed.',
      'missing_field'       => 'Missing required field: @field',
      'invalid_field'       => 'Invalid or missing field: @field',
      'field_too_long'      => 'Field too long: @field',
      'server_error'        => 'Internal server error.',
      'too_many_attempts'   => 'Too many attempts. Please try again later.',
      'field_too_short'     => 'Field too short: @field',
      'password_reset_requested'          => 'If the account exists, an email with instructions was sent.',
      'password_reset_success'            => 'Password updated successfully.',
      'password_reset_email_subject'      => 'Reset your password',
      'password_reset_email_body'         => 'Use this link to reset your password: @link. This link expires in @minutes minutes. If you did not request this, you can ignore this email.',
      'password_reset_page_title'             => 'Reset your password',
      'password_reset_page_new_password_label' => 'New password',
      'password_reset_page_submit_button'      => 'Reset password',
      'password_reset_page_success'            => 'Your password has been updated. You can now log in from the app.',
    ],
    'es' => [
      'invalid_credentials'    => 'Usuario o contraseña incorrectos.',
      'invalid_token'          => 'Token inválido.',
      'unauthorized'           => 'No autorizado.',
      'token_expired'          => 'El token ha expirado.',
      'user_not_found'         => 'Usuario no encontrado.',
      'logout_success'         => 'Sesión cerrada correctamente.',
      'missing_authorization'  => 'No se proporcionó token de acceso.',
      'missing_token'          => 'Token requerido.',
      'method_not_allowed'  => 'Método no permitido.',
      'missing_field'       => 'Falta el campo requerido: @field',
      'invalid_field'       => 'Campo inválido o ausente: @field',
      'field_too_long'      => 'Campo demasiado largo: @field',
      'server_error'        => 'Error interno del servidor.',
      'too_many_attempts'   => 'Demasiados intentos. Inténtalo de nuevo más tarde.',
      'field_too_short'     => 'Campo demasiado corto: @field',
      'password_reset_requested'          => 'Si la cuenta existe, se envió un correo con instrucciones.',
      'password_reset_success'            => 'Contraseña actualizada correctamente.',
      'password_reset_email_subject'      => 'Restablece tu contraseña',
      'password_reset_email_body'         => 'Usa este enlace para restablecer tu contraseña: @link. Este enlace expira en @minutes minutos. Si no solicitaste este cambio, puedes ignorar este correo.',
      'password_reset_page_title'             => 'Restablece tu contraseña',
      'password_reset_page_new_password_label' => 'Nueva contraseña',
      'password_reset_page_submit_button'      => 'Restablecer contraseña',
      'password_reset_page_success'            => 'Tu contraseña fue actualizada. Ya puedes iniciar sesión desde la app.',
    ],
  ];

  // Safe fallback: unknown language or key returns the key itself.
  if (!isset($catalogue[$lang][$key])) {
    return $key;
  }

  $message = $catalogue[$lang][$key];

  return empty($replacements) ? $message : strtr($message, $replacements);
}
