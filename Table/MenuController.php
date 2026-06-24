<?php

namespace MADB\Table;

class MenuController {

  private static $currentSchema = false;
  private static $currentTable = false;

  public static function setCurrentSchema($schema) {
    self::$currentSchema = $schema;
    self::$currentTable = false;
    \MADB\Main\ScreenController::setSelectedSchemaAndTable(self::$currentSchema, self::$currentTable);
    \MADB\Main\ScreenController::refreshTitle();
  }

  public static function restoreSelection($schema, $table) {
    self::$currentSchema = $schema;
    self::$currentTable = $table;
  }

  public static function getCurrentSchema() {
    return self::$currentSchema;
  }

  public static function getCurrentTable() {
    return self::$currentTable;
  }

  private static function schemaLabel() {
    $labels = \MADB\Connection\MenuController::getMenuLabels();
    return strtolower($labels['schema']);
  }

  private static function tableTypeLabel($type) {
    switch ($type) {
      case 'BASE TABLE': return 'table';
      case 'VIEW': return 'view';
      case 'SYSTEM VIEW': return 'sysview';
      case 'COLLECTION': return 'collection';
      default: return strtolower((string) $type);
    }
  }

  private static function quoteIdentifier($identifier) {
    return '`' . str_replace('`', '``', $identifier) . '`';
  }

  public static function reset($clearState = true) {
    if ($clearState) {
      self::$currentSchema = false;
      self::$currentTable = false;
      \MADB\Main\ScreenController::setSelectedSchemaAndTable(self::$currentSchema, self::$currentTable);
    }
    $menuBox = \SPTK\Element::byName('menu-table-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Select a ' . self::schemaLabel() . '!');
    \MADB\Main\ScreenController::refreshTitle();
    \SPTK\Element::refresh();
  }

  public static function loading($clearTable = true) {
    if ($clearTable) {
      self::$currentTable = false;
      \MADB\Main\ScreenController::setSelectedSchemaAndTable(self::$currentSchema, self::$currentTable);
    }
    $menuBox = \SPTK\Element::byName('menu-table-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Loading...');
    \MADB\Main\ScreenController::refreshTitle();
    \SPTK\Element::refresh();
  }

  public static function loadFailed($clearTable = true) {
    if ($clearTable) {
      self::$currentTable = false;
      \MADB\Main\ScreenController::setSelectedSchemaAndTable(self::$currentSchema, self::$currentTable);
    }
    $menuBox = \SPTK\Element::byName('menu-table-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Could not get the list.');
    \MADB\Main\ScreenController::refreshTitle();
    \SPTK\Element::refresh();
  }

  public static function setTables($response) {
    if ($response['status'] !== 'OK') {
      self::loadFailed();
      return;
    }
    $menuBox = \SPTK\Element::byName('menu-table-list');
    $menuBox->clear();
    $menuBox->setOnSelect('\MADB\Table\MenuController::selectTable');
    $operationMenu = new \SPTK\Elements\MenuBoxItem($menuBox, 'menu-table-operations', 'MenuSeparator');
    $operationMenu->setValue('Operations');
    $operationMenu->setSubmenu('true');
    foreach ($response['result'] as $index => $table) {
      if (is_array($table)) {
        $name = $table['name'] ?? '';
        $type = $table['type'] ?? '';
      } else {
        $name = $table;
        $type = 'BASE TABLE';
      }
      $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
      $menuItem->setValue($name);
      $menuItem->setText($name);
      $menuItem->setRight(self::tableTypeLabel($type));
      $menuItem->setFilterable('true');
      $menuItem->setSelectable('tables');
      if ($name === self::$currentTable) {
        $menuItem->setSelected('true');
        $menuBox->moveCursor($index + 1);
      }
    }
    \SPTK\Element::refresh();
  }

  public static function selectTable($item) {
    if (is_string($item)) {
      self::$currentTable = $item;
    } else {
      self::$currentTable = $item->getValue();
    }
    \MADB\Main\ScreenController::setSelectedSchemaAndTable(self::$currentSchema, self::$currentTable);
    \MADB\Main\ScreenController::refreshTitle();
  }

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
    \MADB\Main\ScreenController::executeQuery();
  }

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
