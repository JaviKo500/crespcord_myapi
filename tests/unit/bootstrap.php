<?php

/**
 * @file
 * PHPUnit bootstrap for myapi unit tests.
 *
 * Lets tests `require` production .inc/.resource.inc files directly, outside
 * Drupal, with no copies. The only Drupal-level call these files make at file
 * scope (not inside a function) is module_load_include(), used by
 * resources/auth.resource.inc to pull in its includes/*.inc dependencies; this
 * stub makes that call a no-op so the require succeeds. Tests still `require`
 * the specific includes/*.inc files they actually exercise.
 *
 * If a future change adds another file-scope call to a Drupal function in one
 * of the .inc files exercised by tests/unit, this stub needs to grow to cover
 * it too — see tests/README.md.
 */

if (!function_exists('module_load_include')) {
  function module_load_include($type, $module, $name = NULL) {
    // No-op: unit tests require the relevant includes/*.inc files themselves.
  }
}
