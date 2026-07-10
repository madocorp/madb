<?php

namespace MADB\Table;

/** Creates query workspace templates for selecting rows and showing CREATE SQL from the selected table. */
trait MenuRowsTrait {

  /** Selects rows and refreshes related table menu state. */
  public static function selectRows() {
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
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'tableFields',
      'arguments' => [self::$currentSchema, self::$currentTable],
      'callback' => ['\MADB\Table\MenuController', 'selectedRows'],
      'schema' => self::$currentSchema,
      'table' => self::$currentTable,
      'cache' => 'TableFields:' . self::$currentSchema . ':' . self::$currentTable
    ]);
  }

  /** Selects rows and refreshes related table menu state. */
  public static function selectedRows($response) {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not inspect table', $response['result']);
      return;
    }
    $schema = $response['schema'];
    $table = $response['table'];
    $name = 'SELECT ' . $schema . '.' . $table;
    \MADB\Main\ScreenController::addTemplateQuery('SELECT current', $name, $response['connection']['name'], $schema, $table, $response['result']);
  }

  /** Coordinates show rows work in the table menu. */
  public static function showRows() {
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
    $name = 'SHOW ' . self::$currentSchema . '.' . self::$currentTable;
    \MADB\Main\ScreenController::addTemplateQuery('SELECT all', $name, $connection['name'], self::$currentSchema, self::$currentTable);
    \MADB\Main\QueryExecutionController::executeQuery();
  }

  /** Coordinates show create work in the table menu. */
  public static function showCreate() {
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
    $schema = self::quoteIdentifier(self::$currentSchema);
    $table = self::quoteIdentifier(self::$currentTable);
    $sql = "SHOW CREATE TABLE {$schema}.{$table}";
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'query',
      'arguments' => [$sql],
      'callback' => ['\MADB\Table\MenuController', 'showCreated'],
      'schema' => self::$currentSchema,
      'table' => self::$currentTable
    ]);
  }

  /** Coordinates show created work in the table menu. */
  public static function showCreated($response) {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not get SHOW CREATE TABLE', $response['result']);
      return;
    }
    $result = $response['result'];
    $row = $result['rows'][0] ?? false;
    if ($row === false) {
      \SPTK\Elements\ErrorPanel::forge('Could not get SHOW CREATE TABLE', 'The query returned no rows.');
      return;
    }
    $createSql = false;
    foreach ($row as $column => $value) {
      if (strpos($column, 'Create ') === 0) {
        $createSql = $value;
        break;
      }
    }
    if ($createSql === false) {
      \SPTK\Elements\ErrorPanel::forge('Could not get SHOW CREATE TABLE', 'The query result did not contain a CREATE statement.');
      return;
    }
    $schema = $response['schema'];
    $table = $response['table'];
    $name = 'CREATE ' . $schema . '.' . $table;
    \MADB\Main\ScreenController::addQuery($name, $createSql, $response['connection']['name'], $schema, $table);
  }

}
