<?php

require_once __DIR__ . '/../Engine/EngineConnectionInterface.php';
require_once __DIR__ . '/../Connection/Connection.php';
require_once __DIR__ . '/../Engine/SQLite/Connection.php';
require_once __DIR__ . '/../Query/SqlSplitter.php';

if (!extension_loaded('pdo_sqlite')) {
  fwrite(STDERR, "SKIP: pdo_sqlite is not available.\n");
  exit(0);
}

$path = tempnam(sys_get_temp_dir(), 'madb-sqlite-row-editor-');
if ($path === false) {
  fwrite(STDERR, "FAIL: could not create temporary database file.\n");
  exit(1);
}

$assert = function($condition, $message) use ($path) {
  if ($condition) {
    return;
  }
  @unlink($path);
  fwrite(STDERR, "FAIL: {$message}\n");
  exit(1);
};

$queryBatch = function($connection, string $sql) {
  $statements = \MADB\Query\SqlSplitter::split($sql);
  foreach ($statements as $index => $statement) {
    $statements[$index]['index'] = $index;
  }
  return $connection->queryBatch($statements);
};

try {
  $pdo = new \PDO('sqlite:' . $path);
  $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
  $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, note TEXT NULL)');
  $pdo = null;

  $connection = new \MADB\Engine\SQLite\Connection([
    'name' => 'test-sqlite',
    'path' => $path
  ]);
  $connection->connect();

  $definition = $connection->rowEditorDefinition('main', 'users');
  $columns = $definition['columns'] ?? [];
  $assert(count($columns) === 3, 'SQLite row editor definition should return table columns.');
  $assert(($columns[0]['COLUMN_NAME'] ?? '') === 'id', 'SQLite row editor definition should preserve column order.');
  $assert(($columns[0]['COLUMN_KEY'] ?? '') === 'PRI', 'SQLite row editor definition should expose primary keys.');
  $assert(str_contains($columns[0]['EXTRA'] ?? '', 'auto_increment'), 'SQLite row editor definition should allow rowid primary keys to be omitted on insert.');
  $assert(\MADB\Engine\SQLite\Connection::supportsOperation('rowInsert') === true, 'SQLite row insertion should be supported.');
  $assert(\MADB\Engine\SQLite\Connection::supportsOperation('rowUpdate') === true, 'SQLite row updates should be supported.');
  $assert(\MADB\Engine\SQLite\Connection::supportsOperation('rowDelete') === true, 'SQLite row deletion should be supported.');

  $insert = $queryBatch($connection, "INSERT INTO `main`.`users` (`name`, `note`) VALUES ('Ada', NULL);");
  $assert(($insert['statements'][0]['status'] ?? '') === 'OK', 'SQLite row-editor INSERT SQL should execute.');

  $update = $queryBatch($connection, "UPDATE `main`.`users` SET `note` = 'edited' WHERE `id` = 1;");
  $assert(($update['statements'][0]['status'] ?? '') === 'OK', 'SQLite row-editor UPDATE SQL should execute.');

  $row = $connection->query('SELECT id, name, note FROM "main"."users" WHERE id = 1');
  $assert(($row['rows'][0]['note'] ?? '') === 'edited', 'SQLite row-editor UPDATE SQL should update the row.');

  $delete = $queryBatch($connection, "DELETE FROM `main`.`users` WHERE (`id` = 1);");
  $assert(($delete['statements'][0]['status'] ?? '') === 'OK', 'SQLite row-editor DELETE SQL should execute.');

  $count = $connection->query('SELECT COUNT(*) AS count FROM "main"."users"');
  $assert((int)($count['rows'][0]['count'] ?? -1) === 0, 'SQLite row-editor DELETE SQL should delete the row.');

  @unlink($path);
  fwrite(STDOUT, "OK: SQLite row editor\n");
} catch (\Throwable $e) {
  @unlink($path);
  fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
  exit(1);
}
