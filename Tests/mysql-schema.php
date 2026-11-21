<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADB');
require APP_DIR . '/SPTK/App.php';

use MADB\Engine\MySQL\WorkerBackend;

/** Record USE without opening a real MySQL connection. */
final class RecordingPDO extends PDO {

  public string $statement = '';

  /** Skip PDO's network connection for this isolated check. */
  public function __construct() {
  }

  /** Capture the SQL sent on the existing PDO object. */
  public function exec(string $statement): int|false {
    $this->statement = $statement;
    return 0;
  }

}

$backend = new WorkerBackend([]);
$pdo = new RecordingPDO();
(new ReflectionProperty($backend, 'pdo'))->setValue($backend, $pdo);
$message = $backend->selectDatabase('my`schema');
if ($pdo->statement !== 'USE `my``schema`' || $message !== 'Selected database my`schema.') {
  throw new RuntimeException('Schema selection did not use the existing PDO session safely.');
}
echo "MySQL schema checks passed.\n";
