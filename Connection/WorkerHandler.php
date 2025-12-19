<?php

namespace MADB\Connection;

class WorkerHandler {

  const STATUS_IDLE = 0;
  const STATUS_CONNECTED = 1;
  const STATUS_WORKING = 2;
  const STATUS_DEAD = 3;

  public $id;
  public $pid;
  public $socket;
  public $status;
  public $connectionName;
  public $connectionTime;
  public $lastEvent;
  public $job;

  public function __construct($id) {
    $this->id = $id;
    $socket = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($socket === false) {
      throw new \Exception('Creating socket pair failed!');
    }
    $this->pid = pcntl_fork();
    if ($this->pid == -1) {
      throw new \Exception('Could not fork!');
    } else if ($this->pid === 0) {
      fclose($socket[0]); // child closes parent end
      new Worker($this->id, $socket[1]);
    } else {
      fclose($socket[1]); // parent closes child end
      $this->socket = $socket[0];
      stream_set_blocking($this->socket, false);
    }
  }

  public function initJob($job) {
    $this->status = self::STATUS_WORKING;
    $this->job = $job;
    if ($this->connectionName != $job['connection']['name']) {
      $this->connectionName = $job['connection']['name'];
      $this->connectionTime = microtime(true);
    }
    $this->lastEvent= microtime(true);
    JobDirector::sendMessage($this->socket, $job);
  }

  public function finishJob($response) {
    if ($this->job === null) {
      return;
    }
    if (isset($this->job['cache'])) {
      $key = $this->job['cache'];
      QueryCache::set($key, $response);
    }
    call_user_func($this->job['callback'], $response);
    if ($this->job['command'] === 'test' ) {
      $this->connectionName = null;
      $this->connectionTime = null;
      $this->status = self::STATUS_IDLE;
    } else {
      $this->status = self::STATUS_CONNECTED;
    }
    $this->job = null;
    $this->lastEvent = microtime(true);
  }

  public function stop() {
    if ($this->pid !== 0) {
      posix_kill($this->pid, SIGTERM);
    }
  }

  public function check() {
    $data = fread($this->socket, 1);
    if ($data === '' && feof($this->socket)) {
      $this->status = self::STATUS_DEAD;
    }
  }

}
