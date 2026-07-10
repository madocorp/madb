<?php

namespace MADB\Table;

/** Renders and searches the table editor lists for columns, indexes, foreign keys, and triggers. */
trait EditListStateTrait {

  /** Applies columns values to table editor state or controls. */
  private static function setColumns($columns) {
    $list = self::listElement('table-editor-columns');
    $list->clear();
    if (empty($columns)) {
      self::setEmptyListPlaceholder('table-editor-columns');
      return;
    }
    foreach ($columns as $column) {
      $name = $column['COLUMN_NAME'] ?? '';
      $type = $column['COLUMN_TYPE'] ?? '';
      $attributes = [];
      if (($column['IS_NULLABLE'] ?? '') === 'NO') {
        $attributes[] = 'NOT NULL';
      }
      if (in_array($column['COLUMN_KEY'] ?? '', ['PRI', 'UNI'])) {
        $attributes[] = ($column['COLUMN_KEY'] ?? '') === 'PRI' ? 'PRIMARY' : 'UNIQUE';
      }
      if (stripos($column['EXTRA'] ?? '', 'auto_increment') !== false) {
        $attributes[] = 'AUTO INC';
      }
      $default = $column['COLUMN_DEFAULT'] ?? '';
      $item = new \SPTK\Elements\ListItem($list);
      $item->setValue($name);
      self::addCell($item, 'w20', $name);
      self::addCell($item, 'w20', $type);
      self::addCell($item, 'w40', implode(' ', $attributes));
      self::addCell($item, 'w20', $default);
    }
  }

  /** Applies indexes values to table editor state or controls. */
  private static function setIndexes($indexes) {
    $list = self::listElement('table-editor-indexes');
    $list->clear();
    if (empty($indexes)) {
      self::setEmptyListPlaceholder('table-editor-indexes');
      return;
    }
    $groupedIndexes = [];
    foreach ($indexes as $index) {
      $name = $index['INDEX_NAME'] ?? '';
      if (!isset($groupedIndexes[$name])) {
        if (self::isPrimaryIndexRow($index)) {
          $type = 'PRIMARY';
        } else {
          $unique = ((int) ($index['NON_UNIQUE'] ?? 1)) === 0 ? 'UNIQUE' : 'INDEX';
          $storageType = strtoupper(self::normalizeValue($index['INDEX_TYPE'] ?? ''));
          $type = $storageType === 'DEFAULT' || $storageType === '' ? $unique : trim($unique . ' ' . $storageType);
        }
        $groupedIndexes[$name] = [
          'type' => $type,
          'columns' => []
        ];
      }
      $column = $index['COLUMN_NAME'] ?? '';
      if (($index['COLLATION'] ?? '') === 'D') {
        $column = '-' . $column;
      }
      $groupedIndexes[$name]['columns'][] = $column;
    }
    foreach ($groupedIndexes as $name => $index) {
      $item = new \SPTK\Elements\ListItem($list);
      $item->setValue($name);
      self::addCell($item, 'w25', $name);
      self::addCell($item, 'w25', $index['type']);
      self::addCell($item, 'w50', implode(', ', $index['columns']));
    }
  }

  /** Applies foreign keys values to table editor state or controls. */
  private static function setForeignKeys($foreignKeys) {
    $list = self::listElement('table-editor-foreign-keys');
    $list->clear();
    if (empty($foreignKeys)) {
      self::setEmptyListPlaceholder('table-editor-foreign-keys');
      return;
    }
    foreach ($foreignKeys as $foreignKey) {
      $name = $foreignKey['CONSTRAINT_NAME'] ?? '';
      $column = $foreignKey['COLUMN_NAME'] ?? '';
      $targetSchema = $foreignKey['REFERENCED_TABLE_SCHEMA'] ?? '';
      $targetTable = $foreignKey['REFERENCED_TABLE_NAME'] ?? '';
      $targetColumn = $foreignKey['REFERENCED_COLUMN_NAME'] ?? '';
      $rules = 'U:' . ($foreignKey['UPDATE_RULE'] ?? '') . ' D:' . ($foreignKey['DELETE_RULE'] ?? '');
      $item = new \SPTK\Elements\ListItem($list);
      $item->setValue(self::makeItemKey([$name, $column, $targetSchema, $targetTable, $targetColumn]));
      self::addCell($item, 'w25', $name);
      self::addCell($item, 'w20', $column);
      self::addCell($item, 'w40', "{$targetSchema}.{$targetTable}.{$targetColumn}");
      self::addCell($item, 'w15', $rules);
    }
  }

  /** Applies triggers values to table editor state or controls. */
  private static function setTriggers($triggers) {
    $list = self::listElement('table-editor-triggers');
    $list->clear();
    if (empty($triggers)) {
      self::setEmptyListPlaceholder('table-editor-triggers');
      return;
    }
    foreach ($triggers as $trigger) {
      $name = $trigger['TRIGGER_NAME'] ?? '';
      $timing = $trigger['ACTION_TIMING'] ?? '';
      $event = $trigger['EVENT_MANIPULATION'] ?? '';
      $statement = $trigger['ACTION_STATEMENT'] ?? '';
      $item = new \SPTK\Elements\ListItem($list);
      $item->setValue($name);
      self::addCell($item, 'w25', $name);
      self::addCell($item, 'w10', $timing);
      self::addCell($item, 'w10', $event);
      self::addCell($item, 'w55', $statement);
    }
  }

  /** Finds column data inside the table editor. */
  private static function findColumn($name) {
    foreach (self::$columns as $index => $column) {
      if (($column['COLUMN_NAME'] ?? '') === $name) {
        return [$index, $column];
      }
    }
    return [false, false];
  }

  /** Finds foreign key data inside the table editor. */
  private static function findForeignKey($key) {
    [$name, $column, $targetSchema, $targetTable, $targetColumn] = array_pad(self::splitItemKey($key), 5, '');
    foreach (self::$foreignKeys as $index => $foreignKey) {
      if (
        ($foreignKey['CONSTRAINT_NAME'] ?? '') === $name &&
        ($foreignKey['COLUMN_NAME'] ?? '') === $column &&
        ($foreignKey['REFERENCED_TABLE_SCHEMA'] ?? '') === $targetSchema &&
        ($foreignKey['REFERENCED_TABLE_NAME'] ?? '') === $targetTable &&
        ($foreignKey['REFERENCED_COLUMN_NAME'] ?? '') === $targetColumn
      ) {
        return [$index, $foreignKey];
      }
    }
    return [false, false];
  }

  /** Coordinates matching foreign key rows work in the table editor. */
  private static function matchingForeignKeyRows($foreignKey) {
    $rows = [];
    $name = $foreignKey['CONSTRAINT_NAME'] ?? '';
    $targetSchema = $foreignKey['REFERENCED_TABLE_SCHEMA'] ?? '';
    $targetTable = $foreignKey['REFERENCED_TABLE_NAME'] ?? '';
    foreach (self::$foreignKeys as $index => $row) {
      if (
        ($row['CONSTRAINT_NAME'] ?? '') === $name &&
        ($row['REFERENCED_TABLE_SCHEMA'] ?? '') === $targetSchema &&
        ($row['REFERENCED_TABLE_NAME'] ?? '') === $targetTable
      ) {
        $rows[$index] = $row;
      }
    }
    uasort($rows, fn($a, $b) => ((int) ($a['ORDINAL_POSITION'] ?? 0)) <=> ((int) ($b['ORDINAL_POSITION'] ?? 0)));
    return $rows;
  }

  /** Finds trigger data inside the table editor. */
  private static function findTrigger($name) {
    foreach (self::$triggers as $index => $trigger) {
      if (($trigger['TRIGGER_NAME'] ?? '') === $name) {
        return [$index, $trigger];
      }
    }
    return [false, false];
  }

  /** Coordinates add column work in the table editor. */
  public static function addColumn($panel = null) {
    $index = count(self::$columns);
    self::$columns[] = [
      'COLUMN_NAME' => '',
      'COLUMN_TYPE' => 'INT',
      'IS_NULLABLE' => 'YES',
      'COLUMN_DEFAULT' => '',
      'EXTRA' => '',
      'COLUMN_KEY' => '',
      'COLUMN_COMMENT' => '',
      'CHARACTER_SET_NAME' => '',
      'COLLATION_NAME' => '',
      'ORDINAL_POSITION' => $index + 1
    ];
    self::$editingItem = $index;
    self::$addingItem = ['type' => 'column', 'index' => $index];
    self::setColumns(self::$columns);
    $panel = self::itemPanel('table-column-editor');
    $panel->setValue([
      'column-name' => '',
      'column-parameter' => '',
      'column-primary' => false,
      'column-unique' => false,
      'column-not-null' => false,
      'column-auto-increment' => false,
      'column-unsigned' => false,
      'column-zerofill' => false,
      'column-default' => '',
      'column-charset' => '',
      'column-collation' => '',
      'column-comment' => ''
    ]);
    self::selectColumnTypeInList('INT');
    self::showItemPanel($panel, 'column-name');
  }

  /** Coordinates add work in the table editor. */
  public static function add($panel = null) {
    $tabs = self::tabs();
    $name = $tabs === false ? '' : $tabs->getCurrentTabContentName();
    switch ($name) {
      case 'table-editor-column':
        self::addColumn($panel);
        return;
      case 'table-editor-index':
        self::addIndex($panel);
        return;
      case 'table-editor-foreign-key':
        self::addForeignKey($panel);
        return;
      case 'table-editor-trigger':
        self::addTrigger($panel);
        return;
    }
  }

  /** Removes delete from the table editor. */
  public static function delete($panel = null) {
    $tabs = self::tabs();
    $name = $tabs === false ? '' : $tabs->getCurrentTabContentName();
    switch ($name) {
      case 'table-editor-column':
        self::deleteColumn();
        return;
      case 'table-editor-index':
        self::deleteIndex();
        return;
      case 'table-editor-foreign-key':
        self::deleteForeignKey();
        return;
      case 'table-editor-trigger':
        self::deleteTrigger();
        return;
    }
  }

}
