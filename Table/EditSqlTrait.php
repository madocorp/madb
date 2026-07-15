<?php

namespace MADB\Table;

/** Generates CREATE and ALTER statements from the table editor panel and sends them to the query workspace. */
trait EditSqlTrait {

  /** Builds generate SQL from table editor state. */
  public static function generate($panel = null) {
    $connection = self::currentConnection();
    if ($connection === false || self::$schema === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection and ' . self::schemaLabel() . ' before saving.');
      return;
    }
    $values = self::panel()->getValue();
    self::syncColumnOrderFromList();
    $table = trim(self::textValue(self::namedValue('table-name', $values)));
    if ($table === '') {
      \SPTK\Elements\WarningPanel::forge('No ' . self::tableLabel() . ' name!', 'Please enter a ' . self::tableLabel() . ' name before saving.');
      return;
    }
    if (!self::hasNamedColumns()) {
      \SPTK\Elements\WarningPanel::forge('No fields defined', 'Please add at least one field before saving.');
      return;
    }
    $charset = trim(self::textValue(self::namedValue('table-charset', $values)));
    $collation = trim(self::textValue(self::namedValue('table-collation', $values)));
    $engine = trim(self::textValue(self::namedValue('table-engine', $values)));
    $comment = trim(self::textValue(self::namedValue('table-comment', $values)));
    if (self::$mode === 'create') {
      $sql = self::generateCreateSql($table, $engine, $charset, $collation, $comment);
      \MADB\Main\GeneratedQueryController::open([
        'title' => 'Create table',
        'name' => self::queryName('CREATE', $table),
        'sql' => $sql,
        'connection' => $connection,
        'schema' => self::$schema,
        'table' => $table,
        'cacheKeys' => self::generatedTableCacheKeys(self::$schema, [$table]),
        'refresh' => 'tables'
      ]);
      return;
    }
    $sql = self::generateAlterSql($table, $engine, $charset, $collation, $comment);
    if ($sql === false) {
      return;
    }
    if ($sql === '') {
      \SPTK\Elements\WarningPanel::forge('No changes', 'No table changes were detected.');
      return;
    }
    \MADB\Main\GeneratedQueryController::open([
      'title' => 'Modify table',
      'name' => self::queryName('ALTER', self::$table),
      'sql' => $sql,
      'connection' => $connection,
      'schema' => self::$schema,
      'table' => $table,
      'cacheKeys' => self::generatedTableCacheKeys(self::$schema, [self::$table, $table]),
      'refresh' => 'tables'
    ]);
  }

  /** Returns table metadata cache keys affected by generated table DDL. */
  private static function generatedTableCacheKeys(string $schema, array $tables): array {
    $keys = ['TableList:' . $schema];
    foreach (array_unique(array_filter($tables, fn($table) => $table !== false && $table !== '')) as $table) {
      $keys[] = 'TableDefinition:' . $schema . ':' . $table;
      $keys[] = 'TableFields:' . $schema . ':' . $table;
    }
    return $keys;
  }

  /** Builds create sql SQL from table editor state. */
  private static function generateCreateSql($table, $engine, $charset, $collation, $comment) {
    $definitions = [];
    foreach (self::$columns as $column) {
      if (self::normalizeValue($column['COLUMN_NAME'] ?? '') !== '') {
        $definitions[] = self::columnDefinition($column);
      }
    }
    foreach (self::groupIndexes(self::$indexes) as $index) {
      if (!empty($index['columns'])) {
        $definitions[] = self::indexDefinition($index);
      }
    }
    foreach (self::groupForeignKeys(self::$foreignKeys) as $foreignKey) {
      if (!empty($foreignKey['columns'])) {
        $definitions[] = self::foreignKeyDefinition($foreignKey);
      }
    }
    if (empty($definitions)) {
      $definitions[] = '[COLUMNS]';
    }
    $sql = 'CREATE TABLE ' . self::quoteQualifiedTable(self::$schema, $table) . " (\n  " . implode(",\n  ", $definitions) . "\n)";
    $clauses = self::tableOptionClauses($engine, $charset, $collation, $comment);
    if (!empty($clauses)) {
      $sql .= "\n" . implode("\n", $clauses);
    }
    $statements = [$sql . ';'];
    foreach (self::$triggers as $trigger) {
      if (self::normalizeValue($trigger['TRIGGER_NAME'] ?? '') !== '') {
        $statements[] = self::triggerCreateSql($table, $trigger);
      }
    }
    return implode("\n\n", $statements);
  }

  /** Builds alter sql SQL from table editor state. */
  private static function generateAlterSql($table, $engine, $charset, $collation, $comment) {
    $original = self::$definition['table'] ?? [];
    if (empty($original)) {
      \SPTK\Elements\WarningPanel::forge('Table metadata not loaded', 'Please wait until the table metadata has loaded before saving.');
      return false;
    }
    $currentName = $original['name'] ?? self::$table;
    $currentEngine = $original['engine'] ?? '';
    $currentCharset = $original['charset'] ?? '';
    $currentCollation = $original['collation'] ?? '';
    $currentComment = $original['comment'] ?? '';
    $statements = [];
    if ($table !== $currentName) {
      $statements[] =
        'ALTER TABLE ' . self::quoteQualifiedTable(self::$schema, $currentName) .
        ' RENAME TO ' . self::quoteQualifiedTable(self::$schema, $table) . ';';
    }
    $clauses = [];
    if ($engine !== '' && $engine !== $currentEngine) {
      $clauses[] = 'ENGINE = ' . $engine;
    }
    if ($charset !== '' && $charset !== $currentCharset) {
      $clauses[] = 'DEFAULT CHARACTER SET ' . $charset;
    }
    if ($collation !== '' && $collation !== $currentCollation) {
      $clauses[] = 'COLLATE ' . $collation;
    }
    if ($comment !== $currentComment) {
      $clauses[] = 'COMMENT = ' . self::quoteString($comment);
    }
    if (!empty($clauses)) {
      $targetName = $table !== $currentName ? $table : $currentName;
      $statements[] =
        'ALTER TABLE ' . self::quoteQualifiedTable(self::$schema, $targetName) . "\n  " .
        implode(",\n  ", $clauses) . ';';
    }
    $targetName = $table !== $currentName ? $table : $currentName;
    $foreignKeyClauses = self::generateForeignKeyAlterClauses();
    foreach ($foreignKeyClauses['drop'] as $clause) {
      $statements[] =
        'ALTER TABLE ' . self::quoteQualifiedTable(self::$schema, $targetName) . "\n  " .
        $clause . ';';
    }
    foreach (self::generateColumnAlterClauses() as $clause) {
      $statements[] =
        'ALTER TABLE ' . self::quoteQualifiedTable(self::$schema, $targetName) . "\n  " .
        $clause . ';';
    }
    foreach (self::generateIndexAlterClauses() as $clause) {
      $statements[] =
        'ALTER TABLE ' . self::quoteQualifiedTable(self::$schema, $targetName) . "\n  " .
        $clause . ';';
    }
    foreach ($foreignKeyClauses['add'] as $clause) {
      $statements[] =
        'ALTER TABLE ' . self::quoteQualifiedTable(self::$schema, $targetName) . "\n  " .
        $clause . ';';
    }
    foreach (self::generateTriggerStatements($currentName, $targetName) as $statement) {
      $statements[] = $statement;
    }
    return implode("\n\n", $statements);
  }

  /** Builds column alter clauses SQL from table editor state. */
  private static function generateColumnAlterClauses() {
    $clauses = [];
    $originalColumns = self::$definition['columns'] ?? [];
    $matchedOriginals = [];
    $originalByName = [];
    foreach ($originalColumns as $index => $column) {
      $originalByName[self::normalizeValue($column['COLUMN_NAME'] ?? '')] = $index;
    }
    foreach (self::$columns as $index => $column) {
      $name = self::normalizeValue($column['COLUMN_NAME'] ?? '');
      if ($name === '') {
        continue;
      }
      $originalIndex = $originalByName[$name] ?? false;
      if ($originalIndex === false && isset($originalColumns[$index])) {
        $originalIndex = $index;
      }
      if ($originalIndex === false || !isset($originalColumns[$originalIndex])) {
        $previousName = self::previousNamedColumn(self::$columns, $index);
        $clauses[] = 'ADD COLUMN ' . self::columnDefinition($column) . self::columnPositionClause($previousName);
        continue;
      }
      $matchedOriginals[$originalIndex] = true;
      $original = $originalColumns[$originalIndex];
      $originalName = self::normalizeValue($original['COLUMN_NAME'] ?? '');
      $previousName = self::previousNamedColumn(self::$columns, $index);
      $originalPreviousName = self::previousNamedColumn($originalColumns, $originalIndex);
      $positionChanged = $previousName !== $originalPreviousName;
      if (self::normalizeColumn($original) === self::normalizeColumn($column) && !$positionChanged) {
        continue;
      }
      if ($name !== $originalName) {
        $clauses[] = 'CHANGE COLUMN ' . self::quoteIdentifier($originalName) . ' ' . self::columnDefinition($column) . self::columnPositionClause($previousName);
      } else {
        $clauses[] = 'MODIFY COLUMN ' . self::columnDefinition($column) . self::columnPositionClause($previousName);
      }
    }
    foreach ($originalColumns as $index => $column) {
      $name = self::normalizeValue($column['COLUMN_NAME'] ?? '');
      if ($name !== '' && !isset($matchedOriginals[$index])) {
        $clauses[] = 'DROP COLUMN ' . self::quoteIdentifier($name);
      }
    }
    return $clauses;
  }

  /** Coordinates index drop clause work in the table editor. */
  private static function indexDropClause($index) {
    return ($index['name'] === 'PRIMARY' || strtoupper($index['type']) === 'PRIMARY') ?
      'DROP PRIMARY KEY' :
      'DROP INDEX ' . self::quoteIdentifier($index['name']);
  }

  /** Builds index alter clauses SQL from table editor state. */
  private static function generateIndexAlterClauses() {
    $clauses = [];
    $originalIndexes = self::groupIndexes(self::$definition['indexes'] ?? []);
    $currentIndexes = self::groupIndexes(self::$indexes);
    foreach ($originalIndexes as $name => $index) {
      if (!isset($currentIndexes[$name])) {
        $clauses[] = self::indexDropClause($index);
      }
    }
    foreach ($currentIndexes as $name => $index) {
      if (empty($index['columns'])) {
        continue;
      }
      if (isset($originalIndexes[$name]) && self::normalizeIndex($originalIndexes[$name]) === self::normalizeIndex($index)) {
        continue;
      }
      if (isset($originalIndexes[$name])) {
        $clauses[] = self::indexDropClause($originalIndexes[$name]);
      }
      $clauses[] = 'ADD ' . self::indexDefinition($index);
    }
    return $clauses;
  }

  /** Builds foreign key alter clauses SQL from table editor state. */
  private static function generateForeignKeyAlterClauses() {
    $clauses = [
      'drop' => [],
      'add' => []
    ];
    $originalForeignKeys = self::groupForeignKeys(self::$definition['foreignKeys'] ?? []);
    $currentForeignKeys = self::groupForeignKeys(self::$foreignKeys);
    foreach ($originalForeignKeys as $name => $foreignKey) {
      if (!isset($currentForeignKeys[$name])) {
        $clauses['drop'][] = 'DROP FOREIGN KEY ' . self::quoteIdentifier($name);
      }
    }
    foreach ($currentForeignKeys as $name => $foreignKey) {
      if (empty($foreignKey['columns'])) {
        continue;
      }
      if (isset($originalForeignKeys[$name]) && self::normalizeForeignKey($originalForeignKeys[$name]) === self::normalizeForeignKey($foreignKey)) {
        continue;
      }
      if (isset($originalForeignKeys[$name])) {
        $clauses['drop'][] = 'DROP FOREIGN KEY ' . self::quoteIdentifier($name);
      }
      $clauses['add'][] = 'ADD ' . self::foreignKeyDefinition($foreignKey);
    }
    return $clauses;
  }

  /** Coordinates trigger map work in the table editor. */
  private static function triggerMap($triggers) {
    $map = [];
    foreach ($triggers as $trigger) {
      $name = self::normalizeValue($trigger['TRIGGER_NAME'] ?? '');
      if ($name !== '') {
        $map[$name] = $trigger;
      }
    }
    return $map;
  }

  /** Builds trigger statements SQL from table editor state. */
  private static function generateTriggerStatements($currentName, $targetName) {
    $statements = [];
    $originalTriggers = self::triggerMap(self::$definition['triggers'] ?? []);
    $currentTriggers = self::triggerMap(self::$triggers);
    foreach ($originalTriggers as $name => $trigger) {
      if (!isset($currentTriggers[$name]) || self::normalizeTrigger($trigger) !== self::normalizeTrigger($currentTriggers[$name]) || $currentName !== $targetName) {
        $statements[] = 'DROP TRIGGER ' . self::quoteIdentifier(self::$schema) . '.' . self::quoteIdentifier($name) . ';';
      }
    }
    foreach ($currentTriggers as $name => $trigger) {
      if (!isset($originalTriggers[$name]) || self::normalizeTrigger($originalTriggers[$name]) !== self::normalizeTrigger($trigger) || $currentName !== $targetName) {
        $statements[] = self::triggerCreateSql($targetName, $trigger);
      }
    }
    return $statements;
  }

  /** Closes the close panel in the table editor. */
  public static function close($panel) {
    $panel->hide();
    \SPTK\Element::refresh();
  }

}
