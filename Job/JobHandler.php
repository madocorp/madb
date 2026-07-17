<?php

namespace MADB\Job;

/** Client-side entry point for starting background jobs and polling their responses. */
class JobHandler {

  private static $directorSocket;
  private static $jobs = [];
  private static $jobId = 0;
  private static $controlCommands = ['countProcesses', 'killConnection', 'killProcess', 'getStatus'];

  /** Coordinates init work in the background job system. */
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

  /** Coordinates start job work in the background job system. */
  public static function startJob($job) {
    if (isset($job['cache'])) {
      $cached = Cache::get($job['connection']['name'], $job['cache']);
      if ($cached !== false) {
        if (isset($job['connection'])) {
          $cached['connection'] = $job['connection'];
        }
        if (isset($job['schema'])) {
          $cached['schema'] = $job['schema'];
        }
        if (isset($job['table'])) {
          $cached['table'] = $job['table'];
        }
        if (isset($job['targetSchema'])) {
          $cached['targetSchema'] = $job['targetSchema'];
        }
        if (isset($job['targetTable'])) {
          $cached['targetTable'] = $job['targetTable'];
        }
        if (isset($job['queryId'])) {
          $cached['queryId'] = $job['queryId'];
        }
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

  /** Returns results data used by the background job system. */
  public static function getResults() {
    $read = [self::$directorSocket];
    $write = [];
    $except = [];
    $n = stream_select($read, $write, $except, 0, 0);
    if ($n !== false && $n > 0) {
      foreach ($read as $socket) {
        $response = Message::receive($socket);
        if ($response === false) {
          continue;
        }
        if (!isset($response['jid'])) {
          throw new \Exception("Received message is not a valid job result");
        }
        $jobId = $response['jid'];
        if (isset(self::$jobs[$jobId])) {
          $job = self::$jobs[$jobId];
          if (isset($job['connection'])) {
            $response['connection'] = $job['connection'];
          }
          if (isset($job['schema'])) {
            $response['schema'] = $job['schema'];
          }
          if (isset($job['table'])) {
            $response['table'] = $job['table'];
          }
          if (isset($job['targetSchema'])) {
            $response['targetSchema'] = $job['targetSchema'];
          }
          if (isset($job['targetTable'])) {
            $response['targetTable'] = $job['targetTable'];
          }
          if (isset($job['queryId'])) {
            $response['queryId'] = $job['queryId'];
          }
          if (isset($job['rowContext'])) {
            $response['rowContext'] = $job['rowContext'];
          }
          if (isset($job['deleteContext'])) {
            $response['deleteContext'] = $job['deleteContext'];
          }
          if (isset($job['generatedQuery'])) {
            $response['generatedQuery'] = $job['generatedQuery'];
          }
          if (isset($job['cache']) && $response['status'] == 'OK') {
            $key = $job['cache'];
            Cache::set($job['connection']['name'], $key, $response);
          }
          if (isset($job['callback'])) {
            call_user_func($job['callback'], $response);
          }
          if (empty($response['progress'])) {
            unset(self::$jobs[$jobId]);
          }
        }
      }
    }
  }

  /** Coordinates count jobs work in the background job system. */
  public static function countJobs($connectionName) {
    $n = 0;
    foreach (self::$jobs as $job) {
      if ($job['connection']['name'] === $connectionName) {
        $n++;
      }
    }
    return $n;
  }

  /** Counts connection jobs that would be interrupted by killing connection workers. */
  public static function countInterruptibleJobs($connectionName) {
    $n = 0;
    foreach (self::$jobs as $job) {
      if (
        ($job['connection']['name'] ?? false) === $connectionName &&
        !in_array($job['command'] ?? false, self::$controlCommands, true)
      ) {
        $n++;
      }
    }
    return $n;
  }

  /** Returns job data used by the background job system. */
  public static function getJob($jobId) {
    return self::$jobs[$jobId] ?? false;
  }

}
