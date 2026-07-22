<?php

namespace MADB\Table;

/** Handles the selected-table copy panel and builds generated copy SQL. */
trait MenuCopyTrait {

  /** Coordinates copy work in the table menu. */
  public static function copy() {
    if (self::$currentSchema === false) {
      \SPTK\Elements\WarningPanel::forge('No ' . self::schemaLabel() . ' selected!', 'Please select a ' . self::schemaLabel() . ' before preforming this operation.');
      return;
    }
    if (self::$currentTable === false) {
      \SPTK\Elements\WarningPanel::forge('No table selected!', 'Please select a table before preforming this operation.');
      return;
    }
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    if ($connectionList->current === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    if (!\MADB\Connection\MenuController::requireOperation('tableCopy', 'Copying tables', $connectionList->current)) {
      return;
    }
    $panel = \SPTK\Element::byName('table-copy');
    if ($panel === false) {
      return;
    }
    $schema = \SPTK\Element::byName('table-copy-schema', $panel);
    if ($schema !== false) {
      $schema->setOptions(self::schemaOptions());
    }
    $panel->setValue([
      'table-copy-schema' => self::$currentSchema,
      'table-copy-table' => self::$currentTable
    ]);
    $panel->show();
    $panel->activateInput('table-copy-generate');
    \SPTK\Element::refresh();
  }

  /** Saves copy values from the table menu panel or state. */
  public static function saveCopy($panel) {
    $values = $panel->getValue();
    $targetSchema = trim(self::textValue($values['table-copy-schema'] ?? ''));
    $targetTable = trim(self::textValue($values['table-copy-table'] ?? ''));
    if ($targetSchema === '') {
      \SPTK\Elements\WarningPanel::forge('Missing target ' . self::schemaLabel(), 'Please select the target ' . self::schemaLabel() . '.');
      return;
    }
    if ($targetTable === '') {
      \SPTK\Elements\WarningPanel::forge('Missing target table', 'Please enter the target table name.');
      return;
    }
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    $connection = $connectionList->current;
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    if (!\MADB\Connection\MenuController::requireOperation('tableCopy', 'Copying tables', $connection)) {
      return;
    }
    $panel->hide();
    $command = self::isSQLiteConnection() ? 'tableDefinition' : 'tableFields';
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => $command,
      'arguments' => [self::$currentSchema, self::$currentTable],
      'callback' => ['\MADB\Table\MenuController', 'copied'],
      'schema' => self::$currentSchema,
      'table' => self::$currentTable,
      'targetSchema' => $targetSchema,
      'targetTable' => $targetTable,
      'cache' => ($command === 'tableDefinition' ? 'TableDefinition:' : 'TableFields:') . self::$currentSchema . ':' . self::$currentTable
    ]);
    \SPTK\Element::refresh();
  }

  /** Coordinates copied work in the table menu. */
  public static function copied($response) {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not inspect table', $response['result']);
      return;
    }
    $sourceSchema = $response['schema'];
    $sourceTable = $response['table'];
    $targetSchema = $response['targetSchema'];
    $targetTable = $response['targetTable'] ?? $sourceTable;
    if (self::isSQLiteResponse($response)) {
      self::copiedSQLite($response, $sourceSchema, $sourceTable, $targetSchema, $targetTable);
      return;
    }
    $fields = $response['result'];
    $fieldList = self::formatFieldList($fields);
    $target = self::quoteQualifiedTable($targetSchema, $targetTable);
    $source = self::quoteQualifiedTable($sourceSchema, $sourceTable);
    $statements = ["CREATE TABLE {$target} LIKE {$source};"];
    if ($fieldList === '*') {
      $statements[] = "INSERT INTO {$target}\nSELECT *\nFROM {$source};";
    } else {
      $statements[] = "INSERT INTO {$target}\n  ({$fieldList})\nSELECT {$fieldList}\nFROM {$source};";
    }
    $sql = implode("\n\n", $statements);
    $name = 'COPY ' . $sourceSchema . '.' . $sourceTable . ' -> ' . $targetSchema . '.' . $targetTable;
    self::closeCopyPanel();
    \MADB\Query\GeneratedQueryController::open([
      'title' => 'Copy table',
      'name' => $name,
      'sql' => \MADB\Query\SqlFormatter\SqlFormatter::format($sql),
      'connection' => $response['connection'],
      'schema' => $targetSchema,
      'table' => $targetTable,
      'cacheKeys' => self::tableCacheKeys($targetSchema, [$targetTable]),
      'refresh' => 'tables'
    ]);
  }

  /** Coordinates SQLite copied work in the table menu. */
  private static function copiedSQLite($response, string $sourceSchema, string $sourceTable, string $targetSchema, string $targetTable): void {
    try {
      $sql = self::buildSQLiteCopySql($sourceSchema, $sourceTable, $targetSchema, $targetTable, $response['result']);
    } catch (\InvalidArgumentException $e) {
      \SPTK\Elements\WarningPanel::forge('Could not copy object', $e->getMessage());
      return;
    }
    $name = 'COPY ' . $sourceSchema . '.' . $sourceTable . ' -> ' . $targetSchema . '.' . $targetTable;
    self::closeCopyPanel();
    \MADB\Query\GeneratedQueryController::open([
      'title' => 'Copy object',
      'name' => $name,
      'sql' => $sql,
      'connection' => $response['connection'],
      'schema' => $targetSchema,
      'table' => $targetTable,
      'cacheKeys' => self::tableCacheKeys($targetSchema, [$targetTable]),
      'refresh' => 'tables'
    ]);
  }

  /** Builds SQLite object copy SQL from loaded table definition metadata. */
  public static function buildSQLiteCopySql(string $sourceSchema, string $sourceTable, string $targetSchema, string $targetTable, array $definition): string {
    $tableInfo = $definition['table'] ?? [];
    $type = strtoupper((string)($tableInfo['type'] ?? 'BASE TABLE'));
    $createSql = trim((string)($tableInfo['createSql'] ?? ''));
    if ($createSql === '') {
      throw new \InvalidArgumentException("CREATE SQL for '{$sourceSchema}.{$sourceTable}' was not found.");
    }
    if ($type === 'VIEW') {
      return self::rewriteSQLiteCreateObjectName($createSql, 'VIEW', $targetSchema, $targetTable);
    }
    if ($type !== 'BASE TABLE') {
      throw new \InvalidArgumentException('Only SQLite tables and views can be copied.');
    }
    $fieldList = self::formatSQLiteFieldList($definition['columns'] ?? []);
    $target = self::quoteSQLiteQualifiedTable($targetSchema, $targetTable);
    $source = self::quoteSQLiteQualifiedTable($sourceSchema, $sourceTable);
    $statements = [
      self::rewriteSQLiteCreateObjectName($createSql, 'TABLE', $targetSchema, $targetTable)
    ];
    if ($fieldList === '*') {
      $statements[] = "INSERT INTO {$target}\nSELECT *\nFROM {$source};";
    } else {
      $statements[] = "INSERT INTO {$target}\n  ({$fieldList})\nSELECT {$fieldList}\nFROM {$source};";
    }
    return implode("\n\n", $statements);
  }

  /** Hides the copy panel before showing generated SQL. */
  private static function closeCopyPanel(): void {
    $panel = \SPTK\Element::byName('table-copy');
    if ($panel !== false) {
      $panel->hide();
    }
  }

  /** Returns whether a job response belongs to a SQLite connection. */
  private static function isSQLiteResponse(array $response): bool {
    $connection = $response['connection'] ?? false;
    return is_array($connection) && strcasecmp((string)($connection['type'] ?? ''), 'SQLite') === 0;
  }

  /** Replaces the object name in a stored SQLite CREATE statement. */
  private static function rewriteSQLiteCreateObjectName(string $createSql, string $keyword, string $targetSchema, string $targetTable): string {
    $sql = rtrim(trim($createSql), ';');
    if ($keyword === 'TABLE' && preg_match('/^\s*CREATE\s+VIRTUAL\s+TABLE\b/i', $sql)) {
      throw new \InvalidArgumentException('Copying SQLite virtual tables is not supported.');
    }
    if (!preg_match('/^\s*CREATE\s+(?:TEMP\s+|TEMPORARY\s+)?' . $keyword . '\s+(?:IF\s+NOT\s+EXISTS\s+)?/i', $sql, $match)) {
      throw new \InvalidArgumentException("CREATE {$keyword} SQL could not be parsed.");
    }
    $nameStart = strlen($match[0]);
    $nameEnd = self::sqliteQualifiedIdentifierEnd($sql, $nameStart);
    if ($nameEnd === false) {
      throw new \InvalidArgumentException("CREATE {$keyword} object name could not be parsed.");
    }
    return substr($sql, 0, $nameStart) . self::quoteSQLiteQualifiedTable($targetSchema, $targetTable) . substr($sql, $nameEnd) . ';';
  }

  /** Returns the end offset of an SQLite qualified identifier. */
  private static function sqliteQualifiedIdentifierEnd(string $sql, int $offset): int|false {
    $firstEnd = self::sqliteIdentifierEnd($sql, $offset);
    if ($firstEnd === false) {
      return false;
    }
    $position = self::skipSQLiteWhitespace($sql, $firstEnd);
    if (($sql[$position] ?? '') !== '.') {
      return $firstEnd;
    }
    $secondEnd = self::sqliteIdentifierEnd($sql, $position + 1);
    return $secondEnd === false ? false : $secondEnd;
  }

  /** Returns the end offset of one SQLite identifier. */
  private static function sqliteIdentifierEnd(string $sql, int $offset): int|false {
    $length = strlen($sql);
    $position = self::skipSQLiteWhitespace($sql, $offset);
    if ($position >= $length) {
      return false;
    }
    $start = $position;
    $quote = $sql[$position];
    if ($quote === '"' || $quote === '`') {
      $position++;
      while ($position < $length) {
        if ($sql[$position] === $quote) {
          if (($sql[$position + 1] ?? '') === $quote) {
            $position += 2;
            continue;
          }
          return $position + 1;
        }
        $position++;
      }
      return false;
    }
    if ($quote === '[') {
      $end = strpos($sql, ']', $position + 1);
      return $end === false ? false : $end + 1;
    }
    while ($position < $length && !ctype_space($sql[$position]) && !in_array($sql[$position], ['(', '.'], true)) {
      $position++;
    }
    return $position > $start ? $position : false;
  }

  /** Skips SQLite SQL whitespace. */
  private static function skipSQLiteWhitespace(string $sql, int $offset): int {
    $length = strlen($sql);
    while ($offset < $length && ctype_space($sql[$offset])) {
      $offset++;
    }
    return $offset;
  }

  /** Formats SQLite field list text for INSERT ... SELECT copy SQL. */
  private static function formatSQLiteFieldList(array $columns): string {
    $fields = [];
    foreach ($columns as $column) {
      $name = trim((string)($column['COLUMN_NAME'] ?? ''));
      if ($name !== '') {
        $fields[] = self::quoteSQLiteIdentifier($name);
      }
    }
    return empty($fields) ? '*' : implode(",\n  ", $fields);
  }

  /** Quotes an SQLite qualified object name. */
  private static function quoteSQLiteQualifiedTable(string $schema, string $table): string {
    return self::quoteSQLiteIdentifier($schema) . '.' . self::quoteSQLiteIdentifier($table);
  }

  /** Quotes an SQLite identifier. */
  private static function quoteSQLiteIdentifier(string $identifier): string {
    return '"' . str_replace('"', '""', $identifier) . '"';
  }

}
