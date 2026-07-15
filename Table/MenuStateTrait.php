<?php

namespace MADB\Table;

/** Maintains selected schema/table menu state and renders the table submenu for the active connection. */
trait MenuStateTrait {

  /** Applies current schema values to table menu state or controls. */
  public static function setCurrentSchema($schema) {
    self::$currentSchema = $schema;
    self::$currentTable = false;
    self::$currentTableType = false;
    self::$tableTypes = [];
    \MADB\Main\ScreenController::setSelectedSchemaAndTable(self::$currentSchema, self::$currentTable);
    \MADB\Main\ScreenController::refreshTitle();
  }

  /** Coordinates restore selection work in the table menu. */
  public static function restoreSelection($schema, $table) {
    self::$currentSchema = $schema;
    self::$currentTable = $table;
    self::$currentTableType = self::$tableTypes[$table] ?? false;
  }

  /** Returns current schema data used by the table menu. */
  public static function getCurrentSchema() {
    return self::$currentSchema;
  }

  /** Returns current table data used by the table menu. */
  public static function getCurrentTable() {
    return self::$currentTable;
  }

  /** Returns current table type data used by the table menu. */
  public static function getCurrentTableType() {
    return self::$currentTableType;
  }

  /** Coordinates schema label work in the table menu. */
  private static function schemaLabel() {
    $labels = \MADB\Connection\MenuController::getMenuLabels();
    return strtolower($labels['schema']);
  }

  /** Coordinates table type label work in the table menu. */
  private static function tableTypeLabel($type) {
    switch ($type) {
      case 'BASE TABLE': return 'table';
      case 'VIEW': return 'view';
      case 'SYSTEM VIEW': return 'sysview';
      case 'COLLECTION': return 'collection';
      default: return strtolower((string) $type);
    }
  }

  /** Coordinates table type prefix work in the table menu. */
  private static function tableTypePrefix($type) {
    switch ($type) {
      case 'BASE TABLE': return false;
      case 'VIEW': return 'V ';
      case 'SYSTEM VIEW': return 'S ';
      case 'COLLECTION': return 'C ';
      default: return strtoupper((string) $type) . ' ';
    }
  }

  /** Selects menu item and refreshes related table menu state. */
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

  /** Escapes identifier for SQL built by the table menu. */
  private static function quoteIdentifier($identifier) {
    return '`' . str_replace('`', '``', $identifier) . '`';
  }

  /** Escapes qualified table for SQL built by the table menu. */
  private static function quoteQualifiedTable($schema, $table) {
    return self::quoteIdentifier($schema) . '.' . self::quoteIdentifier($table);
  }

  /** Coordinates text value work in the table menu. */
  private static function textValue($value) {
    if (is_array($value)) {
      return implode("\n", $value);
    }
    return (string) $value;
  }

  /** Coordinates schema options work in the table menu. */
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

  /** Formats field list text for the table menu. */
  private static function formatFieldList($fields) {
    if (!is_array($fields) || empty($fields)) {
      return '*';
    }
    return implode(",\n  ", array_map(fn($field) => self::quoteIdentifier($field), $fields));
  }

  /** Clears table menu selection and placeholder state. */
  public static function reset($clearState = true) {
    if ($clearState) {
      self::$currentSchema = false;
      self::$currentTable = false;
      self::$currentTableType = false;
      self::$tableTypes = [];
      \MADB\Main\ScreenController::setSelectedSchemaAndTable(self::$currentSchema, self::$currentTable);
    }
    $menuBox = \SPTK\Element::byName('menu-table-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Select a ' . self::schemaLabel() . '!');
    \MADB\Main\ScreenController::refreshTitle();
    \SPTK\Element::refresh();
  }

  /** Shows the table menu loading placeholder while table list data is fetched. */
  public static function loading($clearTable = true) {
    if ($clearTable) {
      self::$currentTable = false;
      self::$currentTableType = false;
      \MADB\Main\ScreenController::setSelectedSchemaAndTable(self::$currentSchema, self::$currentTable);
    }
    $menuBox = \SPTK\Element::byName('menu-table-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Loading...');
    \MADB\Main\ScreenController::refreshTitle();
    \SPTK\Element::refresh();
  }

  /** Shows the table menu failure placeholder after table list loading fails. */
  public static function loadFailed($clearTable = true) {
    if ($clearTable) {
      self::$currentTable = false;
      self::$currentTableType = false;
      \MADB\Main\ScreenController::setSelectedSchemaAndTable(self::$currentSchema, self::$currentTable);
    }
    $menuBox = \SPTK\Element::byName('menu-table-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Could not get the list.');
    \MADB\Main\ScreenController::refreshTitle();
    \SPTK\Element::refresh();
  }

  /** Applies tables values to table menu state or controls. */
  public static function setTables($response) {
    if ($response['status'] !== 'OK') {
      self::loadFailed();
      return;
    }
    $menuBox = \SPTK\Element::byName('menu-table-list');
    $menuBox->clear();
    $menuBox->setOnSelect('\MADB\Table\MenuController::selectTable');
    self::$tableTypes = [];
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
      self::$tableTypes[$name] = $type;
      $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
      $menuItem->setValue($name);
      $menuItem->setText($name);
      $menuItem->setPrefix(self::tableTypePrefix($type));
      $menuItem->setFilterable('true');
      $menuItem->setSelectable('tables');
      $menuItem->setSubmenu('menu-table-item-actions');
      $menuItem->setOnOpen('\MADB\Table\MenuController::selectTable');
      if ($name === self::$currentTable) {
        self::$currentTableType = $type;
        $menuItem->setSelected('true');
        $menuBox->moveCursor($index + 1);
      }
    }
    \SPTK\Element::refresh();
  }

  /** Selects a table from the table menu and updates query workspace context. */
  public static function selectTable($item) {
    if (is_string($item)) {
      self::$currentTable = $item;
    } else {
      self::$currentTable = $item->getValue();
      self::selectMenuItem($item, 'tables');
    }
    self::$currentTableType = self::$tableTypes[self::$currentTable] ?? false;
    \MADB\Main\ScreenController::setSelectedSchemaAndTable(self::$currentSchema, self::$currentTable);
    \MADB\Main\ScreenController::refreshTitle();
  }

  /** Routes modify actions by selected table object type. */
  public static function modify() {
    switch (self::$currentTableType) {
      case 'VIEW':
        \MADB\Table\ViewController::openModify();
        return;
      case 'SYSTEM VIEW':
        \SPTK\Elements\WarningPanel::forge('System view', 'System views are read-only and cannot be modified.');
        return;
      default:
        \MADB\Table\EditorController::openModify();
        return;
    }
  }

}
