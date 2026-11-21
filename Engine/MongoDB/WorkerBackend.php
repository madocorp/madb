<?php

namespace MADB\Engine\MongoDB;

/** Keep one MongoDB manager for command execution in a worker. */
final class WorkerBackend implements \MADB\Engine\WorkerBackend {

  private \MongoDB\Driver\Manager $manager;

  public function __construct(private readonly array $data) {
  }

  public function connect(): string {
    if (!extension_loaded('mongodb')) {
      throw new \RuntimeException('mongodb is not installed.');
    }
    $host = (string)($this->data['host'] ?? '');
    $port = (int)(($this->data['port'] ?? '') ?: 27017);
    if ($host === '' || $port < 1 || $port > 65535 || preg_match('/[\/?#@]/', $host)) {
      throw new \InvalidArgumentException('Invalid MongoDB host or port.');
    }
    $options = ['serverSelectionTimeoutMS' => max(1000, min(30000, (int)($this->data['timeout'] ?? 10) * 1000))];
    if (($this->data['username'] ?? '') !== '') {
      $options['username'] = $this->data['username'];
      $options['password'] = $this->data['password'] ?? '';
      $options['authSource'] = ($this->data['authDatabase'] ?? '') ?: 'admin';
    }
    if (strtolower((string)($this->data['tls'] ?? 'no')) === 'yes') {
      $options['tls'] = true;
    }
    if (($this->data['options'] ?? '') !== '') {
      $extra = json_decode($this->data['options'], true, 512, JSON_THROW_ON_ERROR);
      if (!is_array($extra) || !str_starts_with(ltrim($this->data['options']), '{')) {
        throw new \InvalidArgumentException('Driver options must be a JSON object.');
      }
      $options = array_replace($options, $extra);
    }
    $this->manager = new \MongoDB\Driver\Manager("mongodb://{$host}:{$port}", $options);
    return $this->test();
  }

  public function test(): string {
    $this->manager->executeCommand(($this->data['database'] ?? '') ?: 'admin', new \MongoDB\Driver\Command(['ping' => 1]));
    return 'MongoDB ping succeeded.';
  }

  public function query(string $text): array {
    $command = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
    if (!$command instanceof \stdClass) {
      throw new \InvalidArgumentException('MongoDB command must be a JSON object.');
    }
    $started = microtime(true);
    $cursor = $this->manager->executeCommand(($this->data['database'] ?? '') ?: 'admin', new \MongoDB\Driver\Command($command));
    $rows = [];
    foreach ($cursor as $document) {
      if (count($rows) >= 500) {
        break;
      }
      $rows[] = [json_encode($document, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)];
    }
    return [
      'columns' => ['document'],
      'rows' => $rows,
      'affectedRows' => null,
      'truncated' => count($rows) >= 500,
      'elapsedMs' => (int)round((microtime(true) - $started) * 1000),
    ];
  }

  /** MongoDB database selection has its own engine-specific flow. */
  public function selectDatabase(string $name): string {
    throw new \LogicException('MongoDB database selection is not available here.');
  }

}
