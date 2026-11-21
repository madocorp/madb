<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADB');
require APP_DIR . '/SPTK/App.php';

use MADB\Engine\WorkerBackendFactory;

$backend = null;
while (($line = fgets(STDIN)) !== false) {
  $id = null;
  try {
    $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $id = $request['id'] ?? null;
    $operation = $request['operation'] ?? null;
    if ($operation === 'connect') {
      $backend = WorkerBackendFactory::create($request['connection']);
      $result = $backend->connect();
    } else if ($operation === 'test' && $backend !== null) {
      $result = $backend->test();
    } else if ($operation === 'query' && $backend !== null) {
      $result = $backend->query((string)($request['query'] ?? ''));
    } else if ($operation === 'database' && $backend !== null) {
      $result = $backend->selectDatabase((string)($request['database'] ?? ''));
    } else {
      throw new InvalidArgumentException('Unknown worker operation or worker is not connected.');
    }
    $response = ['id' => $id, 'ok' => true, 'result' => $result, 'pid' => getmypid()];
  } catch (Throwable $error) {
    $response = ['id' => $id, 'ok' => false, 'error' => $error->getMessage(), 'pid' => getmypid()];
  }
  try {
    fwrite(STDOUT, json_encode($response, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    fflush(STDOUT);
  } catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    break;
  }
}
