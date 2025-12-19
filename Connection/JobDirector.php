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
    if (!isset($job['callback'])) {
      throw new \Exception('Job without callback!');
    }
    if (isset($job['cache'])) {
      $cached = QueryCache::get($job['cache']);
      if ($cached !== false) {
        call_user_func($job['callback'], $cached);
        return true;
      }
    }
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
      if ($worker->status == WorkerHandler::STATUS_WORKING) {
        $read[] = $worker->socket;
      }
    }
    if (empty($read)) {
      return;
    }
    $n = stream_select($read, $write, $except, 0, 0);
    if ($n !== false && $n > 0) {
      foreach ($read as $socket) {
        $response = self::recvMessage($socket);
        if ($response === null) {
          self::checkWorkers();
          continue;
        }
        $id = $response['workerId'];
        self::$workers[$id]->finishJob($response);
      }
    }
  }

  public static function checkWorkers() {
    foreach (self::$workers as $worker) {
      $worker->check();
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
      if ($chunk === '' || $chunk === false) {
        return null;
      }
      $json .= $chunk;
    }
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
  }

  public static function processInfo() {
    $info = [];
    $statuses = [
      null => 'IDLE',
      WorkerHandler::STATUS_IDLE => 'IDLE',
      WorkerHandler::STATUS_CONNECTED => 'CONNECTED',
      WorkerHandler::STATUS_WORKING => 'WORKING'
    ];
    for ($i = 0; $i < self::WORKERS; $i++) {
      $worker = self::$workers[$i];
      $info[$i] = [
        'status' => $statuses[$worker->status],
        'pid' => $worker->pid,
        'connectionName' => $worker->connectionName,
        'connected' => $worker->connectionTime,
        'lastEvent' => $worker->lastEvent,
        'currentJob' => $worker->job
      ];
    }
    return $info;
  }

  public static function debug() {
    $info = self::processInfo();
    echo "| ";
    foreach ($info as $i => $wrki) {
      echo "{$i}:{$wrki['status']} | ";
    }
    echo "\n";
  }

}
