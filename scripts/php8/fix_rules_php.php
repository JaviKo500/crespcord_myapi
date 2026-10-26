<?php
/**
 * Make the PHP embedded in Rules configs safe on PHP 8.
 *
 * Usage: drush php-script fix_rules_php.php            (dry run: prints the changes)
 *        drush php-script fix_rules_php.php apply      (writes the changed rules)
 *
 * Rewrites, inside every Rules setting that contains "<?php":
 *   $node->field_x['und'][0]['value']   -> (isset($node->field_x['und'][0]['value']) ? $node->field_x['und'][0]['value'] : 0)
 *   if ($node->field_x['und'])          -> if (!empty($node->field_x['und']))
 *   round($var, 4) / round($var,4)      -> round((float) $var, 4)   (only plain variables)
 * The "echo" of a text field keeps an empty string instead of 0.
 */
$apply = function_exists('drush_get_arguments') && in_array('apply', drush_get_arguments(), TRUE);

function myfix_php($code, &$changes) {
  $out = $code;
  // echo of a text field: empty string fallback.
  $out = preg_replace(
    '/echo (\$node->field_[a-z0-9_]+)\[\'und\'\]\[0\]\[\'value\'\];/',
    'echo (isset($1[\'und\'][0][\'value\']) ? $1[\'und\'][0][\'value\'] : \'\');',
    $out, -1, $c1);
  // Comparison against a string: empty string fallback.
  $out = preg_replace(
    '/(\$node->field_[a-z0-9_]+)\[\'und\'\]\[0\]\[\'value\'\] == "/',
    '(isset($1[\'und\'][0][\'value\']) ? $1[\'und\'][0][\'value\'] : \'\') == "',
    $out, -1, $c2);
  // Any other single-value access: numeric fallback (guarded so already-safe code is not rewritten twice).
  $out = preg_replace(
    '/(?<!isset\()(?<!\? )(\$node->field_[a-z0-9_]+)\[\'und\'\]\[0\]\[\'value\'\]/',
    '(isset($1[\'und\'][0][\'value\']) ? $1[\'und\'][0][\'value\'] : 0)',
    $out, -1, $c3);
  // Truthiness of a whole field: !empty().
  $out = preg_replace(
    '/if \((\$node->field_[a-z0-9_]+)\[\'und\'\]\)/',
    'if (!empty($1[\'und\']))',
    $out, -1, $c4);
  // round() of a plain variable: cast so NULL becomes 0.0 without a deprecation.
  $out = preg_replace(
    '/round\((\$[a-z0-9_]+)\s*,\s*4\)/',
    'round((float) $1, 4)',
    $out, -1, $c5);
  $changes = array('value_echo' => $c1, 'value_cmp' => $c2, 'value_num' => $c3, 'notempty' => $c4, 'round' => $c5);
  return $out;
}

$walk = function ($el, $rule, &$touched) use (&$walk) {
  $s = $el->settings;
  if (is_array($s)) {
    foreach ($s as $k => $v) {
      if (is_string($v) && strpos($v, '<?php') !== FALSE) {
        $new = myfix_php($v, $changes);
        if ($new !== $v) {
          $el->settings[$k] = $new;
          $touched[] = $el->getElementName() . '.' . $k . ' ' . json_encode(array_filter($changes));
          // Lint the PHP of the rewritten snippet.
          $tmp = tempnam(sys_get_temp_dir(), 'rl');
          file_put_contents($tmp, $new);
          exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $o, $rc);
          unlink($tmp);
          if ($rc !== 0) { $touched[] = '   !! LINT FAILED: ' . implode(' ', $o); }
        }
      }
    }
  }
  if ($el instanceof RulesContainerPlugin) { foreach ($el as $child) { $walk($child, $rule, $touched); } }
};

$total = 0;
foreach (rules_config_load_multiple(FALSE) as $rule) {
  $touched = array();
  $walk($rule, $rule, $touched);
  if ($touched) {
    $total++;
    echo "RULE " . $rule->name . " (" . $rule->label() . ")\n";
    foreach ($touched as $t) echo "   $t\n";
    if ($apply) { $rule->save(); echo "   saved\n"; }
  }
}
echo ($apply ? "APPLIED" : "DRY RUN") . ": $total rules would change\n";
