<?php

namespace MADB\Job;

class JobHandler {

  private static $directorSocket;
  private static $jobs = [];
  private static $jobId = 0;

  public static function init() {
    $socket = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($socket === false) {
      throw new \Exception('Creating socket pair failed!');
    }
    $pid = pcntl_fork();
    if ($pid == -1) {
      throw new \Exception('Could not fork!');
    } else if ($pid === 0) {
      fclose($socket[0]); // child closes parent end
      new JobDirector($socket[1]);
      exit(0);
    }
    fclose($socket[1]); // parent closes child end
    self::$directorSocket = $socket[0];
    stream_set_blocking(self::$directorSocket, false);
    cli_set_process_title('MADB');
  }

  public static function startJob($job) {
    if (isset($job['cache'])) {
      $cached = Cache::get($job['cache']);
      if ($cached !== false) {
        call_user_func($job['callback'], $cached);
        return -1;
      }
    }
    self::$jobId++;
    $jobId = self::$jobId;
    $job['times']['s'] = microtime(true);
    $job['jid'] = $jobId;
    self::$jobs[$jobId] = $job;
    Message::send(self::$directorSocket, $job);
    return $jobId;
  }

  public static function getResults() {
    $read = [self::$directorSocket];
    $write = [];
    $except = [];
    $n = stream_select($read, $write, $except, 0, 0);
    if ($n !== false && $n > 0) {
      foreach ($read as $socket) {
        $response = Message::receive($socket);
        if ($response === null) {
          throw new \Exception("JobDirector is dead");
        }
        if (!isset($response['jid'])) {
          throw new \Exception("Received message is not a valid job result");
        }
        $jobId = $response['jid'];
        if (isset(self::$jobs[$jobId])) {
          $job = self::$jobs[$jobId];
          $response['connection'] = $job['connection'];
          if (isset($job['cache']) && $job['status'] == 'OK') {
            $key = $this->job['cache'];
            Cache::set($key, $response);
          }
          if (isset($job['callback'])) {
            call_user_func($job['callback'], $response);
          }
          unset(self::$jobs[$jobId]);
        }
      }
    }
  }

}
