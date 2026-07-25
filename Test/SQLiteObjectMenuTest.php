<?php

require_once __DIR__ . '/../Engine/EngineConnectionInterface.php';
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
  $pdo->exec('CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT)');
  $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, team_id INTEGER, name TEXT, FOREIGN KEY (team_id) REFERENCES teams(id))');
  $pdo->exec('CREATE INDEX users_name_idx ON users (name)');
  $pdo->exec('CREATE TRIGGER users_ai AFTER INSERT ON users BEGIN UPDATE users SET name = NEW.name WHERE id = NEW.id; END');
  $pdo->exec('CREATE VIEW active_users AS SELECT id, name FROM users');
  $pdo->exec("INSERT INTO teams (id, name) VALUES (1, 'Core')");
  $pdo->exec("INSERT INTO users (team_id, name) VALUES (1, 'Ada')");
  $pdo = null;

  $connection = new \MADB\Engine\SQLite\Connection([
    'name' => 'test-sqlite',
    'path' => $path
  ]);
  $connection->connect();

  $objects = $connection->tableList('main');
  $expected = [
    [
      'name' => 'teams',
      'type' => 'BASE TABLE'
    ],
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
  $assert(\MADB\Engine\SQLite\Connection::supportsOperation('rowInsert') === true, 'SQLite row insertion should be supported.');
  $assert(\MADB\Engine\SQLite\Connection::supportsOperation('rowUpdate') === true, 'SQLite row updating should be supported.');
  $assert(\MADB\Engine\SQLite\Connection::supportsOperation('rowDelete') === true, 'SQLite row deletion should be supported.');

  $definition = $connection->tableDefinition('main', 'users');
  $assert((int)($definition['table']['rows'] ?? 0) === 1, 'SQLite table definition should include row count.');
  $assert((int)($definition['table']['dataLength'] ?? 0) >= 0, 'SQLite table definition should include table size.');
  $assert((int)($definition['table']['indexLength'] ?? -1) >= 0, 'SQLite table definition should include index size.');
  $assert(count($definition['foreignKeys'] ?? []) === 1, 'SQLite table definition should include outgoing foreign keys.');
  $assert(count($definition['triggers'] ?? []) === 1, 'SQLite table definition should include triggers.');

  $referencedBy = $connection->tableReferencedBy('main', 'teams');
  $assert(count($referencedBy) === 1, 'SQLite tableReferencedBy should include incoming foreign keys.');

  $showCreate = $connection->showCreateTable('main', 'users');
  $showCreateSql = $showCreate['sql'] ?? '';
  $assert(str_contains($showCreateSql, 'CREATE TABLE users'), 'SQLite show create should include the table statement.');
  $assert(str_contains($showCreateSql, 'CREATE INDEX users_name_idx'), 'SQLite show create should include table indexes.');
  $assert(str_contains($showCreateSql, 'CREATE TRIGGER users_ai'), 'SQLite show create should include table triggers.');

  @unlink($path);
  fwrite(STDOUT, "OK: SQLite object menu metadata\n");
} catch (\Throwable $e) {
  @unlink($path);
  fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
  exit(1);
}
