<?php

namespace MADB\Connection;

class Worker {

  public $id;
  public $socket;

  public function __construct($id, $socket) {
    cli_set_process_title('MADBworker');
    $this->id = $id;
    $this->socket = $socket;
    while ($msg = JobDirector::recvMessage($this->socket)) {
      $this->job = $msg;
      $result = $this->processJob();
      JobDirector::sendMessage($this->socket, ['workerId' => $this->id, 'result' => $result]);
    }
    exit(0);
  }

  protected function processJob() {
    // set connection if different
    // run query
    sleep(rand(1, 5));
    return 'OK';
  }

}
