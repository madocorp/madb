<?php

namespace MADB\Job;

class WorkerHandler {

  public $pid;
  public $jid;
  public $socket;
  public $connectionName = false;
  public $idle = true;

  public function __construct($jobDirector) {
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

  public function startJob($job) {
    $this->idle = false;
    $this->jid = $job['jid'];
    if ($this->connectionName === false) {
      $this->connectionName = $job['connection']['name'];
    }
    Message::send($this->socket, $job);
  }

}
