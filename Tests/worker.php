<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADB');
require APP_DIR . '/SPTK/App.php';

use MADB\Job\Manager;

/** Poll until a worker calls back, failing on a timeout. */
function awaitResult(Manager $manager, callable $submit): array {
  $result = null;
  $submit(static function(array $response) use (&$result): void {
    $result = $response;
  });
  $deadline = microtime(true) + 10;
  while ($result === null && microtime(true) < $deadline) {
    $manager->poll();
    usleep(10000);
  }
  return $result ?? throw new RuntimeException('Worker response timed out.');
}

/** Assert a worker-session rule. */
function check(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$path = tempnam(sys_get_temp_dir(), 'madb-worker-');
$manager = new Manager(0.15);
try {
  $connection = ['id' => 'test-session', 'engine' => 'SQLite', 'path' => $path, 'timeout' => '1', 'initCommand' => ''];
  $open = awaitResult($manager, static function(callable $callback) use ($manager, $connection): void {
    $manager->open($connection, $callback);
  });
  check($open['ok'], 'App worker could not connect.');
  $appPid = $open['pid'];
  $reusedOpen = null;
  $alreadyOpen = $manager->open($connection, static function(array $response) use (&$reusedOpen): void {
    $reusedOpen = $response;
  });
  check($alreadyOpen && $reusedOpen === null, 'Selecting an open connection reported another connection result.');
  $appWork = awaitResult($manager, static function(callable $callback) use ($manager): void {
    $manager->submitApp('test-session', 'query', ['query' => 'SELECT 1'], $callback);
  });
  check($appWork['ok'] && $appWork['pid'] === $appPid, 'App work left its reserved worker.');
  $first = awaitResult($manager, static function(callable $callback) use ($manager): void {
    $manager->query('test-session', 'SELECT 1', $callback);
  });
  check($first['ok'] && $first['pid'] !== $appPid, 'User query used the app worker.');
  $reused = awaitResult($manager, static function(callable $callback) use ($manager): void {
    $manager->query('test-session', 'SELECT 2', $callback);
  });
  check($reused['ok'] && $reused['pid'] === $first['pid'], 'Idle query worker was not reused.');
  $parallel = [];
  for ($index = 0; $index < 2; $index++) {
    $manager->query('test-session', 'SELECT sqlite_version()', static function(array $response) use (&$parallel): void {
      $parallel[] = $response;
    });
  }
  $deadline = microtime(true) + 10;
  while (count($parallel) < 2 && microtime(true) < $deadline) {
    $manager->poll();
    usleep(10000);
  }
  check(count($parallel) === 2 && $parallel[0]['ok'] && $parallel[1]['ok'], 'Parallel queries did not complete.');
  check($parallel[0]['pid'] !== $parallel[1]['pid'], 'Busy query worker was reused.');
  $handle = null;
  $pinnedOpen = awaitResult($manager, static function(callable $callback) use ($manager, &$handle): void {
    $handle = $manager->pin('test-session', $callback);
  });
  check($pinnedOpen['ok'] && $pinnedOpen['pid'] !== $appPid, 'Pinned worker did not connect separately.');
  foreach (['CREATE TEMP TABLE session_value (value INTEGER)', 'INSERT INTO session_value VALUES (42)'] as $sql) {
    $result = awaitResult($manager, static function(callable $callback) use ($manager, $handle, $sql): void {
      $manager->queryPinned($handle, $sql, $callback);
    });
    check($result['ok'] && $result['pid'] === $pinnedOpen['pid'], 'Pinned query left its worker.');
  }
  $pinnedResult = awaitResult($manager, static function(callable $callback) use ($manager, $handle): void {
    $manager->queryPinned($handle, 'SELECT value FROM session_value', $callback);
  });
  check($pinnedResult['ok'] && $pinnedResult['result']['rows'] === [[42]], 'Pinned session lost its temporary table.');
  $unshared = awaitResult($manager, static function(callable $callback) use ($manager): void {
    $manager->query('test-session', 'SELECT value FROM session_value', $callback);
  });
  check(!$unshared['ok'] && $unshared['pid'] !== $pinnedOpen['pid'], 'Ordinary query used the pinned session.');
  usleep(200000);
  $manager->poll();
  $afterIdle = awaitResult($manager, static function(callable $callback) use ($manager): void {
    $manager->query('test-session', 'SELECT 3', $callback);
  });
  check($afterIdle['ok'] && $afterIdle['pid'] !== $first['pid'], 'Idle pooled worker did not expire.');
  $stillPinned = awaitResult($manager, static function(callable $callback) use ($manager, $handle): void {
    $manager->queryPinned($handle, 'SELECT value FROM session_value', $callback);
  });
  check($stillPinned['ok'] && $stillPinned['pid'] === $pinnedOpen['pid'], 'Pinned worker expired.');
  $stillApp = awaitResult($manager, static function(callable $callback) use ($manager): void {
    $manager->submitApp('test-session', 'test', [], $callback);
  });
  check($stillApp['ok'] && $stillApp['pid'] === $appPid, 'App worker expired.');
  $manager->release($handle);
  try {
    $manager->queryPinned($handle, 'SELECT 1', static function(array $response): void {
    });
    throw new LogicException('Released pinned handle was accepted.');
  } catch (RuntimeException $error) {
    check($error->getMessage() === 'Pinned session is not open.', 'Pinned release failed.');
  }
  $manager->close('test-session');
  $failed = [];
  $manager->open(array_replace($connection, ['path' => $path . '.missing']), static function(array $response) use (&$failed): void {
    $failed[] = $response;
  });
  $manager->submitApp('test-session', 'query', ['query' => 'SELECT 1'], static function(array $response) use (&$failed): void {
    $failed[] = $response;
  });
  $deadline = microtime(true) + 10;
  while (count($failed) < 2 && microtime(true) < $deadline) {
    $manager->poll();
    usleep(10000);
  }
  check(count($failed) === 2 && !$failed[0]['ok'] && !$failed[1]['ok'], 'Failed connection did not reject queued work.');
  $reopenResults = [];
  check(!$manager->open($connection, static function(array $response) use (&$reopenResults): void {
    $reopenResults[] = $response;
  }), 'A closed connection was reported as already open.');
  check(!$manager->open($connection, static function(array $response) use (&$reopenResults): void {
    $reopenResults[] = $response;
  }), 'A connection still opening was reported as ready.');
  $deadline = microtime(true) + 10;
  while (count($reopenResults) < 2 && microtime(true) < $deadline) {
    $manager->poll();
    usleep(10000);
  }
  check(count($reopenResults) === 2 && $reopenResults[0]['ok'] && $reopenResults[1]['ok']
    && $reopenResults[0]['pid'] === $reopenResults[1]['pid'], 'Selections during connection did not share the result.');
  $reopened = $reopenResults[0];
  $draft = awaitResult($manager, static function(callable $callback) use ($manager, $connection): void {
    $manager->testDraft(array_replace($connection, ['id' => '']), $callback);
  });
  check($draft['ok'] && $draft['pid'] !== $reopened['pid'], 'Draft test used the app worker.');
  $invalidDraft = awaitResult($manager, static function(callable $callback) use ($manager, $connection, $path): void {
    $manager->testDraft(array_replace($connection, ['id' => '', 'path' => $path . '.missing']), $callback);
  });
  check(!$invalidDraft['ok'], 'Invalid draft test did not report an error.');
  $routing = array_replace($connection, ['id' => 'database-routing', 'database' => 'named']);
  awaitResult($manager, static function(callable $callback) use ($manager, $routing): void {
    $manager->open($routing, $callback);
  });
  $named = awaitResult($manager, static function(callable $callback) use ($manager): void {
    $manager->query('database-routing', 'SELECT 1', $callback, 'named');
  });
  $withoutDatabase = awaitResult($manager, static function(callable $callback) use ($manager): void {
    $manager->query('database-routing', 'SELECT 2', $callback, '');
  });
  check($named['ok'] && $withoutDatabase['ok'] && $named['pid'] !== $withoutDatabase['pid'],
    'A query without a database reused a worker with one selected.');
  $namedAgain = awaitResult($manager, static function(callable $callback) use ($manager): void {
    $manager->query('database-routing', 'SELECT 3', $callback, 'named');
  });
  check($namedAgain['ok'] && $namedAgain['pid'] === $named['pid'], 'A query did not reuse its database worker.');
  echo "Worker session checks passed.\n";
} finally {
  $manager->closeAll();
  unlink($path);
}
