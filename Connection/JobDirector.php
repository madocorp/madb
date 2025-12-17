<?php

namespace MADB\Connection;

class JobDirector {

  const WORKERS = 3;

  protected static $workers = [];

  public static function init() {
    cli_set_process_title('MADB');
    for ($i = 0; $i < self::WORKERS; $i++) {
      self::$workers[$i] = new WorkerHandler($i);
    }
    register_shutdown_function(['MADB\Connection\JobDirector', 'end']);
  }

  public static function end() {
    echo "Terminate childrens\n";
    foreach (self::$workers as $worker) {
      $worker->stop();
    }
    echo "Wait for childrens\n";
    while (pcntl_waitpid(-1, $status, WNOHANG) > 0);
    echo "Nice exit\n";
  }

  public static function startJob($job) {
    $connectionName = $job['connection']['name'];
    for ($i = 0; $i < self::WORKERS; $i++) {
      $worker = self::$workers[$i];
      if ($worker->status == WorkerHandler::STATUS_CONNECTED && $worker->connectionName == $connectionName) {
        $worker->initJob($job);
        return true;
      }
    }
    for ($i = 0; $i < self::WORKERS; $i++) {
      $worker = self::$workers[$i];
      if ($worker->status == WorkerHandler::STATUS_IDLE) {
        $worker->initJob($job);
        return true;
      }
    }
    for ($i = 0; $i < self::WORKERS; $i++) {
      $worker = self::$workers[$i];
      if ($worker->status == WorkerHandler::STATUS_CONNECTED) {
        $worker->initJob($job);
        return true;
      }
    }
    return false;
  }

  public static function getStatus() {
    $read = [];
    $write = [];
    $except = [];
    foreach (self::$workers as $worker) {
      $read[] = $worker->socket;
    }
    $n = stream_select($read, $write, $except, 0, 0);
    if ($n !== false && $n > 0) {
      foreach ($read as $socket) {
        $response = self::recvMessage($socket);
        $id = $response['workerId'];
        self::$workers[$id]->finishJob($response);
        // do something ???
      }
    }
  }

  public static function sendMessage($socket, $data) {
    $json = json_encode($data, JSON_THROW_ON_ERROR);
    $len = strlen($json);
    fwrite($socket, pack('N', $len)); // 4-byte length
    fwrite($socket, $json);
  }

  public static function recvMessage($socket) {
    $header = fread($socket, 4);
    if ($header === '' || $header === false) {
      return null; // socket closed
    }
    $len = unpack('N', $header)[1];
    $json = '';
    while (strlen($json) < $len) {
      $chunk = fread($socket, $len - strlen($json));
      if ($chunk === false || $chunk === '') {
        return null;
      }
      $json .= $chunk;
    }
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
  }

  public static function debug() {
    echo "------------------\n";
    for ($i = 0; $i < self::WORKERS; $i++) {
      $worker = self::$workers[$i];
      echo "  worker {$i}: ";
      switch ($worker->status) {
        case WorkerHandler::STATUS_IDLE: echo 'IDLE'; break;
        case WorkerHandler::STATUS_CONNECTED: echo 'CONNECTED ', $worker->connectionName; break;
        case WorkerHandler::STATUS_WORKING: echo 'WORKING ', $worker->connectionName; break;
      }
      echo "\n";
    }
  }

}
