<?php

require_once __DIR__ . '/../Connection/Connection.php';
require_once __DIR__ . '/../Engine/SQLite/Connection.php';

if (!extension_loaded('pdo_sqlite')) {
  fwrite(STDERR, "SKIP: pdo_sqlite is not available.\n");
  exit(0);
}

$path = tempnam(sys_get_temp_dir(), 'madb-sqlite-object-menu-');
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

try {
  $pdo = new \PDO('sqlite:' . $path);
  $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
  $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
  $pdo->exec('CREATE VIEW active_users AS SELECT id, name FROM users');
  $pdo->exec("INSERT INTO users (name) VALUES ('Ada')");
  $pdo = null;

  $connection = new \MADB\Engine\SQLite\Connection([
    'name' => 'test-sqlite',
    'path' => $path
  ]);
  $connection->connect();

  $objects = $connection->tableList('main');
  $expected = [
    [
      'name' => 'users',
      'type' => 'BASE TABLE'
    ],
    [
      'name' => 'active_users',
      'type' => 'VIEW'
    ]
  ];
  $assert($objects === $expected, 'SQLite object list should include user tables and views with menu-compatible types.');
  $assert(!in_array('sqlite_sequence', array_column($objects, 'name'), true), 'SQLite internal objects should be hidden from the object menu.');
  $assert(\MADB\Engine\SQLite\Connection::supportsOperation('tableCreate') === true, 'SQLite table creation should be supported.');
  $assert(\MADB\Engine\SQLite\Connection::supportsOperation('tableModify') === true, 'SQLite table modification should be supported.');
  $assert(\MADB\Engine\SQLite\Connection::supportsOperation('tableCopy') === true, 'SQLite table copying should be supported.');
  $assert(\MADB\Engine\SQLite\Connection::supportsOperation('tableDrop') === true, 'SQLite table and view dropping should be supported.');
  $assert(\MADB\Engine\SQLite\Connection::supportsOperation('viewCreate') === true, 'SQLite view creation should be supported.');
  $assert(\MADB\Engine\SQLite\Connection::supportsOperation('viewModify') === false, 'SQLite view modification should remain disabled.');
  $assert(\MADB\Engine\SQLite\Connection::supportsOperation('rowInsert') === false, 'SQLite row editing should remain disabled.');

  @unlink($path);
  fwrite(STDOUT, "OK: SQLite object menu metadata\n");
} catch (\Throwable $e) {
  @unlink($path);
  fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
  exit(1);
}
