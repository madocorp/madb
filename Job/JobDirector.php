<?php

namespace MADB\Job;

class JobDirector {

  private $workers = [];
  private $directorSocket;
  private $deathReport = [];
  private $isChild = false;

  public function __construct($directorSocket) {
    $this->directorSocket = $directorSocket;
    cli_set_process_title('MADBJobDirector');
    register_shutdown_function([$this, 'end']);
    pcntl_signal(SIGCHLD, [$this, 'death']);
    $this->waitForMessage();
echo "JobDirector exited normally\n";
  }

  private function waitForMessage() {
    $ok = true;
    while ($ok) {
      $read = [$this->directorSocket];
      $write = [];
      $except = [];
      foreach ($this->workers as $worker) {
        if (!$worker->idle) {
          $read[] = $worker->socket;
        }
      }
      $n = @stream_select($read, $write, $except, 60, 0);
      while (!empty($this->deathReport)) {
        $msg = array_shift($this->deathReport);
        Message::send($this->directorSocket, $msg);
      }
      if ($n === false) {
        pcntl_signal_dispatch();
        continue;
      }
      if ($n == 0) {
        continue;
      }
      foreach ($read as $socket) {
        if ($socket === $this->directorSocket) {
          try {
            $job = Message::receive($socket);
          } catch (\Exception $e) {
            $ok = false;
            break;
          }
          if ($job === false) {
            continue;
          }
          if ($job['command'] === 'killConnection') {
            $this->killConnect($job);
          } else {
            $this->delegateJob($job);
          }
        } else {
          $workerResponse = Message::receive($socket);
          if ($workerResponse === false) {
            continue;
          }
          $this->forwardResponse($workerResponse);
        }
      }
    }
  }

  private function delegateJob($job) {
    $selectedWorker = false;
    foreach ($this->workers as $worker) {
      if ($worker->connectionName === $job['connection']['name'] && $worker->idle) {
        $selectedWorker = $worker;
        break;
      }
    }
    if ($selectedWorker === false) {
      $selectedWorker = new WorkerHandler($this);
      $this->workers[$selectedWorker->pid] = $selectedWorker;
    }
    $selectedWorker->startJob($job);
  }

  private function forwardResponse($workerResponse) {
    Message::send($this->directorSocket, $workerResponse);
    $pid = $workerResponse['pid'];
    $this->workers[$pid]->idle = true;
  }

  private function killConnect($job) {
    foreach ($this->workers as $worker) {
      if ($worker->connectionName === $job['connection']['name']) {
        posix_kill($worker->pid, SIGKILL);
      }
    }
  }

  public function end() {
    if ($this->isChild) {
      return;
    }
    foreach ($this->workers as $worker) {
      $worker->idle = true;
      posix_kill($worker->pid, SIGKILL);
      if (is_resource($worker->socket)) {
        fclose($worker->socket);
      }
    }
    while (!empty($this->workers)) {
      while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
        if (isset($this->workers[$pid])) {
          unset($this->workers[$pid]);
        }
      }
      usleep(10000);
    }
  }

  public function death() {
    while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
      if (isset($this->workers[$pid])) {
        $worker = $this->workers[$pid];
        if (is_resource($worker->socket)) {
          fclose($worker->socket);
        }
        if (!$worker->idle) {
          $this->deathReport[] = [
            'jid' => $worker->jid,
            'status' => 'ERROR',
            'result' => "Process {pid} has died"
          ];
        }
        unset($this->workers[$pid]);
      }
    }
  }

  public function cleanupInChild() {
    $this->workers = null;
    $this->directorSocket = null;
    $this->deathReport = null;
    $this->isChild = true;
    pcntl_signal(SIGCHLD, SIG_DFL);
  }

}
