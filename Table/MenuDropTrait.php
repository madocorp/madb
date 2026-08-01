<?php

namespace MADB\Table;

/** Generates confirmed DROP TABLE/VIEW SQL for the selected table menu item. */
trait MenuDropTrait {

  /** Opens a generated drop query for the selected table or view. */
  public static function drop() {
    if (self::$currentSchema === false) {
      \SPTK\Elements\WarningPanel::forge('No ' . self::schemaLabel() . ' selected!', 'Please select a ' . self::schemaLabel() . ' before preforming this operation.');
      return;
    }
    if (self::$currentTable === false) {
      \SPTK\Elements\WarningPanel::forge('No table selected!', 'Please select a table before preforming this operation.');
      return;
    }
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    $connection = $connectionList->current;
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    if (!\MADB\Connection\MenuController::requireOperation('tableDrop', 'Dropping tables and views', $connection)) {
      return;
    }
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'tableDefinition',
      'arguments' => [self::$currentSchema, self::$currentTable],
      'callback' => ['\MADB\Table\MenuController', 'dropDefinitionLoaded'],
      'schema' => self::$currentSchema,
      'table' => self::$currentTable,
      'cache' => 'TableDefinition:' . self::$currentSchema . ':' . self::$currentTable
    ]);
  }

  /** Loads incoming foreign-key references after the base table metadata is available. */
  public static function dropDefinitionLoaded($response): void {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not inspect table', $response['result']);
      return;
    }
    \MADB\Job\JobHandler::startJob([
      'connection' => $response['connection'],
      'command' => 'tableReferencedBy',
      'arguments' => [$response['schema'], $response['table']],
      'callback' => ['\MADB\Table\MenuController', 'dropGenerated'],
      'schema' => $response['schema'],
      'table' => $response['table'],
      'generatedQuery' => [
        'definition' => $response['result']
      ],
      'cache' => 'TableReferencedBy:' . $response['schema'] . ':' . $response['table']
    ]);
  }

  /** Opens a generated DROP statement after table metadata has loaded. */
  public static function dropGenerated($response): void {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not inspect table references', $response['result']);
      return;
    }
    $schema = $response['schema'];
    $table = $response['table'];
    $definition = is_array($response['generatedQuery']['definition'] ?? false) ? $response['generatedQuery']['definition'] : [];
    $definition['referencedBy'] = is_array($response['result']) ? $response['result'] : [];
    $tableInfo = $definition['table'] ?? [];
    $type = strtoupper((string)($tableInfo['type'] ?? self::$currentTableType));
    $objectLabel = $type === 'VIEW' ? 'view' : 'table';
    $sqlKeyword = $type === 'VIEW' ? 'VIEW' : 'TABLE';
    $qualified = self::quoteQualifiedTable($schema, $table);
    $foreignKeys = self::constraintNames($definition['foreignKeys'] ?? []);
    $referencedBy = self::referencingConstraintNames($definition['referencedBy'] ?? []);
    $content = "The following actions will be performed.\n";
    $content .= "- {$schema}.{$table} {$objectLabel} will be dropped\n";
    $content .= "- " . count((array)($definition['columns'] ?? [])) . " fields will be removed\n";
    if ($type !== 'VIEW') {
      $content .= "- Rows: " . self::formatRowCount($tableInfo['rows'] ?? 0) . "\n";
      $content .= "- Table size: " . self::formatSize($tableInfo['dataLength'] ?? 0) . "\n";
      $content .= "- Index size: " . self::formatSize($tableInfo['indexLength'] ?? 0) . "\n";
      $content .= "- " . count($foreignKeys) . " foreign keys defined on this table will be removed\n";
      $content .= "- " . count($referencedBy) . " foreign keys in other tables reference this table\n";
      $content .= "- " . count((array)($definition['triggers'] ?? [])) . " triggers will be dropped\n";
    }
    $content .= "- Cached table metadata for this connection will be cleared\n";
    $content .= "%CONFIRMATION%";
    \MADB\Query\GeneratedQueryController::open([
      'title' => 'Drop ' . $objectLabel,
      'name' => 'DROP ' . $schema . '.' . $table,
      'sql' => 'DROP ' . $sqlKeyword . ' ' . $qualified . ';',
      'connection' => $response['connection'],
      'schema' => $schema,
      'table' => $table,
      'cacheKeys' => self::tableCacheKeys($schema, [$table]),
      'refresh' => 'tables',
      'confirmation' => [
        'title' => 'Drop ' . $objectLabel,
        'content' => $content
      ]
    ]);
  }

  /** Returns unique outgoing foreign-key constraint names. */
  private static function constraintNames(array $rows): array {
    $names = [];
    foreach ($rows as $row) {
      $name = (string)($row['CONSTRAINT_NAME'] ?? '');
      if ($name !== '') {
        $names[$name] = true;
      }
    }
    return array_keys($names);
  }

  /** Returns unique incoming foreign-key constraint names with source table context. */
  private static function referencingConstraintNames(array $rows): array {
    $names = [];
    foreach ($rows as $row) {
      $schema = (string)($row['CONSTRAINT_SCHEMA'] ?? '');
      $table = (string)($row['TABLE_NAME'] ?? '');
      $name = (string)($row['CONSTRAINT_NAME'] ?? '');
      if ($name !== '') {
        $names[$schema . '.' . $table . '.' . $name] = true;
      }
    }
    return array_keys($names);
  }

  /** Formats approximate row counts from INFORMATION_SCHEMA. */
  private static function formatRowCount($rows): string {
    return number_format(max(0, (int)$rows));
  }

  /** Formats bytes for confirmation messages. */
  private static function formatSize($bytes): string {
    return \MADB\App\Format::bytes($bytes);
  }

}
