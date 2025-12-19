<?php

namespace MADB\Connection;

class Worker {

  private $id;
  private $socket;
  private $connection;

  public function __construct($id, $socket) {
    cli_set_process_title('MADBworker');
    $this->id = $id;
    $this->socket = $socket;
    while (true) {
      $read = [$this->socket];
      $write = $except = [];
      $n = stream_select($read, $write, $except, 60);
      if ($n === false) {
        continue; // EINTR
      }
      if ($n === 0) {
        continue; // timeout, loop again
      }
      try {
        $job = JobDirector::recvMessage($this->socket);
      } catch (\Exception $e) {
        continue; // invalid json
      }
      if ($job === null) {
        break; // parent closed
      }
      try {
        $result = $this->processJob($job);
        $status = 'OK';
        $this->connection->endTime();
      } catch (\Exception $e) {
        $result = $e->getMessage();
        $status = 'ERROR';
        $this->connection->endTime();
      }
      JobDirector::sendMessage($this->socket, [
        'workerId' => $this->id,
        'connection' => $job['connection'],
        'status' => $status,
        'result' => $result,
        'timeStat' => $this->connection->getTimeStat()
      ]);
      if ($job['command'] === 'test') {
        $this->connection = null;
      }
    }
    exit(0);
  }

  protected function processJob($job) {
    if ($this->connection === null || $this->connection->data['name'] !== $job['connection']['name']) {
      $type = $job['connection']['type'];
      $className = "\MADB\Connection\Connection{$type}";
      $this->connection = new $className($job['connection']);
      $this->connection->startTime();
      $this->connection->connect();
      $this->connection->connectTime();
    } else {
      $this->connection->startTime();
    }
    $command = $job['command'];
    $arguments = $job['arguments'] ?? [];
    if (method_exists($this->connection, $command)) {
      $result = $this->connection->$command(...$arguments);
    }
    return $result;
  }

}
