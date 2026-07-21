<?php

namespace MADB\Engine\SQLite;

use \PDO;

/** Implements SQLite file database connections, browsing metadata, and query execution. */
class Connection extends \MADB\Connection\Connection {

  public $pdo;
  public $serverInfo = false;
  private string $mainDatabasePath = '';

  /** Returns defaults data used by the SQLite engine. */
  public static function getDefaults() {
    return [
      'name' => 'new',
      'path' => '',
      'timeout' => '60',
      'initCommand' => ''
    ];
  }

  /** Returns menu labels data used by the SQLite engine. */
  public static function getMenuLabels() {
    return [
      'schema' => 'Database',
      'table' => 'Object'
    ];
  }

  /** Returns whether an optional UI operation is supported by SQLite. */
  public static function supportsOperation($operation): bool {
    return !in_array($operation, [
      'tableCreate',
      'tableModify',
      'tableCopy',
      'tableDrop',
      'viewCreate',
      'viewModify',
      'rowInsert',
      'rowUpdate',
      'rowDelete'
    ], true);
  }

  /** Coordinates connect work in the SQLite engine. */
  public function connect() {
    if (!extension_loaded('pdo_sqlite')) {
      throw new \Exception('The pdo_sqlite PHP extension is not installed or enabled.');
    }
    if (empty($this->data['name'])) {
      throw new \Exception('Nameless connection!');
    }
    $path = trim((string)($this->data['path'] ?? ''));
    if ($path === '') {
      throw new \Exception("Empty database file in connection {$this->data['name']}");
    }
    $path = $this->expandPath($path);
    $dir = dirname($path);
    if (!is_dir($dir)) {
      throw new \Exception("Directory does not exist: {$dir}");
    }
    $realDir = realpath($dir);
    $this->mainDatabasePath = ($realDir === false ? $dir : $realDir) . DIRECTORY_SEPARATOR . basename($path);
    $this->pdo = new PDO('sqlite:' . $path);
    $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (!empty($this->data['timeout'])) {
      $this->pdo->exec('PRAGMA busy_timeout = ' . max(0, (int)$this->data['timeout']) * 1000);
    }
    $this->attachSidecarDatabases();
    if (!empty($this->data['initCommand'])) {
      $this->pdo->exec($this->data['initCommand']);
    }
    $this->serverInfo = $this->detectServerInfo();
  }

  /** Coordinates test work in the SQLite engine. */
  public function test() {
    return [
      'message' => 'The connection to the database file was successful.',
      'serverInfo' => $this->getServerInfo()
    ];
  }

  /** Returns detected SQLite version metadata for status displays. */
  public function getServerInfo() {
    return $this->serverInfo;
  }

  /** Coordinates schema list work in the SQLite engine. */
  public function schemaList() {
    $schemas = [];
    foreach ($this->databaseList() as $database) {
      $name = $database['name'] ?? '';
      if ($name !== '') {
        $schemas[] = $name;
      }
    }
    $this->queryTime = microtime(true);
    return $schemas;
  }

  /** Creates schema data for the connection menu. */
  public function createSchema($schema) {
    $this->validateMutableSchemaName($schema, 'create');
    if ($this->schemaExists($schema)) {
      throw new \Exception("Database '{$schema}' is already attached.");
    }
    $path = $this->sidecarPath($schema);
    if (file_exists($path)) {
      throw new \Exception("Database file already exists: {$path}");
    }
    $handle = @fopen($path, 'xb');
    if ($handle === false) {
      throw new \Exception("Could not create database file: {$path}");
    }
    fclose($handle);
    try {
      $this->attachDatabase($schema, $path);
    } catch (\Exception $e) {
      @unlink($path);
      throw $e;
    }
    $this->queryTime = microtime(true);
    return true;
  }

  /** Coordinates schema info work in the SQLite engine. */
  public function schemaInfo($schema) {
    $tables = 0;
    $views = 0;
    foreach ($this->schemaObjects($schema) as $object) {
      if (($object['type'] ?? '') === 'table') {
        $tables++;
      } elseif (($object['type'] ?? '') === 'view') {
        $views++;
      }
    }
    $this->queryTime = microtime(true);
    return [
      'tables' => $tables,
      'views' => $views,
      'bytes' => $this->databaseFileSize($schema),
      'foreignKeys' => 0,
      'routines' => 0,
      'events' => 0
    ];
  }

  /** Coordinates rename schema info work in the SQLite engine. */
  public function renameSchemaInfo($schema, $targetSchema) {
    $this->validateMutableSchemaName($schema, 'rename');
    $this->validateMutableSchemaName($targetSchema, 'rename');
    $info = $this->schemaInfo($schema);
    $info['targetExists'] = $this->schemaExists($targetSchema) || file_exists($this->sidecarPath($targetSchema));
    return $info;
  }

  /** Generates SQL preview text for an SQLite attached database rename. */
  public function renameSchemaSql($schema, $targetSchema) {
    $info = $this->renameSchemaInfo($schema, $targetSchema);
    if (!empty($info['targetExists'])) {
      throw new \Exception("Target database '{$targetSchema}' already exists.");
    }
    $sourcePath = $this->ownedAttachedSidecarPath($schema);
    $targetPath = $this->sidecarPath($targetSchema);
    $source = $this->quoteIdentifier($schema);
    $target = $this->quoteIdentifier($targetSchema);
    $statements = [
      "-- SQLite attached database rename preview.",
      "-- MADB will detach {$source}, rename the file, and attach it as {$target}.",
      'DETACH DATABASE ' . $source . ';',
      "ATTACH DATABASE " . $this->quoteString($targetPath) . ' AS ' . $target . ';',
      "-- Source file: {$sourcePath}",
      "-- Target file: {$targetPath}"
    ];
    $this->queryTime = microtime(true);
    return [
      'info' => $info,
      'sql' => implode("\n", $statements)
    ];
  }

  /** Coordinates rename schema work in the SQLite engine. */
  public function renameSchema($schema, $targetSchema) {
    $this->validateMutableSchemaName($schema, 'rename');
    $this->validateMutableSchemaName($targetSchema, 'rename');
    if ($this->schemaExists($targetSchema)) {
      throw new \Exception("Target database '{$targetSchema}' is already attached.");
    }
    $sourcePath = $this->ownedAttachedSidecarPath($schema);
    $targetPath = $this->sidecarPath($targetSchema);
    if (file_exists($targetPath)) {
      throw new \Exception("Target database file already exists: {$targetPath}");
    }
    $this->detachDatabase($schema);
    if (!@rename($sourcePath, $targetPath)) {
      $this->attachDatabase($schema, $sourcePath);
      throw new \Exception("Could not rename database file to: {$targetPath}");
    }
    try {
      $this->attachDatabase($targetSchema, $targetPath);
    } catch (\Exception $e) {
      @rename($targetPath, $sourcePath);
      $this->attachDatabase($schema, $sourcePath);
      throw $e;
    }
    $this->queryTime = microtime(true);
    return true;
  }

  /** Coordinates drop schema work in the SQLite engine. */
  public function dropSchema($schema) {
    $this->validateMutableSchemaName($schema, 'drop');
    $path = $this->ownedAttachedSidecarPath($schema);
    $this->detachDatabase($schema);
    if (is_file($path) && !@unlink($path)) {
      throw new \Exception("Could not delete database file: {$path}");
    }
    $this->queryTime = microtime(true);
    return true;
  }

  /** Coordinates character set, collation, and engine option loading. */
  public function characterSetsAndCollations() {
    $this->queryTime = microtime(true);
    return [
      'charsets' => [],
      'collations' => [],
      'engines' => []
    ];
  }

  /** Coordinates table list work in the SQLite engine. */
  public function tableList($schema) {
    $tables = [];
    foreach ($this->schemaObjects($schema) as $object) {
      $type = ($object['type'] ?? '') === 'view' ? 'VIEW' : 'BASE TABLE';
      $tables[] = [
        'name' => $object['name'],
        'type' => $type
      ];
    }
    $this->queryTime = microtime(true);
    return $tables;
  }

  /** Coordinates table fields work in the SQLite engine. */
  public function tableFields($schema, $table) {
    $fields = [];
    foreach ($this->tableColumnRows($schema, $table) as $column) {
      if (($column['hidden'] ?? 0) == 1) {
        continue;
      }
      $fields[] = $column['name'];
    }
    $this->queryTime = microtime(true);
    return $fields;
  }

  /** Coordinates table definition work in the SQLite engine. */
  public function tableDefinition($schema, $table) {
    $object = $this->schemaObject($schema, $table);
    if ($object === false) {
      throw new \Exception("Object '{$schema}.{$table}' does not exist.");
    }
    $columns = $this->columnDefinitions($schema, $table);
    $indexes = $this->indexDefinitions($schema, $table);
    $foreignKeys = $this->foreignKeyDefinitions($schema, $table);
    $triggers = $this->triggerDefinitions($schema, $table);
    $this->queryTime = microtime(true);
    return [
      'table' => [
        'name' => $object['name'],
        'type' => ($object['type'] ?? '') === 'view' ? 'VIEW' : 'BASE TABLE',
        'engine' => '',
        'charset' => '',
        'collation' => '',
        'comment' => '',
        'rows' => 0,
        'dataLength' => 0,
        'indexLength' => 0
      ],
      'columns' => $columns,
      'indexes' => $indexes,
      'foreignKeys' => $foreignKeys,
      'referencedBy' => [],
      'triggers' => $triggers
    ];
  }

  /** Loads incoming foreign-key references for workflows that explicitly need them. */
  public function tableReferencedBy($schema, $table) {
    $this->queryTime = microtime(true);
    return [];
  }

  /** Returns the lean table metadata needed by row insert, update, and delete panels. */
  public function rowEditorDefinition($schema, $table) {
    $object = $this->schemaObject($schema, $table);
    if ($object === false) {
      throw new \Exception("Object '{$schema}.{$table}' does not exist.");
    }
    $this->queryTime = microtime(true);
    return [
      'columns' => $this->columnDefinitions($schema, $table)
    ];
  }

  /** Returns CREATE SQL for an SQLite object. */
  public function showCreateTable($schema, $table) {
    $object = $this->schemaObject($schema, $table);
    if ($object === false || trim((string)($object['sql'] ?? '')) === '') {
      throw new \Exception("CREATE SQL for '{$schema}.{$table}' was not found.");
    }
    $this->queryTime = microtime(true);
    return [
      'sql' => rtrim($object['sql'], ';') . ';'
    ];
  }

  /** Runs query through the SQLite engine. */
  public function query($sql, $resultFile = false) {
    if (trim($sql) === '') {
      throw new \Exception('Query is empty.');
    }
    $stmt = $this->pdo->query($sql);
    $this->queryTime = microtime(true);
    if ($stmt === false) {
      throw new \Exception('Query failed.');
    }
    if ($stmt->columnCount() === 0) {
      return [
        'affectedRows' => $stmt->rowCount()
      ];
    }
    $columns = [];
    for ($i = 0; $i < $stmt->columnCount(); $i++) {
      $meta = $stmt->getColumnMeta($i);
      $columns[] = $meta['name'] ?? (string)$i;
    }
    if ($resultFile !== false) {
      return $this->writeResultFile($stmt, $columns, $resultFile);
    }
    return [
      'columns' => $columns,
      'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ];
  }

  /** Runs batch through the SQLite engine. */
  public function queryBatch($statements, $resultFiles = [], $schema = false, $progress = false) {
    if (is_callable($schema) && $progress === false) {
      $progress = $schema;
      $schema = false;
    }
    if (!is_array($statements) || empty($statements)) {
      throw new \Exception('Query is empty.');
    }
    $results = [];
    $resultIndex = 0;
    foreach ($statements as $index => $statement) {
      $statementIndex = $statement['index'] ?? $index;
      $sql = trim((string)($statement['sql'] ?? ''));
      if ($sql === '') {
        continue;
      }
      $started = microtime(true);
      if (is_callable($progress)) {
        $progress([
          'statements' => array_merge($results, [[
            'index' => $statementIndex,
            'sql' => $sql,
            'status' => 'RUNNING',
            'startedAt' => $started,
            'range' => [
              'start' => $statement['start'] ?? 0,
              'end' => $statement['end'] ?? 0
            ]
          ]]),
          'resultCount' => $resultIndex
        ]);
      }
      try {
        $file = $resultFiles[$resultIndex] ?? false;
        $result = $this->query($sql, $file);
        $finished = microtime(true);
        $entry = [
          'index' => $statementIndex,
          'sql' => $sql,
          'status' => 'OK',
          'startedAt' => $started,
          'time' => round($finished - $started, 4),
          'finishedAt' => $finished,
          'range' => [
            'start' => $statement['start'] ?? 0,
            'end' => $statement['end'] ?? 0
          ]
        ];
        if (is_array($result) && isset($result['columns'])) {
          $entry['resultIndex'] = $resultIndex;
          $entry['result'] = $result;
          if ($file !== false && isset($result['rowCount'])) {
            $entry['result']['file'] = $file;
          }
          $resultIndex++;
        } else {
          $entry['result'] = $result;
        }
        $results[] = $entry;
        if (is_callable($progress)) {
          $progress([
            'statements' => $results,
            'resultCount' => $resultIndex
          ]);
        }
      } catch (\Exception $e) {
        $finished = microtime(true);
        $results[] = [
          'index' => $statementIndex,
          'sql' => $sql,
          'status' => 'ERROR',
          'error' => $e->getMessage(),
          'startedAt' => $started,
          'time' => round($finished - $started, 4),
          'finishedAt' => $finished,
          'range' => [
            'start' => $statement['start'] ?? 0,
            'end' => $statement['end'] ?? 0
          ]
        ];
        if (is_callable($progress)) {
          $progress([
            'statements' => $results,
            'resultCount' => $resultIndex
          ]);
        }
        break;
      }
    }
    return [
      'statements' => $results,
      'resultCount' => $resultIndex
    ];
  }

  /** Detects SQLite version metadata. */
  private function detectServerInfo() {
    $version = (string)$this->pdo->query('SELECT sqlite_version()')->fetchColumn();
    return [
      'vendor' => 'sqlite',
      'vendorLabel' => 'SQLite',
      'version' => $version,
      'versionNumber' => $version,
      'versionComment' => '',
      'capabilities' => [
        'sqlite' => true
      ]
    ];
  }

  /** Expands a leading tilde in connection paths. */
  private function expandPath($path) {
    if ($path === '~' || strpos($path, '~/') === 0) {
      $home = getenv('HOME');
      if ($home !== false && $home !== '') {
        return $home . substr($path, 1);
      }
    }
    return $path;
  }

  /** Escapes an SQLite identifier. */
  private function escapeIdentifier($identifier) {
    return str_replace('"', '""', (string)$identifier);
  }

  /** Quotes an SQLite identifier. */
  private function quoteIdentifier($identifier) {
    return '"' . $this->escapeIdentifier($identifier) . '"';
  }

  /** Quotes a string literal for SQLite preview SQL. */
  private function quoteString($value) {
    return "'" . str_replace("'", "''", (string)$value) . "'";
  }

  /** Quotes a schema-qualified SQLite object. */
  private function quoteQualifiedTable($schema, $table) {
    return $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);
  }

  /** Returns attached SQLite database rows. */
  private function databaseList() {
    return $this->pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
  }

  /** Returns whether an SQLite database name is currently attached. */
  private function schemaExists($schema): bool {
    foreach ($this->databaseList() as $database) {
      if (($database['name'] ?? '') === $schema) {
        return true;
      }
    }
    return false;
  }

  /** Attaches sidecar files matching the configured main database path convention. */
  private function attachSidecarDatabases(): void {
    $pattern = $this->sidecarGlobPattern();
    $files = glob($pattern);
    if ($files === false) {
      return;
    }
    sort($files);
    foreach ($files as $file) {
      if (!is_file($file)) {
        continue;
      }
      $schema = $this->sidecarNameFromPath($file);
      if ($schema === false) {
        continue;
      }
      if (!$this->isAutoAttachableSidecarName($schema) || $this->schemaExists($schema)) {
        continue;
      }
      $this->attachDatabase($schema, $file);
    }
  }

  /** Returns whether a discovered sidecar suffix is safe to auto-attach. */
  private function isAutoAttachableSidecarName($schema): bool {
    $schema = (string)$schema;
    if ($schema === '' || !$this->isMutableSchemaName($schema)) {
      return false;
    }
    $lower = strtolower($schema);
    return !in_array($lower, ['wal', 'shm', 'journal', '-wal', '-shm', '-journal'], true) &&
      !preg_match('/-(?:wal|shm|journal)$/i', $schema);
  }

  /** Validates an attached database name used for sidecar file operations. */
  private function validateMutableSchemaName($schema, $operation): void {
    if (!$this->isMutableSchemaName($schema)) {
      throw new \Exception("Invalid database name for SQLite {$operation}: {$schema}");
    }
  }

  /** Returns whether a name is safe for a MADB-owned SQLite sidecar database. */
  private function isMutableSchemaName($schema): bool {
    $schema = trim((string)$schema);
    return $schema !== '' &&
      !in_array(strtolower($schema), ['main', 'temp'], true) &&
      strpos($schema, '/') === false &&
      strpos($schema, '\\') === false &&
      strpos($schema, "\0") === false;
  }

  /** Returns the MADB sidecar file path for one attached database name. */
  private function sidecarPath($schema): string {
    $this->validateMutableSchemaName($schema, 'resolve');
    $path = pathinfo($this->mainDatabasePath);
    $dir = ($path['dirname'] ?? '') === '.' ? '' : ($path['dirname'] . DIRECTORY_SEPARATOR);
    $filename = $path['filename'] ?? basename($this->mainDatabasePath);
    $extension = $path['extension'] ?? '';
    $suffix = $extension === '' ? '' : '.' . $extension;
    return $dir . $filename . '.' . trim((string)$schema) . $suffix;
  }

  /** Returns a glob pattern for MADB-managed SQLite sidecar files. */
  private function sidecarGlobPattern(): string {
    $path = pathinfo($this->mainDatabasePath);
    $dir = ($path['dirname'] ?? '') === '.' ? '' : ($path['dirname'] . DIRECTORY_SEPARATOR);
    $filename = $path['filename'] ?? basename($this->mainDatabasePath);
    $extension = $path['extension'] ?? '';
    $suffix = $extension === '' ? '' : '.' . $extension;
    return $dir . $filename . '.*' . $suffix;
  }

  /** Extracts an attached database name from a sidecar path. */
  private function sidecarNameFromPath($file) {
    $main = pathinfo($this->mainDatabasePath);
    $sidecar = pathinfo($file);
    $prefix = ($main['filename'] ?? basename($this->mainDatabasePath)) . '.';
    $extension = $main['extension'] ?? '';
    $basename = $sidecar['basename'] ?? basename($file);
    if (strpos($basename, $prefix) !== 0) {
      return false;
    }
    if ($extension !== '') {
      $suffix = '.' . $extension;
      if (substr($basename, -strlen($suffix)) !== $suffix) {
        return false;
      }
      return substr($basename, strlen($prefix), -strlen($suffix));
    }
    return substr($basename, strlen($prefix));
  }

  /** Returns the path for an attached sidecar database owned by this connection. */
  private function ownedAttachedSidecarPath($schema): string {
    $expected = $this->sidecarPath($schema);
    foreach ($this->databaseList() as $database) {
      if (($database['name'] ?? '') !== $schema) {
        continue;
      }
      $file = (string)($database['file'] ?? '');
      if ($this->samePath($file, $expected)) {
        return file_exists($file) ? $file : $expected;
      }
      throw new \Exception("Database '{$schema}' is not a MADB-managed sidecar database.");
    }
    throw new \Exception("Database '{$schema}' is not attached.");
  }

  /** Attaches a database file using a safely quoted schema name. */
  private function attachDatabase($schema, $path): void {
    $stmt = $this->pdo->prepare('ATTACH DATABASE ? AS ' . $this->quoteIdentifier($schema));
    $stmt->execute([$path]);
  }

  /** Detaches a database by schema name. */
  private function detachDatabase($schema): void {
    $this->pdo->exec('DETACH DATABASE ' . $this->quoteIdentifier($schema));
  }

  /** Compares paths using real paths when possible. */
  private function samePath($a, $b): bool {
    $realA = realpath($a);
    $realB = realpath($b);
    if ($realA !== false && $realB !== false) {
      return $realA === $realB;
    }
    return $a === $b;
  }

  /** Returns the database file size for one attached database. */
  private function databaseFileSize($schema) {
    foreach ($this->databaseList() as $database) {
      if (($database['name'] ?? '') === $schema) {
        $file = $database['file'] ?? '';
        return is_file($file) ? filesize($file) : 0;
      }
    }
    return 0;
  }

  /** Returns user-visible table and view objects from sqlite_schema. */
  private function schemaObjects($schema) {
    $schema = $this->quoteIdentifier($schema);
    $stmt = $this->pdo->query(
      "SELECT name, type, sql
       FROM {$schema}.sqlite_schema
       WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%'
       ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  /** Returns one SQLite schema object by name. */
  private function schemaObject($schema, $table) {
    $schemaSql = $this->quoteIdentifier($schema);
    $stmt = $this->pdo->prepare(
      "SELECT name, type, sql
       FROM {$schemaSql}.sqlite_schema
       WHERE name = ? AND type IN ('table', 'view')"
    );
    $stmt->execute([$table]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  /** Returns raw SQLite table column metadata. */
  private function tableColumnRows($schema, $table) {
    $stmt = $this->pdo->query('PRAGMA ' . $this->quoteIdentifier($schema) . '.table_xinfo(' . $this->quoteIdentifier($table) . ')');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  /** Converts SQLite column metadata to MADB table-definition columns. */
  private function columnDefinitions($schema, $table) {
    $columns = [];
    foreach ($this->tableColumnRows($schema, $table) as $column) {
      if (($column['hidden'] ?? 0) == 1) {
        continue;
      }
      $columns[] = [
        'COLUMN_NAME' => $column['name'] ?? '',
        'COLUMN_TYPE' => $column['type'] ?? '',
        'IS_NULLABLE' => !empty($column['notnull']) || !empty($column['pk']) ? 'NO' : 'YES',
        'COLUMN_DEFAULT' => $column['dflt_value'] ?? null,
        'EXTRA' => '',
        'COLUMN_KEY' => !empty($column['pk']) ? 'PRI' : '',
        'COLUMN_COMMENT' => '',
        'CHARACTER_SET_NAME' => '',
        'COLLATION_NAME' => '',
        'ORDINAL_POSITION' => ((int)($column['cid'] ?? 0)) + 1
      ];
    }
    return $columns;
  }

  /** Converts SQLite index metadata to MADB table-definition indexes. */
  private function indexDefinitions($schema, $table) {
    $indexes = [];
    $list = $this->pdo->query('PRAGMA ' . $this->quoteIdentifier($schema) . '.index_list(' . $this->quoteIdentifier($table) . ')')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($list as $index) {
      if (($index['origin'] ?? '') === 'pk') {
        continue;
      }
      $name = $index['name'] ?? '';
      if ($name === '') {
        continue;
      }
      $info = $this->pdo->query('PRAGMA ' . $this->quoteIdentifier($schema) . '.index_xinfo(' . $this->quoteIdentifier($name) . ')')->fetchAll(PDO::FETCH_ASSOC);
      foreach ($info as $column) {
        if ((int)($column['key'] ?? 0) !== 1) {
          continue;
        }
        $indexes[] = [
          'INDEX_NAME' => $name,
          'NON_UNIQUE' => empty($index['unique']) ? 1 : 0,
          'SEQ_IN_INDEX' => ((int)($column['seqno'] ?? 0)) + 1,
          'COLUMN_NAME' => $column['name'] ?? '',
          'COLLATION' => !empty($column['desc']) ? 'D' : 'A',
          'CARDINALITY' => '',
          'INDEX_TYPE' => 'BTREE'
        ];
      }
    }
    foreach ($this->columnDefinitions($schema, $table) as $column) {
      if (($column['COLUMN_KEY'] ?? '') === 'PRI') {
        $indexes[] = [
          'INDEX_NAME' => 'PRIMARY',
          'NON_UNIQUE' => 0,
          'SEQ_IN_INDEX' => count(array_filter($indexes, fn($index) => ($index['INDEX_NAME'] ?? '') === 'PRIMARY')) + 1,
          'COLUMN_NAME' => $column['COLUMN_NAME'],
          'COLLATION' => 'A',
          'CARDINALITY' => '',
          'INDEX_TYPE' => 'PRIMARY'
        ];
      }
    }
    return $indexes;
  }

  /** Converts SQLite foreign-key metadata to MADB table-definition foreign keys. */
  private function foreignKeyDefinitions($schema, $table) {
    $foreignKeys = [];
    $rows = $this->pdo->query('PRAGMA ' . $this->quoteIdentifier($schema) . '.foreign_key_list(' . $this->quoteIdentifier($table) . ')')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
      $name = 'fk_' . ($row['id'] ?? count($foreignKeys));
      $foreignKeys[] = [
        'CONSTRAINT_NAME' => $name,
        'COLUMN_NAME' => $row['from'] ?? '',
        'REFERENCED_TABLE_SCHEMA' => $schema,
        'REFERENCED_TABLE_NAME' => $row['table'] ?? '',
        'REFERENCED_COLUMN_NAME' => $row['to'] ?? '',
        'UPDATE_RULE' => strtoupper($row['on_update'] ?? ''),
        'DELETE_RULE' => strtoupper($row['on_delete'] ?? ''),
        'ORDINAL_POSITION' => ((int)($row['seq'] ?? 0)) + 1
      ];
    }
    return $foreignKeys;
  }

  /** Returns trigger metadata for a table in MADB shape. */
  private function triggerDefinitions($schema, $table) {
    $schemaSql = $this->quoteIdentifier($schema);
    $stmt = $this->pdo->prepare(
      "SELECT name, sql
       FROM {$schemaSql}.sqlite_schema
       WHERE type = 'trigger' AND tbl_name = ?
       ORDER BY name"
    );
    $stmt->execute([$table]);
    $triggers = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $sql = (string)($row['sql'] ?? '');
      preg_match('/CREATE\s+(?:TEMP\s+|TEMPORARY\s+)?TRIGGER\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:["`\[]?[^\s"`\].]+["`\]]?\.)?["`\[]?[^\s"`\]]+["`\]]?\s+(BEFORE|AFTER|INSTEAD\s+OF)\s+(INSERT|UPDATE|DELETE)/i', $sql, $match);
      $triggers[] = [
        'TRIGGER_NAME' => $row['name'] ?? '',
        'ACTION_TIMING' => strtoupper($match[1] ?? ''),
        'EVENT_MANIPULATION' => strtoupper($match[2] ?? ''),
        'ACTION_STATEMENT' => $sql
      ];
    }
    return $triggers;
  }

  /** Writes a result set to the common TSV result file format. */
  private function writeResultFile($stmt, $columns, $resultFile) {
    $dir = dirname($resultFile);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
      throw new \Exception('Could not create result directory.');
    }
    $handle = fopen($resultFile, 'wb');
    if ($handle === false) {
      throw new \Exception('Could not create result file.');
    }
    try {
      $this->writeTsvLine($handle, $columns);
      $rowCount = 0;
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $values = [];
        foreach ($columns as $column) {
          $values[] = $row[$column] ?? null;
        }
        $this->writeTsvLine($handle, $values);
        $rowCount++;
      }
    } finally {
      fclose($handle);
    }
    return [
      'columns' => $columns,
      'rowCount' => $rowCount
    ];
  }

  /** Writes one TSV line with null and newline escaping. */
  private function writeTsvLine($handle, $values) {
    $fields = [];
    foreach ($values as $value) {
      if ($value === null) {
        $fields[] = '\N';
        continue;
      }
      $fields[] = str_replace(["\\", "\t", "\r", "\n"], ["\\\\", "\\t", "\\r", "\\n"], (string)$value);
    }
    fwrite($handle, implode("\t", $fields) . "\n");
  }

}
