<?php

define('SPTK\DEBUG', false);
define('APP_PATH', __DIR__ . '/../madb.php');
define('APP_NAMESPACE', 'MADB');

require_once 'SPTK/Autoload.php';

$lines = [
  'diff --git a/file.php b/file.php',
  'index 1111111..2222222 100644',
  '--- a/file.php',
  '+++ b/file.php',
  '@@ -1,2 +1,2 @@ function test',
  '-old line',
  '+new line',
  ' unchanged'
];

$tokens = \SPTK\Tokenizer::start($lines, '\MADB\Tokenizer\GitDiff');
$expected = [
  'git-diff-file',
  'git-diff-header',
  'git-diff-file',
  'git-diff-file',
  'git-diff-row',
  'git-diff-delete',
  'git-diff-add',
  ''
];

foreach ($expected as $index => $style) {
  $actual = $tokens[$index]['tokens'][0]['style'] ?? false;
  if ($actual !== $style) {
    echo "FAIL: line {$index} expected {$style}, got {$actual}\n";
    exit(1);
  }
}

$hunkTokens = $tokens[4]['tokens'];
if (($hunkTokens[0]['value'] ?? '') !== '@@ -1,2 +1,2 @@' || ($hunkTokens[1]['style'] ?? false) !== '') {
  echo "FAIL: hunk trailing text should not use row style\n";
  exit(1);
}

echo "OK: Git diff tokenizer\n";
