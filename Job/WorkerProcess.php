<?php

namespace MADB\Job;

/** Own a fresh PHP subprocess and its buffered newline-delimited protocol. */
final class WorkerProcess {

  private $process;
  private array $pipes = [];
  private string $outgoing = '';
  private string $incoming = '';
  private string $errors = '';
  private bool $closed = false;

  /** Start a worker without putting connection secrets on the command line. */
  public function __construct() {
    $this->process = proc_open([PHP_BINARY, APP_DIR . '/Job/runner.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes, APP_DIR);
    if (!is_resource($this->process)) {
      throw new \RuntimeException('Could not start database worker.');
    }
    foreach ($this->pipes as $pipe) {
      stream_set_blocking($pipe, false);
    }
  }

  /** Queue one request for the child. */
  public function send(array $request): void {
    if ($this->closed) {
      throw new \RuntimeException('Database worker has stopped.');
    }
    $this->outgoing .= json_encode($request, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
  }

  /** Transfer available bytes and return all complete response frames. */
  public function poll(): array {
    if ($this->closed) {
      return [];
    }
    if ($this->outgoing !== '') {
      $written = @fwrite($this->pipes[0], $this->outgoing);
      if ($written === false) {
        $this->outgoing = '';
      } else {
        $this->outgoing = substr($this->outgoing, $written);
      }
    }
    $this->incoming .= stream_get_contents($this->pipes[1]) ?: '';
    $this->errors .= stream_get_contents($this->pipes[2]) ?: '';
    $responses = [];
    while (($end = strpos($this->incoming, "\n")) !== false) {
      $line = substr($this->incoming, 0, $end);
      $this->incoming = substr($this->incoming, $end + 1);
      try {
        $response = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($response)) {
          $responses[] = $response;
        }
      } catch (\JsonException $error) {
        $this->errors .= $error->getMessage();
      }
    }
    return $responses;
  }

  /** Report whether the child is still running. */
  public function running(): bool {
    return !$this->closed && proc_get_status($this->process)['running'];
  }

  /** Return a bounded diagnostic from child stderr. */
  public function error(): string {
    return trim(substr($this->errors, -1000));
  }

  /** Stop the process and close its pipes. */
  public function close(): void {
    if ($this->closed) {
      return;
    }
    $this->closed = true;
    if ($this->runningProcess()) {
      proc_terminate($this->process);
    }
    foreach ($this->pipes as $pipe) {
      fclose($pipe);
    }
    proc_close($this->process);
  }

  /** Avoid a status call after the process resource is closed. */
  private function runningProcess(): bool {
    return proc_get_status($this->process)['running'];
  }

  /** Clean up a worker even when the app exits unexpectedly. */
  public function __destruct() {
    $this->close();
  }

}
