<?php

namespace MADB\Job;

/** Reserve app workers, reuse idle query workers, and support pinned user sessions. */
final class Manager {

  private array $workers = [];
  private array $appWorkers = [];
  private array $pinned = [];
  private array $connections = [];
  private int $nextWorker = 0;
  private int $nextRequest = 0;

  /** Set the idle lifetime for pooled workers, normally ten minutes. */
  public function __construct(private readonly float $idleTimeout = 600.0) {
    if ($idleTimeout <= 0) {
      throw new \InvalidArgumentException('Idle timeout must be positive.');
    }
  }

  /** Start or reuse the worker reserved for app browsing and metadata jobs. Return true when already connected. */
  public function open(array $connection, callable $callback): bool {
    $id = (string)($connection['id'] ?? '');
    if ($id === '') {
      throw new \InvalidArgumentException('Connection has no ID.');
    }
    if (isset($this->appWorkers[$id])) {
      $workerId = $this->appWorkers[$id];
      if (!$this->workers[$workerId]['process']->running()) {
        $this->fail($workerId, $this->workers[$workerId]['process']->error() ?: 'Database worker exited.');
      } else if ($this->workers[$workerId]['openResponse'] !== null) {
        return true;
      } else {
        $this->workers[$workerId]['openCallbacks'][] = $callback;
        return false;
      }
    }
    $workerId = $this->create($id, 'app', null, (string)($connection['database'] ?? ''));
    $this->appWorkers[$id] = $workerId;
    $this->connections[$id] = $connection;
    $this->workers[$workerId]['openCallbacks'][] = $callback;
    $this->enqueue($workerId, 'connect', ['connection' => $connection], function(array $response) use ($workerId): void {
      $this->workers[$workerId]['openResponse'] = $response;
      $callbacks = $this->workers[$workerId]['openCallbacks'];
      $this->workers[$workerId]['openCallbacks'] = [];
      foreach ($callbacks as $callback) {
        $callback($response);
      }
    });
    return false;
  }

  /** Run app-owned work on its reserved worker. */
  public function submitApp(string $id, string $operation, array $payload, callable $callback): void {
    if (!in_array($operation, ['test', 'query'], true)) {
      throw new \InvalidArgumentException('Unsupported worker operation.');
    }
    if (!isset($this->appWorkers[$id])) {
      throw new \RuntimeException('Connection is not open.');
    }
    $this->enqueue($this->appWorkers[$id], $operation, $payload, $callback);
  }

  /** Select a MySQL schema on the app worker and update its default for callers without a database. */
  public function selectDatabase(string $id, string $name, callable $callback): void {
    $this->requireConnection($id);
    if ($this->connections[$id]['engine'] !== 'MySQL' || $name === '') {
      throw new \InvalidArgumentException('Choose a MySQL database.');
    }
    $workerId = $this->appWorkers[$id];
    $this->enqueue($workerId, 'database', ['database' => $name], function(array $response) use ($id, $name, $workerId, $callback): void {
      if ($response['ok'] ?? false) {
        $this->connections[$id]['database'] = $name;
        $this->workers[$workerId]['database'] = $name;
      }
      $callback($response);
    });
  }

  /** Run a query in its saved database, reusing a compatible idle worker. */
  public function query(string $id, string $text, callable $callback, ?string $database = null): void {
    $this->requireConnection($id);
    $database ??= (string)($this->connections[$id]['database'] ?? '');
    foreach ($this->workers as $workerId => $worker) {
      if ($worker['connectionId'] === $id && $worker['kind'] === 'pool' && $worker['current'] === null && $worker['queue'] === []) {
        if (!$worker['process']->running()) {
          $this->fail($workerId, $worker['process']->error() ?: 'Database worker exited.');
          break;
        }
        if ($database === '' && $worker['database'] !== '') {
          continue;
        }
        $this->enqueuePoolQuery($workerId, $database, $text, $callback);
        return;
      }
    }
    $workerId = $this->create($id, 'pool', null, $database);
    $connection = array_replace($this->connections[$id], ['database' => $database]);
    $this->enqueue($workerId, 'connect', ['connection' => $connection], static function(array $response): void {
    });
    $this->enqueuePoolQuery($workerId, $database, $text, $callback);
  }

  /** Reserve a fresh worker for a user session until release is called. */
  public function pin(string $id, callable $callback): string {
    $this->requireConnection($id);
    $handle = bin2hex(random_bytes(12));
    $workerId = $this->create($id, 'pinned', $handle, (string)($this->connections[$id]['database'] ?? ''));
    $this->pinned[$handle] = $workerId;
    $this->enqueue($workerId, 'connect', ['connection' => $this->connections[$id]], $callback);
    return $handle;
  }

  /** Run a query only on the worker reserved by a pinned handle. */
  public function queryPinned(string $handle, string $text, callable $callback): void {
    $workerId = $this->pinned[$handle] ?? null;
    if ($workerId === null) {
      throw new \RuntimeException('Pinned session is not open.');
    }
    $this->enqueue($workerId, 'query', ['query' => $text], $callback);
  }

  /** End a pinned session and release its worker. */
  public function release(string $handle): void {
    if (isset($this->pinned[$handle])) {
      $this->remove($this->pinned[$handle]);
    }
  }

  /** Test unsaved editor values in a disposable worker. */
  public function testDraft(array $connection, callable $callback): void {
    $workerId = $this->create('draft:' . bin2hex(random_bytes(8)), 'test');
    $this->enqueue($workerId, 'connect', ['connection' => $connection], $callback);
  }

  /** Pump nonblocking I/O and deliver completed job responses. */
  public function poll(): void {
    foreach (array_keys($this->workers) as $workerId) {
      if (!isset($this->workers[$workerId])) {
        continue;
      }
      $process = $this->workers[$workerId]['process'];
      foreach ($process->poll() as $response) {
        if (!isset($this->workers[$workerId])) {
          break;
        }
        $current = $this->workers[$workerId]['current'];
        if ($current === null || $current['request']['id'] !== ($response['id'] ?? null)) {
          $this->fail($workerId, 'Worker returned an unexpected response.');
          break;
        }
        $this->workers[$workerId]['current'] = null;
        $current['callback']($response);
        if (!isset($this->workers[$workerId])) {
          break;
        }
        if (!$response['ok'] && ($current['request']['operation'] === 'connect' || $current['fatalOnError'])) {
          $this->fail($workerId, (string)($response['error'] ?? 'Connection failed.'));
          break;
        }
        $this->dispatch($workerId);
      }
      if (!isset($this->workers[$workerId])) {
        continue;
      }
      if (!$process->running()) {
        $this->fail($workerId, $process->error() ?: 'Database worker exited.');
      } else if ($this->workers[$workerId]['kind'] === 'test' && $this->workers[$workerId]['current'] === null) {
        $this->remove($workerId);
      } else if ($this->workers[$workerId]['kind'] === 'pool' && $this->workers[$workerId]['current'] === null && $this->workers[$workerId]['queue'] === []) {
        $this->workers[$workerId]['idleSince'] ??= microtime(true);
        if (microtime(true) - $this->workers[$workerId]['idleSince'] >= $this->idleTimeout) {
          $this->remove($workerId);
        }
      }
    }
  }

  /** Close every worker associated with one saved connection. */
  public function close(string $id): void {
    foreach (array_keys($this->workers) as $workerId) {
      if ($this->workers[$workerId]['connectionId'] === $id) {
        $this->remove($workerId);
      }
    }
    unset($this->connections[$id], $this->appWorkers[$id]);
  }

  /** Stop all worker processes when the app closes. */
  public function closeAll(): void {
    foreach (array_keys($this->workers) as $workerId) {
      $this->remove($workerId);
    }
    $this->connections = [];
    $this->appWorkers = [];
    $this->pinned = [];
  }

  /** Create and track one process for an explicit role. */
  private function create(string $id, string $kind, ?string $handle = null, string $database = ''): int {
    $workerId = ++$this->nextWorker;
    $this->workers[$workerId] = ['process' => new WorkerProcess(), 'connectionId' => $id, 'kind' => $kind, 'handle' => $handle, 'database' => $database, 'queue' => [], 'current' => null, 'idleSince' => null, 'openResponse' => null, 'openCallbacks' => []];
    return $workerId;
  }

  /** Apply a changed schema lazily before a pooled worker's next query. */
  private function enqueuePoolQuery(int $workerId, string $database, string $text, callable $callback): void {
    if ($database !== '' && $this->workers[$workerId]['database'] !== $database) {
      $this->enqueue($workerId, 'database', ['database' => $database], function(array $response) use ($workerId, $database): void {
        if ($response['ok'] ?? false) {
          $this->workers[$workerId]['database'] = $database;
        }
      }, true);
    }
    $this->enqueue($workerId, 'query', ['query' => $text], $callback);
  }

  /** Require a saved connection with an active app worker. */
  private function requireConnection(string $id): void {
    if (!isset($this->appWorkers[$id], $this->connections[$id])) {
      throw new \RuntimeException('Connection is not open.');
    }
  }

  /** Queue work and send the first item if the process is idle. */
  private function enqueue(int $workerId, string $operation, array $payload, callable $callback, bool $fatalOnError = false): void {
    $request = array_replace($payload, ['id' => ++$this->nextRequest, 'operation' => $operation]);
    $this->workers[$workerId]['queue'][] = ['request' => $request, 'callback' => $callback, 'fatalOnError' => $fatalOnError];
    $this->dispatch($workerId);
  }

  /** Keep only one outstanding request on each worker. */
  private function dispatch(int $workerId): void {
    if ($this->workers[$workerId]['current'] !== null || $this->workers[$workerId]['queue'] === []) {
      return;
    }
    $job = array_shift($this->workers[$workerId]['queue']);
    $this->workers[$workerId]['current'] = $job;
    $this->workers[$workerId]['idleSince'] = null;
    $this->workers[$workerId]['process']->send($job['request']);
  }

  /** Fail queued work when a worker dies or cannot connect. */
  private function fail(int $workerId, string $message): void {
    if (!isset($this->workers[$workerId])) {
      return;
    }
    $jobs = array_merge($this->workers[$workerId]['current'] === null ? [] : [$this->workers[$workerId]['current']], $this->workers[$workerId]['queue']);
    $this->remove($workerId);
    foreach ($jobs as $job) {
      $job['callback'](['ok' => false, 'error' => $message]);
    }
  }

  /** Remove a worker and any app or pinned mapping it owns. */
  private function remove(int $workerId): void {
    if (!isset($this->workers[$workerId])) {
      return;
    }
    $worker = $this->workers[$workerId];
    unset($this->workers[$workerId]);
    if (($this->appWorkers[$worker['connectionId']] ?? null) === $workerId) {
      unset($this->appWorkers[$worker['connectionId']], $this->connections[$worker['connectionId']]);
    }
    if ($worker['handle'] !== null) {
      unset($this->pinned[$worker['handle']]);
    }
    $worker['process']->close();
  }

}
