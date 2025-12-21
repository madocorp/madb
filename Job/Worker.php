<?php

namespace MADB\Job;

class Worker {

  const MAX_IDLE_TIME = 600;

  private $pid;
  private $socket;
  private $connection;
  private $timeStat;
  private $connected = false;

  public function __construct($socket) {
    cli_set_process_title('MADBworker');
    $this->pid = getmypid();
    $this->socket = $socket;
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
        'times' => $this->timeStat
      ]);
      $idleSince = microtime(true);
      if ($job['command'] === 'test') {
        break;
      }
    }
  }

  private function processJob($job) {
    if ($this->connection === null || $this->connection->data['name'] !== $job['connection']['name']) {
      $type = $job['connection']['type'];
      $className = "\MADB\Connection\Connection{$type}";
      $this->connection = new $className($job['connection']);
      $this->connection->connect();
      $this->timeStat['c'] = microtime(true);
    } else {
      $this->timeStat['c'] = microtime(true);
    }
    $this->connected = true;
    $command = $job['command'];
    $arguments = $job['arguments'] ?? [];
    if (method_exists($this->connection, $command)) {
      $result = $this->connection->$command(...$arguments);
    } else {
      $result = 'Unknown command';
    }
    $this->timeStat['q'] = $this->connection->queryTime;
    $this->timeStat['f'] = microtime(true);
    return $result;
  }

}
