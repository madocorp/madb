<?php

namespace MADB\Job;

/** Runs the background job director process that delegates work to workers and returns responses to the UI. */
class JobDirector {

  private $workers = [];
  private $directorSocket;
  private $deathReport = [];
  private $isChild = false;

  /** Initializes background job system state. */
  public function __construct($directorSocket) {
    $this->directorSocket = $directorSocket;
    cli_set_process_title('MADBJobDirector');
    register_shutdown_function([$this, 'end']);
    pcntl_signal(SIGCHLD, [$this, 'death']);
    $this->waitForMessage();
  }

  /** Reads and dispatches the next message sent to the job director socket. */
  private function waitForMessage() {
    $ok = true;
    while ($ok) {
      $read = [$this->directorSocket];
      $write = [];
      $except = [];
      foreach ($this->workers as $worker) {
        if ($worker->idle === false) {
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
          switch ($job['command']) {
            case 'killConnection':
              $this->killConnection($job);
              break;
            case 'killProcess':
              $this->killProcess($job);
              break;
            case 'killJob':
              $this->killJob($job);
              break;
            case 'interruptJob':
              $this->interruptJob($job);
              break;
            case 'countProcesses':
              $this->countProcesses($job);
              break;
            case 'getStatus':
              $this->getStatus($job);
              break;
            default:
              $this->delegateJob($job);
              break;
          }
        } else {
          try {
            $workerResponse = Message::receive($socket);
          } catch (\Exception $e) {
            $this->removeWorkerBySocket($socket, $e->getMessage());
            continue;
          }
          if ($workerResponse === false) {
            continue;
          }
          if (($workerResponse['internal'] ?? false) === 'serverInfo') {
            $this->updateWorkerServerInfo($workerResponse);
            continue;
          }
          $this->forwardResponse($workerResponse);
        }
      }
    }
  }

  /** Removes a worker whose socket closed or failed. */
  private function removeWorkerBySocket($socket, $message = 'Worker socket closed'): void {
    foreach ($this->workers as $pid => $worker) {
      if ($worker->socket !== $socket) {
        continue;
      }
      if (is_resource($worker->socket)) {
        fclose($worker->socket);
      }
      if ($worker->idle === false && empty($worker->killed)) {
        $this->deathReport[] = [
          'jid' => $worker->jid,
          'status' => 'ERROR',
          'result' => $message
        ];
      }
      unset($this->workers[$pid]);
      return;
    }
  }

  /** Delegates a received job to an available worker process. */
  private function delegateJob($job) {
    $selectedWorker = false;
    foreach ($this->workers as $worker) {
      if ($worker->connectionName === $job['connection']['name'] && $worker->idle !== false) {
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

  /** Forwards a worker response back to the waiting UI process. */
  private function forwardResponse($workerResponse) {
    $this->updateWorkerServerInfo($workerResponse);
    Message::send($this->directorSocket, $workerResponse);
    if (!empty($workerResponse['progress'])) {
      return;
    }
    $pid = $workerResponse['pid'];
    $this->workers[$pid]->idle = true;
    $this->workers[$pid]->since = microtime(true);
    $this->workers[$pid]->jid = false;
  }

  /** Stores server metadata reported by worker processes. */
  private function updateWorkerServerInfo($workerResponse): void {
    $pid = $workerResponse['pid'] ?? false;
    if ($pid === false || !isset($this->workers[$pid])) {
      return;
    }
    if (isset($workerResponse['serverInfo'])) {
      $this->workers[$pid]->serverInfo = $workerResponse['serverInfo'];
    }
  }

  /** Requests cancellation of all jobs for a connection. */
  private function killConnection($job) {
    $pids = [];
    foreach ($this->workers as $worker) {
      if ($worker->connectionName === $job['connection']['name']) {
        $pids[] = $worker->pid;
        posix_kill($worker->pid, SIGKILL);
      }
    }
    $response = [
      'jid' => $job['jid'],
      'status' => 'OK',
      'result' => $pids
    ];
    Message::send($this->directorSocket, $response);
  }

  /** Requests cancellation of a specific backend process. */
  private function killProcess($job) {
    foreach ($this->workers as $worker) {
      if ($worker->pid === $job['pid']) {
        posix_kill($worker->pid, SIGKILL);
        break;
      }
    }
    $response = [
      'jid' => $job['jid'],
      'status' => 'OK',
      'result' => $job['pid']
    ];
    Message::send($this->directorSocket, $response);
  }

  /** Requests hard termination of a worker selected by job id or process id. */
  private function killJob($job) {
    $targetJid = $job['targetJid'] ?? false;
    $targetPid = $job['pid'] ?? false;
    foreach ($this->workers as $worker) {
      if ($worker->idle !== false) {
        continue;
      }
      if ($targetJid !== false && (int)$worker->jid !== (int)$targetJid) {
        continue;
      }
      if ($targetJid === false && $targetPid !== false && (int)$worker->pid !== (int)$targetPid) {
        continue;
      }
      $killedJid = $worker->jid;
      $worker->killed = true;
      posix_kill($worker->pid, SIGKILL);
      Message::send($this->directorSocket, [
        'jid' => $killedJid,
        'pid' => $worker->pid,
        'status' => 'ERROR',
        'result' => 'Query worker killed.'
      ]);
      Message::send($this->directorSocket, [
        'jid' => $job['jid'],
        'status' => 'OK',
        'result' => [
          'pid' => $worker->pid,
          'jid' => $worker->jid
        ]
      ]);
      return;
    }
    Message::send($this->directorSocket, [
      'jid' => $job['jid'],
      'status' => 'ERROR',
      'result' => 'Running job was not found.'
    ]);
  }

  /** Requests a running batch to stop before starting another statement. */
  private function interruptJob($job) {
    $targetJid = $job['targetJid'] ?? false;
    $targetPid = $job['pid'] ?? false;
    foreach ($this->workers as $worker) {
      if ($worker->idle !== false) {
        continue;
      }
      if ($targetJid !== false && (int)$worker->jid !== (int)$targetJid) {
        continue;
      }
      if ($targetJid === false && $targetPid !== false && (int)$worker->pid !== (int)$targetPid) {
        continue;
      }
      $result = [
        'pid' => $worker->pid,
        'jid' => $worker->jid
      ];
      posix_kill($worker->pid, SIGUSR1);
      Message::send($this->directorSocket, [
        'jid' => $job['jid'],
        'status' => 'OK',
        'result' => $result
      ]);
      return;
    }
    Message::send($this->directorSocket, [
      'jid' => $job['jid'],
      'status' => 'ERROR',
      'result' => 'Running job was not found.'
    ]);
  }

  /** Counts running processes for a connection. */
  private function countProcesses($job) {
    $n = 0;
    foreach ($this->workers as $worker) {
      if ($worker->connectionName === $job['connection']['name']) {
        $n++;
      }
    }
    $response = [
      'jid' => $job['jid'],
      'status' => 'OK',
      'result' => $n
    ];
    Message::send($this->directorSocket, $response);
  }

  /** Returns status data used by the background job system. */
  private function getStatus($job) {
    $status = [];
    foreach ($this->workers as $worker) {
      $status[$worker->pid] = [
        'connectionName' => $worker->connectionName,
        'idle' => $worker->idle,
        'since' => $worker->since,
        'jid' => $worker->jid,
        'serverInfo' => $worker->serverInfo
      ];
    }
    $response = [
      'jid' => $job['jid'],
      'status' => 'OK',
      'result' => $status
    ];
    Message::send($this->directorSocket, $response);
  }

  /** Stops the job director loop and closes worker processes. */
  public function end() {
    if ($this->isChild) {
      return;
    }
    foreach ($this->workers as $worker) {
      $worker->idle = true;
      $worker->since = microtime(true);
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

  /** Handles job director shutdown from a signal. */
  public function death() {
    while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
      if (isset($this->workers[$pid])) {
        $worker = $this->workers[$pid];
        if (is_resource($worker->socket)) {
          fclose($worker->socket);
        }
        if ($worker->idle === false && empty($worker->killed)) {
          $this->deathReport[] = [
            'jid' => $worker->jid,
            'status' => 'ERROR',
            'result' => "Process {$pid} has died"
          ];
        }
        unset($this->workers[$pid]);
      }
    }
  }

  /** Closes inherited director resources after forking a child process. */
  public function cleanupInChild() {
    $this->workers = null;
    $this->directorSocket = null;
    $this->deathReport = null;
    $this->isChild = true;
    pcntl_signal(SIGCHLD, SIG_DFL);
  }

}
