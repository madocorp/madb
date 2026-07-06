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

  private static function tableTypePrefix($type) {
    switch ($type) {
      case 'BASE TABLE': return false;
      case 'VIEW': return 'V ';
      case 'SYSTEM VIEW': return 'S ';
      case 'COLLECTION': return 'C ';
      default: return strtoupper((string) $type) . ' ';
    }
  }

  private static function selectMenuItem($item, $group) {
    $menuBox = $item->findAncestorByType('MenuBox');
    if ($menuBox === false) {
      return;
    }
    foreach ($menuBox->getDescendants() as $descendant) {
      if (!method_exists($descendant, 'isSelectable') || $descendant->isSelectable() !== $group) {
        continue;
      }
      if ($descendant->getId() === $item->getId()) {
        if (!$descendant->isSelected()) {
          $descendant->select();
        }
      } else {
        $descendant->deselect();
      }
    }
  }

  private static function quoteIdentifier($identifier) {
    return '`' . str_replace('`', '``', $identifier) . '`';
  }

  private static function quoteQualifiedTable($schema, $table) {
    return self::quoteIdentifier($schema) . '.' . self::quoteIdentifier($table);
  }

  private static function textValue($value) {
    if (is_array($value)) {
      return implode("\n", $value);
    }
    return (string) $value;
  }

  private static function schemaOptions() {
    $options = [];
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    if ($menuBox !== false) {
      foreach ($menuBox->getDescendants() as $item) {
        if (method_exists($item, 'isSelectable') && $item->isSelectable() === 'schemas') {
          $value = $item->getValue();
          if ($value !== false && $value !== '') {
            $options[] = $value;
          }
        }
      }
    }
    if (self::$currentSchema !== false && self::$currentSchema !== '' && !in_array(self::$currentSchema, $options, true)) {
      $options[] = self::$currentSchema;
    }
    return $options;
  }

  private static function formatFieldList($fields) {
    if (!is_array($fields) || empty($fields)) {
      return '*';
    }
    return implode(",\n  ", array_map(fn($field) => self::quoteIdentifier($field), $fields));
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
    $operationMenu->setValue('Create');
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
      $menuItem->setPrefix(self::tableTypePrefix($type));
      $menuItem->setFilterable('true');
      $menuItem->setSelectable('tables');
      $menuItem->setSubmenu('menu-table-item-actions');
      $menuItem->setOnOpen('\MADB\Table\MenuController::selectTable');
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
      self::selectMenuItem($item, 'tables');
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
    $panel->activateInput('table-copy-schema');
    \SPTK\Element::refresh();
  }

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
    $panel->hide();
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'tableFields',
      'arguments' => [self::$currentSchema, self::$currentTable],
      'callback' => ['\MADB\Table\MenuController', 'copied'],
      'schema' => self::$currentSchema,
      'table' => self::$currentTable,
      'targetSchema' => $targetSchema,
      'targetTable' => $targetTable,
      'cache' => 'TableFields:' . self::$currentSchema . ':' . self::$currentTable
    ]);
    \SPTK\Element::refresh();
  }

  public static function copied($response) {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not inspect table', $response['result']);
      return;
    }
    $sourceSchema = $response['schema'];
    $sourceTable = $response['table'];
    $targetSchema = $response['targetSchema'];
    $targetTable = $response['targetTable'] ?? $sourceTable;
    $fields = $response['result'];
    $fieldList = self::formatFieldList($fields);
    $target = self::quoteQualifiedTable($targetSchema, $targetTable);
    $source = self::quoteQualifiedTable($sourceSchema, $sourceTable);
    if ($fieldList === '*') {
      $sql = "INSERT INTO {$target}\nSELECT *\nFROM {$source};";
    } else {
      $sql = "INSERT INTO {$target}\n  ({$fieldList})\nSELECT {$fieldList}\nFROM {$source};";
    }
    $name = 'COPY ' . $sourceSchema . '.' . $sourceTable . ' -> ' . $targetSchema . '.' . $targetTable;
    \MADB\Main\ScreenController::addQuery($name, $sql, $response['connection']['name'], $sourceSchema, $sourceTable);
  }

}
