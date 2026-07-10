<?php

namespace MADB\Table;

/** Removes selected columns, indexes, foreign keys, or triggers from the table editor lists. */
trait EditDeleteTrait {

  /** Selects list value and refreshes related table editor state. */
  private static function selectedListValue($listName) {
    $list = self::listElement($listName);
    return $list === false ? false : $list->getValue();
  }

  /** Coordinates warn no item selected work in the table editor. */
  private static function warnNoItemSelected($itemType) {
    \SPTK\Elements\WarningPanel::forge('No ' . $itemType . ' selected', 'Please select a ' . $itemType . ' before deleting.');
  }

  /** Removes column from the table editor. */
  private static function deleteColumn() {
    [$index, $column] = self::findColumn(self::selectedListValue('table-editor-columns'));
    if ($column === false) {
      self::warnNoItemSelected('field');
      return;
    }
    $name = $column['COLUMN_NAME'] ?? '';
    array_splice(self::$columns, $index, 1);
    if ($name !== '') {
      self::$indexes = array_values(array_filter(self::$indexes, fn($index) => ($index['COLUMN_NAME'] ?? '') !== $name));
      self::$foreignKeys = array_values(array_filter(self::$foreignKeys, fn($foreignKey) => ($foreignKey['COLUMN_NAME'] ?? '') !== $name));
    }
    self::syncColumnKeysFromIndexes();
    self::setColumns(self::$columns);
    self::setIndexes(self::$indexes);
    self::setForeignKeys(self::$foreignKeys);
    \SPTK\Element::refresh();
  }

  /** Removes index from the table editor. */
  private static function deleteIndex() {
    $name = self::selectedListValue('table-editor-indexes');
    if ($name === false || $name === '') {
      self::warnNoItemSelected('index');
      return;
    }
    $count = count(self::$indexes);
    self::$indexes = array_values(array_filter(self::$indexes, fn($index) => ($index['INDEX_NAME'] ?? '') !== $name));
    if (count(self::$indexes) === $count) {
      self::warnNoItemSelected('index');
      return;
    }
    self::syncColumnKeysFromIndexes();
    self::setColumns(self::$columns);
    self::setIndexes(self::$indexes);
    \SPTK\Element::refresh();
  }

  /** Removes foreign key from the table editor. */
  private static function deleteForeignKey() {
    [$index, $foreignKey] = self::findForeignKey(self::selectedListValue('table-editor-foreign-keys'));
    if ($foreignKey === false) {
      self::warnNoItemSelected('foreign key');
      return;
    }
    $rows = self::matchingForeignKeyRows($foreignKey);
    self::$foreignKeys = array_values(array_filter(self::$foreignKeys, fn($row, $index) => !isset($rows[$index]), ARRAY_FILTER_USE_BOTH));
    self::setForeignKeys(self::$foreignKeys);
    \SPTK\Element::refresh();
  }

  /** Removes trigger from the table editor. */
  private static function deleteTrigger() {
    [$index, $trigger] = self::findTrigger(self::selectedListValue('table-editor-triggers'));
    if ($trigger === false) {
      self::warnNoItemSelected('trigger');
      return;
    }
    array_splice(self::$triggers, $index, 1);
    self::setTriggers(self::$triggers);
    \SPTK\Element::refresh();
  }

}
