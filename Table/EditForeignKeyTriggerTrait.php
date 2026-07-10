<?php

namespace MADB\Table;

/** Coordinates foreign-key and trigger editor panels inside the table editor. */
trait EditForeignKeyTriggerTrait {

  /** Coordinates add foreign key work in the table editor. */
  public static function addForeignKey($panel = null) {
    if (!self::hasNamedColumns()) {
      self::warnNoColumnsFor('foreign keys');
      return;
    }
    $index = count(self::$foreignKeys);
    $name = "\0new-foreign-key-" . $index;
    $sourceColumn = self::firstNamedColumn();
    self::$foreignKeys[] = [
      'CONSTRAINT_NAME' => $name,
      'COLUMN_NAME' => $sourceColumn,
      'REFERENCED_TABLE_SCHEMA' => self::$schema,
      'REFERENCED_TABLE_NAME' => '',
      'REFERENCED_COLUMN_NAME' => '',
      'UPDATE_RULE' => 'RESTRICT',
      'DELETE_RULE' => 'RESTRICT',
      'ORDINAL_POSITION' => 1
    ];
    self::$editingItem = $index;
    self::$addingItem = ['type' => 'foreignKey', 'index' => $index];
    $panel = self::itemPanel('table-foreign-key-editor');
    $panel->setValue([
      'foreign-key-name' => '',
      'foreign-key-schema' => self::$schema,
      'foreign-key-table' => self::currentTableName(),
      'foreign-key-update-rule' => 'RESTRICT',
      'foreign-key-delete-rule' => 'RESTRICT'
    ]);
    self::setForeignKeySourceColumnList($sourceColumn === '' ? [] : [$sourceColumn]);
    self::loadForeignKeySchemas(self::$schema);
    self::loadForeignKeyTables(self::$schema);
    self::loadForeignKeyTargetFields('', '');
    self::showItemPanel($panel, 'foreign-key-name');
  }

  /** Opens the foreign key editor panel or view in the table editor. */
  public static function openForeignKeyEditor($item) {
    [$index, $foreignKey] = self::findForeignKey($item->getValue());
    if ($foreignKey === false) {
      return;
    }
    self::$editingItem = $index;
    self::$addingItem = false;
    $panel = self::itemPanel('table-foreign-key-editor');
    $foreignKeyRows = self::matchingForeignKeyRows($foreignKey);
    $sourceColumns = [];
    $targetColumns = [];
    foreach ($foreignKeyRows as $row) {
      $sourceColumn = $row['COLUMN_NAME'] ?? '';
      $targetColumn = $row['REFERENCED_COLUMN_NAME'] ?? '';
      if ($sourceColumn !== '') {
        $sourceColumns[] = $sourceColumn;
      }
      if ($targetColumn !== '') {
        $targetColumns[] = $targetColumn;
      }
    }
    $targetSchema = $foreignKey['REFERENCED_TABLE_SCHEMA'] ?? '';
    $targetTable = $foreignKey['REFERENCED_TABLE_NAME'] ?? '';
    $panel->setValue([
      'foreign-key-name' => $foreignKey['CONSTRAINT_NAME'] ?? '',
      'foreign-key-schema' => self::$schema,
      'foreign-key-table' => self::$table,
      'foreign-key-update-rule' => $foreignKey['UPDATE_RULE'] ?? '',
      'foreign-key-delete-rule' => $foreignKey['DELETE_RULE'] ?? ''
    ]);
    self::setForeignKeySourceColumnList($sourceColumns);
    self::loadForeignKeySchemas($targetSchema);
    self::loadForeignKeyTables($targetSchema, $targetTable);
    self::loadForeignKeyTargetFields($targetSchema, $targetTable, $targetColumns);
    self::showItemPanel($panel, 'foreign-key-name');
  }

  /** Saves foreign key editor values from the table editor panel or state. */
  public static function saveForeignKeyEditor($panel) {
    if (self::$editingItem === false || !isset(self::$foreignKeys[self::$editingItem])) {
      return;
    }
    $values = $panel->getValue();
    $sourceColumns = $values['foreign-key-column'] ?? [];
    $targetColumns = $values['foreign-key-target-column'] ?? [];
    if (!is_array($sourceColumns)) {
      $sourceColumns = [$sourceColumns];
    }
    if (!is_array($targetColumns)) {
      $targetColumns = [$targetColumns];
    }
    $sourceColumns = array_values(array_filter(array_map('trim', $sourceColumns), fn($column) => $column !== ''));
    $targetColumns = array_values(array_filter(array_map('trim', $targetColumns), fn($column) => $column !== ''));
    if (empty($sourceColumns) || empty($targetColumns)) {
      \SPTK\Elements\WarningPanel::forge('Missing columns', 'Please select at least one source and referenced column.');
      return;
    }
    if (count($sourceColumns) !== count($targetColumns)) {
      \SPTK\Elements\WarningPanel::forge('Column count mismatch', 'Please select the same number of source and referenced columns.');
      return;
    }
    $oldForeignKey = self::$foreignKeys[self::$editingItem];
    $oldRows = self::matchingForeignKeyRows($oldForeignKey);
    $template = reset($oldRows);
    if ($template === false) {
      return;
    }
    $newRows = [];
    foreach ($sourceColumns as $index => $sourceColumn) {
      $row = $template;
      $row['CONSTRAINT_NAME'] = $values['foreign-key-name'] ?? '';
      $row['COLUMN_NAME'] = $sourceColumn;
      $row['REFERENCED_TABLE_SCHEMA'] = (string) ($values['foreign-key-target-schema'] ?? '');
      $row['REFERENCED_TABLE_NAME'] = (string) ($values['foreign-key-target-table'] ?? '');
      $row['REFERENCED_COLUMN_NAME'] = $targetColumns[$index];
      $row['UPDATE_RULE'] = $values['foreign-key-update-rule'] ?? '';
      $row['DELETE_RULE'] = $values['foreign-key-delete-rule'] ?? '';
      $row['ORDINAL_POSITION'] = $index + 1;
      $newRows[] = $row;
    }
    $foreignKeys = [];
    $inserted = false;
    foreach (self::$foreignKeys as $index => $foreignKey) {
      if (isset($oldRows[$index])) {
        if (!$inserted) {
          array_push($foreignKeys, ...$newRows);
          $inserted = true;
        }
        continue;
      }
      $foreignKeys[] = $foreignKey;
    }
    self::$foreignKeys = $foreignKeys;
    self::setForeignKeys(self::$foreignKeys);
    self::$addingItem = false;
    self::closeItemEditor($panel);
  }

  /** Coordinates add trigger work in the table editor. */
  public static function addTrigger($panel = null) {
    $index = count(self::$triggers);
    self::$triggers[] = [
      'TRIGGER_NAME' => '',
      'ACTION_TIMING' => 'BEFORE',
      'EVENT_MANIPULATION' => 'INSERT',
      'ACTION_STATEMENT' => ''
    ];
    self::$editingItem = $index;
    self::$addingItem = ['type' => 'trigger', 'index' => $index];
    $panel = self::itemPanel('table-trigger-editor');
    $panel->setValue([
      'trigger-name' => '',
      'trigger-timing' => 'BEFORE',
      'trigger-event' => 'INSERT',
      'trigger-statement' => ''
    ]);
    self::showItemPanel($panel, 'trigger-name');
  }

  /** Opens the trigger editor panel or view in the table editor. */
  public static function openTriggerEditor($item) {
    [$index, $trigger] = self::findTrigger($item->getValue());
    if ($trigger === false) {
      return;
    }
    self::$editingItem = $index;
    self::$addingItem = false;
    $panel = self::itemPanel('table-trigger-editor');
    $panel->setValue([
      'trigger-name' => $trigger['TRIGGER_NAME'] ?? '',
      'trigger-timing' => $trigger['ACTION_TIMING'] ?? '',
      'trigger-event' => $trigger['EVENT_MANIPULATION'] ?? '',
      'trigger-statement' => $trigger['ACTION_STATEMENT'] ?? ''
    ]);
    self::showItemPanel($panel, 'trigger-name');
  }

  /** Saves trigger editor values from the table editor panel or state. */
  public static function saveTriggerEditor($panel) {
    if (self::$editingItem === false || !isset(self::$triggers[self::$editingItem])) {
      return;
    }
    $values = $panel->getValue();
    if (trim(self::textValue($values['trigger-name'] ?? '')) === '') {
      \SPTK\Elements\WarningPanel::forge('No trigger name', 'Please enter a trigger name before saving.');
      return;
    }
    self::$triggers[self::$editingItem]['TRIGGER_NAME'] = $values['trigger-name'] ?? '';
    self::$triggers[self::$editingItem]['ACTION_TIMING'] = $values['trigger-timing'] ?? '';
    self::$triggers[self::$editingItem]['EVENT_MANIPULATION'] = $values['trigger-event'] ?? '';
    self::$triggers[self::$editingItem]['ACTION_STATEMENT'] = $values['trigger-statement'] ?? '';
    self::setTriggers(self::$triggers);
    self::$addingItem = false;
    self::closeItemEditor($panel);
  }

  /** Closes the item editor panel in the table editor. */
  public static function closeItemEditor($panel) {
    if (is_array(self::$addingItem)) {
      switch (self::$addingItem['type'] ?? '') {
        case 'column':
          $index = self::$addingItem['index'] ?? false;
          if ($index !== false && isset(self::$columns[$index])) {
            array_splice(self::$columns, $index, 1);
            self::setColumns(self::$columns);
          }
          break;
        case 'index':
          $name = self::$addingItem['name'] ?? false;
          if ($name !== false) {
            self::$indexes = array_values(array_filter(self::$indexes, fn($index) => ($index['INDEX_NAME'] ?? '') !== $name));
            self::syncColumnKeysFromIndexes();
            self::setColumns(self::$columns);
            self::setIndexes(self::$indexes);
          }
          break;
        case 'foreignKey':
          $index = self::$addingItem['index'] ?? false;
          if ($index !== false && isset(self::$foreignKeys[$index])) {
            array_splice(self::$foreignKeys, $index, 1);
            self::setForeignKeys(self::$foreignKeys);
          }
          break;
        case 'trigger':
          $index = self::$addingItem['index'] ?? false;
          if ($index !== false && isset(self::$triggers[$index])) {
            array_splice(self::$triggers, $index, 1);
            self::setTriggers(self::$triggers);
          }
          break;
      }
    }
    self::$editingItem = false;
    self::$addingItem = false;
    $panel->hide();
    \SPTK\Element::refresh();
  }

}
