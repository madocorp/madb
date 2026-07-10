#!/usr/bin/env php
<?php

define('SPTK\DEBUG', false);
define('APP_PATH', dirname(__DIR__) . '/madb.php');
define('APP_NAMESPACE', 'MADB');

require_once '../SPTK/Autoload.php';

use MADB\Query\SqlFormatter;

$caseFile = __DIR__ . '/SqlFormatterCases.sql';
$cases = parseCases($caseFile);
$failures = 0;

foreach ($cases as $case) {
  $actual = SqlFormatter::format($case['input']);
  if (normalizeSql($actual) === normalizeSql($case['expect'])) {
    echo "OK  {$case['name']}\n";
    continue;
  }

  $failures++;
  echo "FAIL {$case['name']}\n";
  echo diffText($case['expect'], $actual);
}

if ($failures > 0) {
  echo "\n{$failures} formatter case(s) failed.\n";
  exit(1);
}

echo "\n" . count($cases) . " formatter case(s) passed.\n";

/** Coordinates parse cases work in the SQL formatter. */
function parseCases(string $filename): array {
  $lines = file($filename, FILE_IGNORE_NEW_LINES);
  if ($lines === false) {
    throw new \RuntimeException("Unable to read {$filename}");
  }

  $cases = [];
  $case = null;
  $section = null;
  foreach ($lines as $lineNo => $line) {
    if (preg_match('/^-- CASE:\s*(.+)$/', $line, $match)) {
      if ($case !== null) {
        throw new \RuntimeException("Nested case at line " . ($lineNo + 1));
      }
      $case = [
        'name' => trim($match[1]),
        'input' => [],
        'expect' => []
      ];
      $section = null;
      continue;
    }

    if ($line === '-- INPUT') {
      requireCase($case, $lineNo);
      $section = 'input';
      continue;
    }

    if ($line === '-- EXPECT') {
      requireCase($case, $lineNo);
      $section = 'expect';
      continue;
    }

    if ($line === '-- END') {
      requireCase($case, $lineNo);
      $cases[] = [
        'name' => $case['name'],
        'input' => trim(implode("\n", $case['input'])),
        'expect' => trim(implode("\n", $case['expect']))
      ];
      $case = null;
      $section = null;
      continue;
    }

    if ($case !== null && $section !== null) {
      $case[$section][] = $line;
    }
  }

  if ($case !== null) {
    throw new \RuntimeException('Unclosed SQL formatter case: ' . $case['name']);
  }

  return $cases;
}

/** Coordinates require case work in the SQL formatter. */
function requireCase(?array $case, int $lineNo): void {
  if ($case === null) {
    throw new \RuntimeException("Formatter case marker outside a case at line " . ($lineNo + 1));
  }
}

/** Normalizes sql data for SQL formatter comparisons. */
function normalizeSql(string $sql): string {
  return rtrim(str_replace("\r\n", "\n", $sql));
}

/** Coordinates diff text work in the SQL formatter. */
function diffText(string $expected, string $actual): string {
  $expectedLines = explode("\n", normalizeSql($expected));
  $actualLines = explode("\n", normalizeSql($actual));
  $max = max(count($expectedLines), count($actualLines));
  $out = '';
  for ($i = 0; $i < $max; $i++) {
    $expectedLine = $expectedLines[$i] ?? null;
    $actualLine = $actualLines[$i] ?? null;
    if ($expectedLine === $actualLine) {
      continue;
    }
    $line = $i + 1;
    if ($expectedLine !== null) {
      $out .= sprintf("  - %3d %s\n", $line, $expectedLine);
    }
    if ($actualLine !== null) {
      $out .= sprintf("  + %3d %s\n", $line, $actualLine);
    }
  }
  return $out;
}
