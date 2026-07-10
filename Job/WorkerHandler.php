<?php

namespace MADB\Job;

/** Starts and tracks worker processes owned by the background job director. */
class WorkerHandler {

  public $pid;
  public $jid;
  public $socket;
  public $connectionName = false;
  public $idle = true;
  public $since;

  /** Initializes background job system state. */
  public function __construct($jobDirector) {
    $this->since = microtime(true);
    $socket = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($socket === false) {
      throw new \Exception('Creating socket pair failed!');
    }
    $this->pid = pcntl_fork();
    if ($this->pid == -1) {
      throw new \Exception('Could not fork!');
    } else if ($this->pid === 0) {
      fclose($socket[0]); // child closes parent end
      $jobDirector->cleanupInChild();
      new Worker($socket[1]);
      exit(0);
    } else {
      fclose($socket[1]); // parent closes child end
      $this->socket = $socket[0];
      stream_set_blocking($this->socket, false);
    }
  }

  /** Coordinates start job work in the background job system. */
  public function startJob($job) {
    $this->idle = false;
    $this->since = microtime(true);
    $this->jid = $job['jid'];
    if ($this->connectionName === false) {
      $this->connectionName = $job['connection']['name'];
    }
    Message::send($this->socket, $job);
  }

}
