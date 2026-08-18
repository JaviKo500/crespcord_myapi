<?php

/**
 * @file
 * Generic upload/delete helpers for a node's file fields (SPEC 90).
 *
 * Moved here from resources/claim.resource.inc, where they were born as
 * myapi_claim_create_save_files() / myapi_claim_create_delete_files()
 * (SPEC 66): same remapping of $_FILES, same validators read from
 * field_info_field()/field_info_instance(), same real-MIME double-check, same
 * all-or-nothing cleanup — only the name changed, from 'claim' to 'node',
 * because this spec gives them a second consumer:
 * resources/service_request.resource.inc. Regla 3 de CLAUDE.md: shared logic
 * belongs in includes/, written once.
 *
 * One deliberate addition over the original: the "too many files" branch used
 * to answer a hardcoded 'claim_too_many_images', the only i18n key
 * myapi_claim_create_save_files() ever hardcoded instead of taking from a
 * parameter. With a second consumer that needs its own key
 * ('service_request_too_many_images'), that literal is promoted to the
 * $too_many_key parameter below, passed explicitly by every caller. Every
 * other line of logic is unchanged.
 *
 * resources/claim.resource.inc keeps calling these under their new names; it
 * carries no wrapper of the old ones, since nothing else called them by that
 * name.
 */

/**
 * Deletes a batch of just-saved managed files.
 *
 * The all-or-nothing cleanup primitive: used both inside
 * myapi_node_files_save() (when a later file of the SAME field fails) and by
 * its callers (when a later field of the same request fails after an earlier
 * one already saved). One place for the loop instead of two.
 *
 * @param array $files  file_managed objects, as returned by file_save().
 */
function myapi_node_files_delete(array $files) {
  foreach ($files as $file) {
    file_delete($file);
  }
}

/**
 * Validates and saves the files of one upload field, all-or-nothing.
 *
 * Serves any pair of upload fields shaped like 'field_images' (multi-file
 * input, $max_count > 1) and 'field_attachment' (single-file input,
 * $max_count = 1) — one function for both, since the only difference between
 * them is which Field API instance they read. $files is the raw $_FILES entry
 * for the input ($_FILES['images'] or $_FILES['attachment']): PHP gives a
 * multi-file input ('images[]') as parallel arrays keyed by index, and a
 * single-file input ('attachment') as one flat entry; both shapes are
 * normalized here into a list of individual files before anything is
 * validated or saved.
 *
 * Nothing about extensions or sizes is hardcoded: $field_name's Field API
 * field and instance (field_info_field() / field_info_instance()) are read to
 * build the same #upload_validators and #upload_location the native widget
 * would use (file_field_widget_upload_validators() / file_field_widget_uri()),
 * so every caller inherits the field's own limits instead of duplicating them.
 * The real-MIME whitelist is not hardcoded either: it is derived from
 * $validators['file_validate_extensions'][0] — the very string the extension
 * check above it already enforces, not a second read of
 * $instance['settings']['file_extensions'] under a different name — via
 * file_get_mimetype('dummy.<ext>'), Drupal core's own extension-to-mimetype
 * map.
 *
 * On the first failure (too many files, a rejected extension/size, or a MIME
 * mismatch) every file already saved BY THIS CALL is deleted with
 * myapi_node_files_delete() before myapi_error() ends the request: no
 * orphaned file_managed row survives a failed upload on its own.
 * $also_delete extends that same cleanup to files saved by an EARLIER call
 * within the same request — e.g. myapi_claim_create() passes its
 * already-saved $image_files here when it calls this function for the
 * attachment, so a rejected attachment does not leave the request's valid
 * images orphaned. myapi_error() never returns to its caller (it ends the
 * request with drupal_exit()), which is exactly why this cleanup has to
 * happen HERE, before it is called, rather than by the orchestrator
 * afterwards.
 *
 * @param string $field_name   Machine name of the field ('field_images' or
 *                              'field_attachment').
 * @param string $bundle       Node bundle the field is attached to ('reclamo',
 *                              'service_request', ...).
 * @param array  $files        The raw $_FILES[...] entry for this input, or
 *                              an empty array when nothing was sent.
 * @param int    $max_count     Maximum number of files allowed.
 * @param string $error_key     i18n key to answer when a file is rejected for
 *                               its extension or size. A MIME mismatch always
 *                               answers 'invalid_file_type' instead,
 *                               regardless of this parameter.
 * @param string $too_many_key  i18n key to answer when more than $max_count
 *                               files are sent for this field.
 * @param array  $also_delete   Managed file objects saved by an earlier field
 *                               of the SAME request, to delete together with
 *                               this call's own batch on any failure. Empty by
 *                               default.
 *
 * @return array  The saved, permanent file objects, in the order they were
 *                sent. Empty when $files carried nothing.
 */
function myapi_node_files_save($field_name, $bundle, array $files, $max_count, $error_key, $too_many_key, array $also_delete = []) {
  // Normalize into a flat list of individual file specs, whichever shape
  // $files arrived in.
  $items = [];
  if (isset($files['name']) && is_array($files['name'])) {
    foreach ($files['name'] as $delta => $name) {
      if ($name === '' || (int) $files['error'][$delta] === UPLOAD_ERR_NO_FILE) {
        continue;
      }
      $items[] = [
        'name'     => $files['name'][$delta],
        'type'     => $files['type'][$delta],
        'tmp_name' => $files['tmp_name'][$delta],
        'error'    => $files['error'][$delta],
        'size'     => $files['size'][$delta],
      ];
    }
  }
  elseif (isset($files['name']) && $files['name'] !== '' && (int) $files['error'] !== UPLOAD_ERR_NO_FILE) {
    $items[] = $files;
  }

  if (empty($items)) {
    return [];
  }

  if (count($items) > $max_count) {
    myapi_node_files_delete($also_delete);
    myapi_error($too_many_key, 422);
  }

  $field_info = field_info_field($field_name);
  $instance = field_info_instance('node', $field_name, $bundle);
  $validators = file_field_widget_upload_validators($field_info, $instance);
  $directory = file_field_widget_uri($field_info, $instance);

  if (!file_prepare_directory($directory, FILE_CREATE_DIRECTORY)) {
    myapi_node_files_delete($also_delete);
    myapi_error($error_key, 422);
  }

  // The extensions actually enforced by $validators itself
  // ('file_validate_extensions', the same key file_save_upload() reads),
  // rather than re-reading $instance['settings']['file_extensions'] under a
  // second name: this way the MIME whitelist can never drift from what the
  // extension check above it already validated, whichever key Drupal stores
  // it under.
  $extensions = isset($validators['file_validate_extensions'][0])
    ? preg_split('/\s+/', trim($validators['file_validate_extensions'][0]))
    : [];
  $allowed_mimes = [];
  foreach ($extensions as $extension) {
    $allowed_mimes[] = file_get_mimetype('dummy.' . $extension);
  }

  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $saved = [];

  foreach ($items as $delta => $item) {
    // Remap into the nested $_FILES['files'][...][<key>] shape
    // file_save_upload() expects, one key per item so N files of the same
    // input can each be uploaded on their own call.
    $form_key = $field_name . '_' . $delta;
    foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $part) {
      $_FILES['files'][$part][$form_key] = $item[$part];
    }

    $file = file_save_upload($form_key, $validators, $directory . '/');
    if (!is_object($file)) {
      myapi_node_files_delete($saved);
      myapi_node_files_delete($also_delete);
      if ($finfo) {
        finfo_close($finfo);
      }
      myapi_error($error_key, 422);
    }

    $real_path = drupal_realpath($file->uri);
    $mime = $finfo ? finfo_file($finfo, $real_path) : FALSE;
    if (!in_array($mime, $allowed_mimes, TRUE)) {
      file_delete($file);
      myapi_node_files_delete($saved);
      myapi_node_files_delete($also_delete);
      if ($finfo) {
        finfo_close($finfo);
      }
      myapi_error('invalid_file_type', 422);
    }

    $file->status = FILE_STATUS_PERMANENT;
    $saved[] = file_save($file);
  }

  if ($finfo) {
    finfo_close($finfo);
  }

  return $saved;
}
