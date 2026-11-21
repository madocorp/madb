<?php

namespace MADB\Engine\MySQL;

use MADB\Engine\SqlResult;

/** Keep one MySQL PDO connection for sequential session work. */
final class WorkerBackend implements \MADB\Engine\WorkerBackend {

  private \PDO $pdo;

  public function __construct(private readonly array $data) {
  }

  public function connect(): string {
    if (!extension_loaded('pdo_mysql')) {
      throw new \RuntimeException('pdo_mysql is not installed.');
    }
    $host = (string)($this->data['host'] ?? '');
    $port = (int)(($this->data['port'] ?? '') ?: 3306);
    $database = (string)($this->data['database'] ?? '');
    if ($host === '' || str_contains($host, ';') || $port < 1 || $port > 65535) {
      throw new \InvalidArgumentException('Invalid MySQL host or port.');
    }
    if (str_contains($database, ';') || str_contains($database, "\0")) {
      throw new \InvalidArgumentException('Invalid MySQL database name.');
    }
    $options = [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_TIMEOUT => max(1, min(30, (int)($this->data['timeout'] ?? 10))),
    ];
    foreach (['sslCA' => \PDO::MYSQL_ATTR_SSL_CA, 'sslCert' => \PDO::MYSQL_ATTR_SSL_CERT, 'sslKey' => \PDO::MYSQL_ATTR_SSL_KEY, 'sslCipher' => \PDO::MYSQL_ATTR_SSL_CIPHER] as $field => $attribute) {
      if (($this->data[$field] ?? '') !== '') {
        $options[$attribute] = $this->data[$field];
      }
    }
    $dsn = "mysql:host={$host};port={$port}" . ($database === '' ? '' : ";dbname={$database}");
    $this->pdo = new \PDO($dsn, ($this->data['username'] ?? '') ?: null, ($this->data['password'] ?? '') ?: null, $options);
    if (($this->data['initCommand'] ?? '') !== '') {
      $this->pdo->exec($this->data['initCommand']);
    }
    return $this->test();
  }

  public function test(): string {
    return 'Connected to MySQL ' . $this->pdo->query('SELECT VERSION()')->fetchColumn();
  }

  public function query(string $text): array {
    return SqlResult::run($this->pdo, $text);
  }

  /** Switch the current schema on this existing PDO session. */
  public function selectDatabase(string $name): string {
    if ($name === '' || str_contains($name, "\0")) {
      throw new \InvalidArgumentException('Choose a database name.');
    }
    $identifier = '`' . str_replace('`', '``', $name) . '`';
    $this->pdo->exec('USE ' . $identifier);
    return 'Selected database ' . $name . '.';
  }

}
