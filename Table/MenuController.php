<?php

namespace MADB\Table;

class MenuController {

  private static $currentSchema = false;
  private static $currentTable = false;

  public static function setCurrentSchema($schema) {
    self::$currentSchema = $schema;
    self::$currentTable = false;
    \MADB\Main\ScreenController::refreshTitle();
  }

  public static function getCurrentSchema() {
    return self::$currentSchema;
  }

  public static function getCurrentTable() {
    return self::$currentTable;
  }

  private static function quoteIdentifier($identifier) {
    return '`' . str_replace('`', '``', $identifier) . '`';
  }

  private static function formatSelectQuery($schema, $table) {
    return "SELECT *\n" .
      "FROM " . self::quoteIdentifier($schema) . "." . self::quoteIdentifier($table) . "\n" .
      "WHERE 1\n" .
      "LIMIT 1000;";
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

  public static function reset() {
    self::$currentSchema = false;
    self::$currentTable = false;
    $menuBox = \SPTK\Element::byName('menu-table-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Select a ' . self::schemaLabel() . '!');
    \MADB\Main\ScreenController::refreshTitle();
    \SPTK\Element::refresh();
  }

  public static function loading() {
    self::$currentTable = false;
    $menuBox = \SPTK\Element::byName('menu-table-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Loading...');
    \MADB\Main\ScreenController::refreshTitle();
    \SPTK\Element::refresh();
  }

  public static function loadFailed() {
    self::$currentTable = false;
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
    foreach ($response['result'] as $table) {
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
      $menuItem->setSelectable('tables');
    }
    \SPTK\Element::refresh();
  }

  public static function selectTable($item) {
    if (is_string($item)) {
      self::$currentTable = $item;
    } else {
      self::$currentTable = $item->getValue();
    }
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
    $sql = self::formatSelectQuery(self::$currentSchema, self::$currentTable);
    $name = 'SELECT ' . self::$currentSchema . '.' . self::$currentTable;
    \MADB\Main\ScreenController::addQuery($name, $sql, $connection['name'], self::$currentSchema, self::$currentTable);
  }

}
