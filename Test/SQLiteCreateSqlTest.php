<?php

require_once __DIR__ . '/../Table/SQLiteTableCreateController.php';
require_once __DIR__ . '/../Table/SQLiteViewCreateController.php';
require_once __DIR__ . '/../Table/MenuStateTrait.php';
require_once __DIR__ . '/../Table/MenuRowsTrait.php';
require_once __DIR__ . '/../Table/MenuCopyTrait.php';
require_once __DIR__ . '/../Table/MenuDropTrait.php';
require_once __DIR__ . '/../Table/MenuController.php';

$assertSame = function($actual, $expected, $message) {
  if ($actual === $expected) {
    return;
  }
  fwrite(STDERR, "FAIL: {$message}\nExpected:\n" . var_export($expected, true) . "\nActual:\n" . var_export($actual, true) . "\n");
  exit(1);
};

$assertThrowsContains = function($callback, string $needle, string $message) {
  try {
    $callback();
  } catch (\InvalidArgumentException $e) {
    if (str_contains($e->getMessage(), $needle)) {
      return;
    }
    fwrite(STDERR, "FAIL: {$message}\nExpected exception message to contain:\n{$needle}\nActual:\n" . $e->getMessage() . "\n");
    exit(1);
  }
  fwrite(STDERR, "FAIL: {$message}\nExpected InvalidArgumentException.\n");
  exit(1);
};

$createColumns = [
  [
    'name' => 'id',
    'type' => 'INTEGER',
    'primary' => true,
    'notNull' => true,
    'autoincrement' => true,
    'default' => ''
  ],
  [
    'name' => 'name',
    'type' => 'TEXT',
    'primary' => false,
    'notNull' => true,
    'autoincrement' => false,
    'default' => 'anonymous'
  ],
  [
    'name' => 'score',
    'type' => 'NUMERIC',
    'primary' => false,
    'notNull' => false,
    'autoincrement' => false,
    'default' => '0'
  ]
];

$tableSql = \MADB\Table\SQLiteTableCreateController::buildCreateSql('main', 'users', $createColumns);

$assertSame(
  $tableSql,
  "CREATE TABLE \"main\".\"users\" (\n  \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,\n  \"name\" TEXT NOT NULL DEFAULT 'anonymous',\n  \"score\" NUMERIC DEFAULT 0\n);",
  'SQLite table create SQL should omit MySQL-only clauses and support SQLite column options.'
);

$tableSqlWithObjects = \MADB\Table\SQLiteTableCreateController::buildCreateSql('main', 'users', $createColumns, [
  [
    'INDEX_NAME' => 'users_name_idx',
    'NON_UNIQUE' => 1,
    'SEQ_IN_INDEX' => 1,
    'COLUMN_NAME' => 'name',
    'COLLATION' => 'A',
    'INDEX_TYPE' => 'BTREE'
  ]
], [
  [
    'CONSTRAINT_NAME' => 'users_team_fk',
    'COLUMN_NAME' => 'score',
    'REFERENCED_TABLE_SCHEMA' => 'main',
    'REFERENCED_TABLE_NAME' => 'teams',
    'REFERENCED_COLUMN_NAME' => 'id',
    'UPDATE_RULE' => 'CASCADE',
    'DELETE_RULE' => 'RESTRICT',
    'ORDINAL_POSITION' => 1
  ]
], [
  [
    'TRIGGER_NAME' => 'users_ai',
    'ACTION_TIMING' => 'AFTER',
    'EVENT_MANIPULATION' => 'INSERT',
    'ACTION_STATEMENT' => 'BEGIN SELECT NEW.id; END'
  ]
]);
$assertSame(
  $tableSqlWithObjects,
  "CREATE TABLE \"main\".\"users\" (\n  \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,\n  \"name\" TEXT NOT NULL DEFAULT 'anonymous',\n  \"score\" NUMERIC DEFAULT 0,\n  CONSTRAINT \"users_team_fk\" FOREIGN KEY (\"score\") REFERENCES \"teams\" (\"id\") ON UPDATE CASCADE ON DELETE RESTRICT\n);\n\nCREATE INDEX \"main\".\"users_name_idx\" ON \"users\" (\"name\");\n\nCREATE TRIGGER \"main\".\"users_ai\"\nAFTER INSERT ON \"users\"\nFOR EACH ROW\nBEGIN SELECT NEW.id; END;",
  'SQLite table create SQL should include SQLite-compatible indexes, foreign keys, and triggers.'
);

$viewSql = \MADB\Table\SQLiteViewCreateController::buildCreateSql('main', 'user_names', "SELECT\n  name\nFROM \"main\".\"users\";");
$assertSame(
  $viewSql,
  "CREATE VIEW \"main\".\"user_names\" AS\nSELECT\n  name\nFROM \"main\".\"users\";",
  'SQLite view create SQL should not include MySQL-only view clauses.'
);

$copyDefinition = [
  'table' => [
    'type' => 'BASE TABLE',
    'createSql' => $tableSql
  ],
  'columns' => array_map(fn($column) => ['COLUMN_NAME' => $column['name']], $createColumns)
];
$copyTableSql = \MADB\Table\MenuController::buildSQLiteCopySql('main', 'users', 'main', 'users_copy', $copyDefinition);
$assertSame(
  $copyTableSql,
  "CREATE TABLE \"main\".\"users_copy\" (\n  \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,\n  \"name\" TEXT NOT NULL DEFAULT 'anonymous',\n  \"score\" NUMERIC DEFAULT 0\n);\n\nINSERT INTO \"main\".\"users_copy\"\n  (\"id\",\n  \"name\",\n  \"score\")\nSELECT \"id\",\n  \"name\",\n  \"score\"\nFROM \"main\".\"users\";",
  'SQLite table copy SQL should rewrite the stored CREATE TABLE SQL and copy rows.'
);

$copyViewSql = \MADB\Table\MenuController::buildSQLiteCopySql('main', 'user_names', 'main', 'user_names_copy', [
  'table' => [
    'type' => 'VIEW',
    'createSql' => $viewSql
  ]
]);
$assertSame(
  $copyViewSql,
  "CREATE VIEW \"main\".\"user_names_copy\" AS\nSELECT\n  name\nFROM \"main\".\"users\";",
  'SQLite view copy SQL should rewrite the stored CREATE VIEW SQL.'
);

try {
  \MADB\Table\SQLiteTableCreateController::buildCreateSql('main', 'bad_table', [
    [
      'name' => 'id',
      'type' => 'TEXT',
      'primary' => true,
      'notNull' => false,
      'autoincrement' => true,
      'default' => ''
    ]
  ]);
  fwrite(STDERR, "FAIL: SQLite autoincrement should require INTEGER primary key.\n");
  exit(1);
} catch (\InvalidArgumentException $e) {
  // Expected.
}

$renamedColumns = $createColumns;
$renamedColumns[1]['name'] = 'full_name';
$renameColumnSql = \MADB\Table\SQLiteTableCreateController::buildAlterSql('main', 'users', 'users', $createColumns, $renamedColumns);
$assertSame(
  $renameColumnSql,
  'ALTER TABLE "main"."users" RENAME COLUMN "name" TO "full_name";',
  'SQLite modify should generate native rename-column SQL.'
);

$addedColumns = $renamedColumns;
$addedColumns[] = [
  'name' => 'active',
  'type' => 'INTEGER',
  'primary' => false,
  'notNull' => true,
  'autoincrement' => false,
  'default' => '1'
];
$addColumnSql = \MADB\Table\SQLiteTableCreateController::buildAlterSql('main', 'users', 'users', $renamedColumns, $addedColumns);
$assertSame(
  $addColumnSql,
  'ALTER TABLE "main"."users" ADD COLUMN "active" INTEGER NOT NULL DEFAULT 1;',
  'SQLite modify should generate native add-column SQL for appended fields.'
);

$droppedColumns = [$addedColumns[0], $addedColumns[1], $addedColumns[3]];
$dropColumnSql = \MADB\Table\SQLiteTableCreateController::buildAlterSql('main', 'users', 'users', $addedColumns, $droppedColumns);
$assertSame(
  $dropColumnSql,
  'ALTER TABLE "main"."users" DROP COLUMN "score";',
  'SQLite modify should generate native drop-column SQL.'
);

$renameTableSql = \MADB\Table\SQLiteTableCreateController::buildAlterSql('main', 'users', 'accounts', $droppedColumns, $droppedColumns);
$assertSame(
  $renameTableSql,
  'ALTER TABLE "main"."users" RENAME TO "accounts";',
  'SQLite modify should generate native rename-table SQL.'
);

$indexAlterSql = \MADB\Table\SQLiteTableCreateController::buildAlterSql('main', 'users', 'users', $createColumns, $createColumns, [], [
  [
    'INDEX_NAME' => 'users_name_idx',
    'NON_UNIQUE' => 0,
    'SEQ_IN_INDEX' => 1,
    'COLUMN_NAME' => 'name',
    'COLLATION' => 'A',
    'INDEX_TYPE' => 'BTREE'
  ]
]);
$assertSame(
  $indexAlterSql,
  'CREATE UNIQUE INDEX "main"."users_name_idx" ON "users" ("name");',
  'SQLite modify should generate native create-index SQL.'
);

$triggerAlterSql = \MADB\Table\SQLiteTableCreateController::buildAlterSql('main', 'users', 'users', $createColumns, $createColumns, [], [], [], [], [], [
  [
    'TRIGGER_NAME' => 'users_ai',
    'ACTION_TIMING' => 'AFTER',
    'EVENT_MANIPULATION' => 'INSERT',
    'ACTION_STATEMENT' => 'BEGIN SELECT NEW.id; END'
  ]
]);
$assertSame(
  $triggerAlterSql,
  "CREATE TRIGGER \"main\".\"users_ai\"\nAFTER INSERT ON \"users\"\nFOR EACH ROW\nBEGIN SELECT NEW.id; END;",
  'SQLite modify should generate native create-trigger SQL.'
);

$assertThrowsContains(function() use ($createColumns) {
  \MADB\Table\SQLiteTableCreateController::buildAlterSql('main', 'users', 'users', $createColumns, $createColumns, [], [], [], [
    [
      'CONSTRAINT_NAME' => 'users_team_fk',
      'COLUMN_NAME' => 'score',
      'REFERENCED_TABLE_SCHEMA' => 'main',
      'REFERENCED_TABLE_NAME' => 'teams',
      'REFERENCED_COLUMN_NAME' => 'id',
      'UPDATE_RULE' => 'RESTRICT',
      'DELETE_RULE' => 'RESTRICT',
      'ORDINAL_POSITION' => 1
    ]
  ]);
}, 'requires rebuilding', 'SQLite foreign-key changes should require table rebuild on modify.');

$assertThrowsContains(function() use ($createColumns) {
  $changedColumns = $createColumns;
  $changedColumns[1]['type'] = 'NUMERIC';
  \MADB\Table\SQLiteTableCreateController::buildAlterSql('main', 'users', 'users', $createColumns, $changedColumns);
}, 'requires rebuilding', 'SQLite type changes should require table rebuild.');

$assertThrowsContains(function() use ($createColumns) {
  $reordered = [$createColumns[1], $createColumns[0], $createColumns[2]];
  \MADB\Table\SQLiteTableCreateController::buildAlterSql('main', 'users', 'users', $createColumns, $reordered);
}, 'field order', 'SQLite column reordering should require table rebuild.');

$assertThrowsContains(function() use ($createColumns) {
  $reorderedAfterDrop = [$createColumns[2], $createColumns[0]];
  \MADB\Table\SQLiteTableCreateController::buildAlterSql('main', 'users', 'users', $createColumns, $reorderedAfterDrop);
}, 'field order', 'SQLite column reordering mixed with deletion should require table rebuild.');

$assertThrowsContains(function() use ($createColumns) {
  $reorderedWithAdd = [$createColumns[1], $createColumns[0], $createColumns[2], [
    'name' => 'active',
    'type' => 'INTEGER',
    'primary' => false,
    'notNull' => false,
    'autoincrement' => false,
    'default' => ''
  ]];
  \MADB\Table\SQLiteTableCreateController::buildAlterSql('main', 'users', 'users', $createColumns, $reorderedWithAdd);
}, 'field order', 'SQLite column reordering mixed with additions should require table rebuild.');

$assertThrowsContains(function() use ($createColumns) {
  $middleAdded = $createColumns;
  array_splice($middleAdded, 1, 0, [[
    'name' => 'middle_name',
    'type' => 'TEXT',
    'primary' => false,
    'notNull' => false,
    'autoincrement' => false,
    'default' => ''
  ]]);
  \MADB\Table\SQLiteTableCreateController::buildAlterSql('main', 'users', 'users', $createColumns, $middleAdded);
}, 'requires rebuilding', 'SQLite middle-column additions should be rejected.');

if (extension_loaded('pdo_sqlite')) {
  $path = tempnam(sys_get_temp_dir(), 'madb-sqlite-create-sql-');
  if ($path === false) {
    fwrite(STDERR, "FAIL: could not create temporary database file.\n");
    exit(1);
  }
  try {
    $pdo = new \PDO('sqlite:' . $path);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec($tableSql);
    $pdo->exec("INSERT INTO \"main\".\"users\" (name, score) VALUES ('Ada', 5)");
    $pdo->exec($viewSql);
    $pdo->exec($copyTableSql);
    $pdo->exec($copyViewSql);
    $objects = $pdo->query("SELECT name, type FROM sqlite_schema WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(\PDO::FETCH_ASSOC);
    $assertSame($objects, [
      [
        'name' => 'users',
        'type' => 'table'
      ],
      [
        'name' => 'users_copy',
        'type' => 'table'
      ],
      [
        'name' => 'user_names',
        'type' => 'view'
      ],
      [
        'name' => 'user_names_copy',
        'type' => 'view'
      ]
    ], 'Generated SQLite create SQL should execute successfully.');
    $copiedName = $pdo->query('SELECT name FROM "main"."users_copy" WHERE id = 1')->fetchColumn();
    $assertSame($copiedName, 'Ada', 'Generated SQLite copy SQL should copy table rows.');
  } catch (\Throwable $e) {
    @unlink($path);
    fwrite(STDERR, 'FAIL: generated SQLite create SQL did not execute: ' . $e->getMessage() . "\n");
    exit(1);
  }
  @unlink($path);

  $path = tempnam(sys_get_temp_dir(), 'madb-sqlite-alter-sql-');
  if ($path === false) {
    fwrite(STDERR, "FAIL: could not create temporary database file.\n");
    exit(1);
  }
  try {
    $pdo = new \PDO('sqlite:' . $path);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec($tableSql);
    $pdo->exec($renameColumnSql);
    $pdo->exec($addColumnSql);
    if (version_compare((string)$pdo->query('SELECT sqlite_version()')->fetchColumn(), '3.35.0', '>=')) {
      $pdo->exec($dropColumnSql);
    }
    $pdo->exec($renameTableSql);
    $tableName = $pdo->query("SELECT name FROM sqlite_schema WHERE type = 'table' AND name = 'accounts'")->fetchColumn();
    $assertSame($tableName, 'accounts', 'Generated SQLite ALTER SQL should execute successfully.');
  } catch (\Throwable $e) {
    @unlink($path);
    fwrite(STDERR, 'FAIL: generated SQLite ALTER SQL did not execute: ' . $e->getMessage() . "\n");
    exit(1);
  }
  @unlink($path);
}

fwrite(STDOUT, "OK: SQLite create SQL\n");
