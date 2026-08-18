<?php

/**
 * @file
 * Internationalization helpers for the myapi module.
 *
 * Resolves the request language from a 'lang' query parameter, falling back to
 * the Accept-Language header, and translates system messages (errors and
 * optional success messages) through a small in-code catalogue. Only 'es' and
 * 'en' are supported; 'es' is the default.
 */

/**
 * Resolves the request language.
 *
 * A 'lang' query parameter takes priority over Accept-Language: it lets the
 * password/reset web page keep rendering in whatever language its email link
 * was generated in (see myapi_auth_password_forgot()), regardless of the
 * browser's own Accept-Language header. When no valid 'lang' parameter is
 * present, falls back to the first two characters of Accept-Language, and
 * defaults to 'es' when neither is present or valid. The result is cached in a
 * static variable so the language is resolved only once per request.
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

  if (isset($_GET['lang']) && is_string($_GET['lang'])) {
    $candidate = strtolower(substr($_GET['lang'], 0, 2));
    if (in_array($candidate, $allowed, TRUE)) {
      $lang = $candidate;
      return $lang;
    }
  }

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
      'unit_access_denied'  => 'You do not have access to this unit.',
      'condominium_access_denied' => 'You do not have access to this condominium.',
      'method_not_allowed'  => 'Method not allowed.',
      'missing_field'       => 'Missing required field: @field',
      'invalid_field'       => 'Invalid or missing field: @field',
      'field_too_long'      => 'Field too long: @field',
      'server_error'        => 'Internal server error.',
      'too_many_attempts'   => 'Too many attempts. Please try again later.',
      'field_too_short'     => 'Field too short: @field',
      'invalid_amount'         => 'The amount must be a number greater than zero.',
      'invalid_payment_method' => 'Invalid payment method.',
      'invalid_bank'           => 'Invalid bank.',
      'invalid_date'           => 'Invalid payment date.',
      'duplicate_reference'    => 'A payment with this reference already exists for this unit.',
      'invalid_file'           => 'Invalid file. Allowed: PDF, JPG, JPEG or PNG up to 5 MB.',
      'invalid_file_type'      => 'The file content does not match an allowed type.',
      'payment_created'        => 'Payment registered successfully.',
      'payment_not_found'      => 'Payment not found.',
      'payment_not_pending'    => 'Only a payment pending verification can be cancelled.',
      'payment_cancelled'      => 'Payment cancelled successfully.',
      'password_reset_requested'          => 'If the account exists, an email with instructions was sent.',
      'password_reset_success'            => 'Password updated successfully.',
      'password_reset_email_subject'      => 'Reset your password',
      'password_reset_email_greeting'     => 'Hi @name,',
      'password_reset_email_intro'        => 'We received a request to reset your CrespCord account password. Click the button below to continue.',
      'password_reset_email_expiry'       => 'This link expires in @minutes minutes. If you did not request this, you can ignore this email.',
      'password_reset_email_signoff'      => 'Best regards,<br>CrespCord Team',
      'password_reset_page_title'             => 'Reset your password',
      'password_reset_page_new_password_label' => 'New password',
      'password_reset_page_submit_button'      => 'Reset password',
      'password_reset_page_success'            => 'Your password has been updated. You can now log in from the app.',
      'password_reset_page_subtitle'           => 'Create a new password for your CrespCord account. It must be different from your previous ones.',
      'password_reset_page_confirm_label'      => 'Confirm password',
      'password_reset_page_req_length'         => 'At least 8 characters',
      'password_reset_page_req_upper'          => 'One uppercase letter',
      'password_reset_page_req_number'         => 'One number',
      'password_reset_page_req_symbol'         => 'One symbol',
      'notification_not_found'    => 'Notification not found.',
      'notification_marked_read'  => 'Notification marked as read.',
      'notifications_marked_read' => 'Notifications marked as read.',
      'reservation_role_not_allowed'  => 'You are not allowed to reserve this area with your current role.',
      'area_not_active'               => 'This area is not currently available for reservations.',
      'area_not_found'                => 'Area not found.',
      'reservation_outside_hours'     => "The requested time is outside the area's opening hours.",
      'reservation_crosses_midnight'  => 'This reservation would cross midnight but the area closes the same day.',
      'reservation_duration_exceeded' => "The requested duration exceeds the area's maximum allowed duration.",
      'reservation_overlap'           => 'This time slot overlaps with an existing reservation.',
      'area_capacity_full'            => 'This area has reached its reservation capacity for that time slot.',
      'reservation_already_active'    => 'This unit already has an active reservation for this area.',
      'insufficient_balance'          => 'You cannot make a reservation while you have an outstanding balance.',
      'reservation_created'           => 'Reservation created successfully.',
      'reservation_not_found'              => 'Reservation not found.',
      'reservation_forbidden'              => 'You are not allowed to cancel this reservation.',
      'reservation_not_confirmed'          => 'Only a confirmed reservation can be cancelled.',
      'reservation_cancel_window_expired'  => 'The cancellation window for this reservation has expired.',
      'reservation_cancelled'              => 'Reservation cancelled successfully.',
      'claim_not_found'                    => 'Claim not found.',
      'claim_created'                      => 'Claim created successfully.',
      'claim_updated'                      => 'Claim updated successfully.',
      'claim_edit_denied'                  => 'Only the requester can modify this claim.',
      'claim_not_editable'                 => 'This claim can no longer be modified because its status changed.',
      'claim_closed'                       => 'Claim closed successfully.',
      'claim_close_denied'                 => 'Only the requester can close this claim.',
      'claim_not_closable'                 => 'This claim can no longer be closed because its status changed.',
      'claim_too_many_images'              => 'Maximum 5 images per claim.',
      'claim_invalid_image'                => 'Invalid image. Allowed: JPG, JPEG or PNG up to 3 MB.',
      'claim_invalid_attachment'           => 'Invalid attachment. Allowed: PDF, DOC, DOCX, XLS or XLSX up to 3 MB.',
      'file_not_found'                     => 'File not found.',
      // SPEC 82. A key of its own and not a reuse of claim_not_found: they are
      // different messages for the end user, and the catalogue does not share
      // keys between resources. file_not_found IS reused, because "the file is
      // not there" reads the same for a claim and for a gallery.
      'provider_not_found'                 => 'Provider not found.',
      // SPEC 89. The two GENERIC keys of this catalogue, and the exception to
      // the per-resource rule right above: the spec names them 'not_found' and
      // 'forbidden' in its response tables and in its acceptance criteria, and
      // error_code is a contract the app branches on. The service request
      // detail is also the first endpoint where the two mean strictly different
      // things — 404 "it does not exist or is unpublished" versus 403 "it
      // exists and you may not read it" — and that distinction is the message,
      // whatever resource it is about.
      'not_found'                          => 'Not found.',
      'forbidden'                          => 'You do not have access to this resource.',
      // SPEC 90.
      'service_request_created'            => 'Service request created successfully.',
      'service_request_too_many_images'    => 'Maximum 5 images per service request.',
      'service_request_invalid_image'      => 'Invalid image. Allowed: JPG, JPEG or PNG up to 3 MB.',
      'service_request_invalid_attachment' => 'Invalid attachment. Allowed: PDF, DOC, DOCX, XLS or XLSX up to 3 MB.',
      'provider_not_eligible'              => 'The selected provider cannot receive this request.',
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
      'unit_access_denied'  => 'No tienes acceso a esta unidad.',
      'condominium_access_denied' => 'No tienes acceso a este condominio.',
      'method_not_allowed'  => 'Método no permitido.',
      'missing_field'       => 'Falta el campo requerido: @field',
      'invalid_field'       => 'Campo inválido o ausente: @field',
      'field_too_long'      => 'Campo demasiado largo: @field',
      'server_error'        => 'Error interno del servidor.',
      'too_many_attempts'   => 'Demasiados intentos. Inténtalo de nuevo más tarde.',
      'field_too_short'     => 'Campo demasiado corto: @field',
      'invalid_amount'         => 'El valor debe ser un número mayor que cero.',
      'invalid_payment_method' => 'Forma de pago inválida.',
      'invalid_bank'           => 'Banco inválido.',
      'invalid_date'           => 'Fecha de pago inválida.',
      'duplicate_reference'    => 'Ya existe un pago con esta referencia para esta vivienda.',
      'invalid_file'           => 'Archivo inválido. Permitidos: PDF, JPG, JPEG o PNG hasta 5 MB.',
      'invalid_file_type'      => 'El contenido del archivo no corresponde a un tipo permitido.',
      'payment_created'        => 'Pago registrado correctamente.',
      'payment_not_found'      => 'Pago no encontrado.',
      'payment_not_pending'    => 'Solo se puede anular un pago pendiente de verificar.',
      'payment_cancelled'      => 'Pago anulado correctamente.',
      'password_reset_requested'          => 'Si la cuenta existe, se envió un correo con instrucciones.',
      'password_reset_success'            => 'Contraseña actualizada correctamente.',
      'password_reset_email_subject'      => 'Restablece tu contraseña',
      'password_reset_email_greeting'     => 'Hola @name,',
      'password_reset_email_intro'        => 'Recibimos una solicitud para restablecer la contraseña de tu cuenta de CrespCord. Haz clic en el botón para continuar.',
      'password_reset_email_expiry'       => 'Este enlace expira en @minutes minutos. Si no solicitaste este cambio, puedes ignorar este correo.',
      'password_reset_email_signoff'      => 'Saludos,<br>Grupo CrespCord',
      'password_reset_page_title'             => 'Restablece tu contraseña',
      'password_reset_page_new_password_label' => 'Nueva contraseña',
      'password_reset_page_submit_button'      => 'Restablecer contraseña',
      'password_reset_page_success'            => 'Tu contraseña fue actualizada. Ya puedes iniciar sesión desde la app.',
      'password_reset_page_subtitle'           => 'Crea una nueva contraseña para tu cuenta de CrespCord. Debe ser distinta a las anteriores.',
      'password_reset_page_confirm_label'      => 'Confirmar contraseña',
      'password_reset_page_req_length'         => 'Al menos 8 caracteres',
      'password_reset_page_req_upper'          => 'Una letra mayúscula',
      'password_reset_page_req_number'         => 'Un número',
      'password_reset_page_req_symbol'         => 'Un símbolo',
      'notification_not_found'    => 'La notificación no existe.',
      'notification_marked_read'  => 'Notificación marcada como leída.',
      'notifications_marked_read' => 'Notificaciones marcadas como leídas.',
      'reservation_role_not_allowed'  => 'No tienes permitido reservar esta área con tu rol actual.',
      'area_not_active'               => 'Esta área no está disponible actualmente para reservas.',
      'area_not_found'                => 'Área no encontrada.',
      'reservation_outside_hours'     => 'El horario solicitado está fuera del horario de atención del área.',
      'reservation_crosses_midnight'  => 'Esta reserva cruzaría la medianoche y el área cierra el mismo día.',
      'reservation_duration_exceeded' => 'La duración solicitada excede la duración máxima permitida para el área.',
      'reservation_overlap'           => 'Este horario se cruza con una reserva existente.',
      'area_capacity_full'            => 'El área ya alcanzó su cupo de reservas para ese horario.',
      'reservation_already_active'    => 'Esta unidad ya tiene una reserva activa para esta área.',
      'insufficient_balance'          => 'No puedes hacer una reserva mientras tengas saldo pendiente.',
      'reservation_created'           => 'Reserva creada correctamente.',
      'reservation_not_found'              => 'Reserva no encontrada.',
      'reservation_forbidden'              => 'No tienes permitido cancelar esta reserva.',
      'reservation_not_confirmed'          => 'Solo se puede cancelar una reserva confirmada.',
      'reservation_cancel_window_expired'  => 'La ventana de cancelación de esta reserva ya expiró.',
      'reservation_cancelled'              => 'Reserva cancelada correctamente.',
      'claim_not_found'                    => 'Reclamo no encontrado.',
      'claim_created'                      => 'Reclamo creado correctamente.',
      'claim_updated'                      => 'Reclamo actualizado correctamente.',
      'claim_edit_denied'                  => 'Solo el solicitante puede modificar este reclamo.',
      'claim_not_editable'                 => 'Este reclamo ya no se puede modificar porque su estado cambió.',
      'claim_closed'                       => 'Reclamo cerrado correctamente.',
      'claim_close_denied'                 => 'Solo el solicitante puede cerrar este reclamo.',
      'claim_not_closable'                 => 'Este reclamo ya no se puede cerrar porque su estado cambió.',
      'claim_too_many_images'              => 'Máximo 5 imágenes por reclamo.',
      'claim_invalid_image'                => 'Imagen inválida. Permitidos: JPG, JPEG o PNG hasta 3 MB.',
      'claim_invalid_attachment'           => 'Adjunto inválido. Permitidos: PDF, DOC, DOCX, XLS o XLSX hasta 3 MB.',
      'file_not_found'                     => 'Archivo no encontrado.',
      'provider_not_found'                 => 'Proveedor no encontrado.',
      // SPEC 89 — see the note next to the English pair.
      'not_found'                          => 'No encontrado.',
      'forbidden'                          => 'No tienes acceso a este recurso.',
      // SPEC 90.
      'service_request_created'            => 'Solicitud de servicio creada correctamente.',
      'service_request_too_many_images'    => 'Máximo 5 imágenes por solicitud.',
      'service_request_invalid_image'      => 'Imagen inválida. Permitidos: JPG, JPEG o PNG hasta 3 MB.',
      'service_request_invalid_attachment' => 'Adjunto inválido. Permitidos: PDF, DOC, DOCX, XLS o XLSX hasta 3 MB.',
      'provider_not_eligible'              => 'El proveedor seleccionado no puede recibir esta solicitud.',
    ],
  ];

  // Safe fallback: unknown language or key returns the key itself.
  if (!isset($catalogue[$lang][$key])) {
    return $key;
  }

  $message = $catalogue[$lang][$key];

  return empty($replacements) ? $message : strtr($message, $replacements);
}
