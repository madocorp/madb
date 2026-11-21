<?php

namespace MADB\Engine\SQLite;

use MADB\Engine\SqlResult;

/** Keep one SQLite PDO connection for sequential session work. */
final class WorkerBackend implements \MADB\Engine\WorkerBackend {

  private \PDO $pdo;

  public function __construct(private readonly array $data) {
  }

  public function connect(): string {
    if (!extension_loaded('pdo_sqlite')) {
      throw new \RuntimeException('pdo_sqlite is not installed.');
    }
    $savedPath = (string)($this->data['path'] ?? '');
    $expanded = str_starts_with($savedPath, '~/') ? (getenv('HOME') ?: '') . substr($savedPath, 1) : $savedPath;
    $path = $expanded === '' ? false : realpath($expanded);
    if ($path === false || !is_file($path)) {
      throw new \RuntimeException('SQLite database file does not exist.');
    }
    $this->pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $this->pdo->exec('PRAGMA busy_timeout = ' . max(0, (int)($this->data['timeout'] ?? 60)) * 1000);
    if (($this->data['initCommand'] ?? '') !== '') {
      $this->pdo->exec($this->data['initCommand']);
    }
    return $this->test();
  }

  public function test(): string {
    return 'Connected to SQLite ' . $this->pdo->query('SELECT sqlite_version()')->fetchColumn();
  }

  public function query(string $text): array {
    return SqlResult::run($this->pdo, $text);
  }

  /** SQLite does not switch database files within a worker. */
  public function selectDatabase(string $name): string {
    throw new \LogicException('SQLite does not support selecting a database here.');
  }

}
