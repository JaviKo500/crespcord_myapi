<?php

/**
 * @file
 * Reads a Clover report and decides whether the suite still covers the module.
 *
 * Usage:
 *   vendor/bin/phpunit --coverage-clover coverage.xml
 *   php scripts/coverage-gate.php coverage.xml
 *
 * Why a gate and not just a report (SPEC 124). The suite is 105 files and more
 * than 3,000 cases, and every one of them was written on purpose, which is
 * exactly the failure mode: nothing in this repository notices a resource that
 * arrives WITHOUT one. `drush cc all`, the endpoint works, the docs are
 * written, CI is green — and the file has never been executed by a test in its
 * life. The percentage alone does not catch that either: one new file among
 * sixty moves the total by less than the noise between two commits.
 *
 * So the sharp rule here is not the percentage, it is the second one: a file in
 * includes/ or resources/ that no test executes AT ALL fails the build, by
 * name. That is the check that fires the day the file is added, when writing
 * the test is still cheap, instead of two years later when it is the one file
 * nobody dares change.
 *
 * The percentage floor is the blunt companion, and it is a RATCHET: it starts
 * below where the suite already stands, and the number goes up as the suite
 * does. It is not a target — it is a promise that coverage does not fall.
 *
 * What this cannot say: a covered line is a line that RAN, not a line whose
 * behaviour was asserted. 100% here would still be compatible with a suite
 * that calls everything and checks nothing. It is a floor under the tests, not
 * a measure of them.
 */

/**
 * The lowest total line coverage the module may fall to, as a percentage.
 *
 * A ratchet, deliberately set below where the suite stands so that introducing
 * it did not turn CI red on the day it landed. The number CI prints is the one
 * to raise it to, minus a point or two of slack, and raising it is a one-line
 * diff a reviewer can see.
 */
const COVERAGE_FLOOR = 70.0;

/**
 * Files that are allowed to have no coverage at all, as name => why.
 *
 * One entry, and it is not a file anybody chose not to test: myapi.mailsystem.inc
 * declares a class that extends DefaultMailSystem, a Drupal core class, at file
 * scope. It cannot be loaded outside a site at all, which puts it out of reach
 * of tests/unit by construction rather than by neglect — what it does belongs
 * to tests/integration.
 *
 * Every other name that ever appears in this list is a decision to leave a file
 * untested, and it should be as uncomfortable to write as it looks.
 */
const UNCOVERABLE = [
  'includes/myapi.mailsystem.inc' => 'extends DefaultMailSystem at file scope; cannot be loaded outside a site',
];

/**
 * How many of the weakest files to list on a passing run.
 */
const WORST_LISTED = 12;

$report = isset($argv[1]) ? $argv[1] : 'coverage.xml';
if (!is_file($report)) {
  fwrite(STDERR, "coverage gate: no report at $report\n");
  fwrite(STDERR, "run: vendor/bin/phpunit --coverage-clover $report\n");
  exit(2);
}

$xml = simplexml_load_file($report);
if ($xml === FALSE) {
  fwrite(STDERR, "coverage gate: $report is not readable XML\n");
  exit(2);
}

// isset() on a class-less constant's offset is not valid in every PHP 7.x
// build; the local copy sidesteps it and costs nothing.
$uncoverable = UNCOVERABLE;

$root = dirname(__DIR__) . '/';
$files = [];
$total = 0;
$covered = 0;

foreach ($xml->xpath('//file') as $file) {
  $metrics = $file->metrics;
  if (!$metrics) {
    continue;
  }
  $name = str_replace($root, '', (string) $file['name']);
  $statements = (int) $metrics['statements'];
  $hit = (int) $metrics['coveredstatements'];

  $files[$name] = ['statements' => $statements, 'covered' => $hit];
  $total += $statements;
  $covered += $hit;
}

if (!$files) {
  fwrite(STDERR, "coverage gate: the report names no files — did the driver load?\n");
  exit(2);
}

$percentage = $total > 0 ? ($covered / $total) * 100 : 0.0;
$failures = [];

// Rule 1: no source file goes entirely unexecuted.
$dead = [];
foreach ($files as $name => $data) {
  if ($data['covered'] > 0 || $data['statements'] === 0) {
    continue;
  }
  if (isset($uncoverable[$name])) {
    continue;
  }
  $dead[] = $name;
}
if ($dead) {
  $failures[] = sprintf(
    "%d file(s) are not executed by any test:\n    %s\n\n  Write a test, or — if the file genuinely cannot be loaded outside a\n  site — add it to UNCOVERABLE in %s with the reason.",
    count($dead),
    implode("\n    ", $dead),
    'scripts/coverage-gate.php'
  );
}

// Rule 2: the total does not fall below the ratchet.
if ($percentage < COVERAGE_FLOOR) {
  $failures[] = sprintf(
    "total line coverage is %.2f%%, below the floor of %.2f%%.",
    $percentage,
    COVERAGE_FLOOR
  );
}

// The allowlist keeps no stale entries, the same way the suite's other
// allowlists do not: a file deleted or made testable and left here would
// silently exempt the next one to take its name.
foreach ($uncoverable as $name => $why) {
  if (!isset($files[$name])) {
    $failures[] = "UNCOVERABLE names $name, which the report does not cover — the entry is stale.";
    continue;
  }
  if ($files[$name]['covered'] > 0) {
    $failures[] = "UNCOVERABLE names $name, which IS covered now — drop the entry.";
  }
}

printf("Coverage: %.2f%% (%d/%d statements over %d files, floor %.2f%%)\n\n",
  $percentage, $covered, $total, count($files), COVERAGE_FLOOR);

uasort($files, function ($a, $b) {
  $left = $a['statements'] > 0 ? $a['covered'] / $a['statements'] : 1;
  $right = $b['statements'] > 0 ? $b['covered'] / $b['statements'] : 1;

  return $left == $right ? 0 : ($left < $right ? -1 : 1);
});

echo "Weakest files:\n";
$shown = 0;
foreach ($files as $name => $data) {
  if ($shown++ >= WORST_LISTED) {
    break;
  }
  $ratio = $data['statements'] > 0 ? ($data['covered'] / $data['statements']) * 100 : 100;
  printf("  %6.2f%%  %4d/%-4d  %s%s\n",
    $ratio,
    $data['covered'],
    $data['statements'],
    $name,
    isset($uncoverable[$name]) ? '  (allowlisted)' : ''
  );
}

if ($failures) {
  echo "\nCoverage gate FAILED:\n";
  foreach ($failures as $failure) {
    echo "\n  - " . $failure . "\n";
  }
  exit(1);
}

echo "\nCoverage gate passed.\n";
exit(0);
