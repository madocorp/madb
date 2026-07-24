#!/usr/bin/env php
<?php

require_once __DIR__ . '/../Query/SqlSplitter.php';
require_once __DIR__ . '/../Query/SqlSelectLimiter.php';

$failures = 0;

$assertSame = function($actual, $expected, $message) use (&$failures) {
  if ($actual === $expected) {
    echo "OK  {$message}\n";
    return;
  }
  $failures++;
  echo "FAIL {$message}\n";
  echo "Expected:\n{$expected}\nActual:\n{$actual}\n";
};

$withIndexes = function(string $sql): array {
  $statements = \MADB\Query\SqlSplitter::split($sql);
  foreach ($statements as $index => $statement) {
    $statements[$index]['index'] = $index;
  }
  return $statements;
};

$editorSql = function(string $sql, $selected = false) use ($withIndexes): string {
  return \MADB\Query\SqlSelectLimiter::editorSql($sql, $withIndexes($sql), 1000, $selected);
};

$executionSql = fn(string $sql): string => \MADB\Query\SqlSelectLimiter::executionSql($sql);

$assertSame($editorSql('SELECT * FROM users'), "SELECT * FROM users\nLIMIT 1000", 'simple SELECT gets default limit');
$assertSame($editorSql('SELECT * FROM users LIMIT 50'), 'SELECT * FROM users LIMIT 50', 'existing LIMIT is preserved');
$assertSame($editorSql('SELECT * FROM users UNLIMITED'), 'SELECT * FROM users UNLIMITED', 'UNLIMITED suppresses editor limit');
$assertSame($executionSql('SELECT * FROM users UNLIMITED'), 'SELECT * FROM users', 'UNLIMITED is removed for execution');
$assertSame(
  $editorSql("SELECT * FROM users;\nSELECT * FROM logs UNLIMITED;\nUPDATE users SET name = 'Ada' WHERE id = 1"),
  "SELECT * FROM users\nLIMIT 1000;\nSELECT * FROM logs UNLIMITED;\nUPDATE users SET name = 'Ada' WHERE id = 1",
  'multiple statements normalize independently'
);
$assertSame(
  $editorSql("SELECT * FROM users;\nSELECT * FROM logs", [1]),
  "SELECT * FROM users;\nSELECT * FROM logs\nLIMIT 1000",
  'current-statement normalization changes only selected statement'
);
$assertSame(
  $editorSql("SELECT 'UNLIMITED' AS word -- UNLIMITED\nFROM users"),
  "SELECT 'UNLIMITED' AS word -- UNLIMITED\nFROM users\nLIMIT 1000",
  'UNLIMITED inside strings and comments does not suppress limiting'
);
$assertSame(
  $editorSql('SELECT unlimited FROM users'),
  "SELECT unlimited FROM users\nLIMIT 1000",
  'UNLIMITED used as a selected column does not suppress limiting'
);
$assertSame(
  $executionSql('SELECT unlimited FROM users'),
  'SELECT unlimited FROM users',
  'UNLIMITED used as a selected column is not removed for execution'
);
$assertSame(
  $editorSql('SELECT * FROM users WHERE id IN (SELECT user_id FROM logs LIMIT 1)'),
  "SELECT * FROM users WHERE id IN (SELECT user_id FROM logs LIMIT 1)\nLIMIT 1000",
  'nested LIMIT does not count as top-level LIMIT'
);
$assertSame(
  $editorSql('SELECT * FROM users FOR UPDATE'),
  "SELECT * FROM users LIMIT 1000\nFOR UPDATE",
  'automatic LIMIT is inserted before locking clause'
);
$assertSame($editorSql('DELETE FROM users WHERE id = 1'), 'DELETE FROM users WHERE id = 1', 'non-SELECT is unchanged');

if ($failures > 0) {
  echo "\n{$failures} SQL select limiter case(s) failed.\n";
  exit(1);
}

echo "\nSQL select limiter cases passed.\n";
