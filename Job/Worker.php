<?php

namespace MADB\Job;

/** Runs individual database jobs in a worker process for the background job system. */
class Worker {

  const MAX_IDLE_TIME = 600;
  const PROGRESS_INTERVAL = 0.1;

  private $pid;
  private $socket;
  private $connection;
  private $timeStat;
  private $connected = false;
  private $interrupted = false;

  /** Initializes background job system state. */
  public function __construct($socket) {
    cli_set_process_title('MADBworker');
    $this->pid = getmypid();
    $this->socket = $socket;
    pcntl_async_signals(true);
    pcntl_signal(SIGUSR1, [$this, 'interrupt']);
    $idleSince = microtime(true);
    $end = false;
    while (!$end) {
      $read = [$this->socket];
      $write = $except = [];
      $n = stream_select($read, $write, $except, 60, 0);
      if ($n === false || $n === 0) { // EINTR or timeout
        if (microtime(true) - $idleSince > self::MAX_IDLE_TIME) {
          break;
        }
        continue;
      }
      try {
        $job = Message::receive($this->socket);
      } catch (\Exception $e) { // invalid json
        continue;
      }
      if ($job === false) {
        continue;
      }
      try {
        $this->timeStat = $job['times'];
        $this->timeStat['r'] = microtime(true);
        $this->interrupted = false;
        $result = $this->processJob($job);
        $status = 'OK';
      } catch (\Exception $e) {
        $result = $e->getMessage();
        $status = 'ERROR';
        if (!$this->connected) {
          $end = true;
        }
      }
      Message::send($this->socket, [
        'jid' => $job['jid'],
        'pid' => $this->pid,
        'status' => $status,
        'result' => $result,
        'serverInfo' => $this->serverInfo(),
        'times' => $this->timeStat
      ]);
      $idleSince = microtime(true);
      if ($job['command'] === 'test') {
        break;
      }
    }
  }

  /** Coordinates process job work in the background job system. */
  private function processJob($job) {
    if ($this->connection === null || $this->connection->data['name'] !== $job['connection']['name']) {
      $type = $job['connection']['engine'];
      $className = \MADB\Engine\EngineRegistry::connectionClass($type);
      $this->connection = new $className($job['connection']);
      $this->connection->connect();
      $this->timeStat['c'] = microtime(true);
      $this->sendServerInfo($job);
    } else {
      $this->timeStat['c'] = microtime(true);
    }
    $this->connected = true;
    $command = $job['command'];
    $arguments = $job['arguments'] ?? [];
    if ($command === 'queryBatch') {
      $lastProgressAt = 0;
      $pendingProgress = false;
      $arguments[] = function($result) use ($job, &$lastProgressAt, &$pendingProgress) {
        $now = microtime(true);
        $pendingProgress = $this->mergeProgressResults($pendingProgress, $result);
        if (!$this->shouldSendProgress($pendingProgress, $now, $lastProgressAt)) {
          return;
        }
        $lastProgressAt = $now;
        $times = $this->timeStat;
        $times['p'] = $now;
        Message::send($this->socket, [
          'jid' => $job['jid'],
          'pid' => $this->pid,
          'status' => 'PROGRESS',
          'progress' => true,
          'result' => $pendingProgress,
          'serverInfo' => $this->serverInfo(),
          'times' => $times
        ]);
        $pendingProgress = false;
      };
      $arguments[] = function() {
        pcntl_signal_dispatch();
        return $this->interrupted;
      };
    }
    if (method_exists($this->connection, $command)) {
      $result = $this->connection->$command(...$arguments);
    } else {
      $result = 'Unknown command';
    }
    $this->timeStat['q'] = $this->connection->queryTime;
    $this->timeStat['f'] = microtime(true);
    return $result;
  }

  /** Merges throttled batch progress so skipped callbacks do not leave stale RUNNING statements in the UI. */
  private function mergeProgressResults($pending, $result) {
    if (!is_array($pending)) {
      return $result;
    }
    if (!is_array($result)) {
      return $pending;
    }
    $merged = array_merge($pending, $result);
    $statements = [];
    foreach (array_merge($pending['statements'] ?? [], $result['statements'] ?? []) as $offset => $statement) {
      if (!is_array($statement)) {
        continue;
      }
      $index = (int)($statement['index'] ?? $offset);
      $statements[$index] = array_merge($statements[$index] ?? [], $statement, ['index' => $index]);
    }
    ksort($statements);
    $merged['statements'] = array_values($statements);
    return $merged;
  }

  /** Sends connection metadata to the director without completing the current job. */
  private function sendServerInfo($job): void {
    Message::send($this->socket, [
      'jid' => $job['jid'],
      'pid' => $this->pid,
      'internal' => 'serverInfo',
      'serverInfo' => $this->serverInfo()
    ]);
  }

  /** Returns current connection server metadata when available. */
  private function serverInfo() {
    if ($this->connection === null || !method_exists($this->connection, 'getServerInfo')) {
      return false;
    }
    return $this->connection->getServerInfo();
  }

  /** Keeps progress responsive without flooding the director and UI with one message per fast statement. */
  private function shouldSendProgress($result, float $now, float $lastProgressAt): bool {
    if ($lastProgressAt <= 0 || $now - $lastProgressAt >= self::PROGRESS_INTERVAL) {
      return true;
    }
    foreach (($result['statements'] ?? []) as $statement) {
      if (($statement['status'] ?? '') === 'ERROR') {
        return true;
      }
    }
    return false;
  }

  /** Requests the current batch to stop before starting another statement. */
  public function interrupt(): void {
    $this->interrupted = true;
  }

}
