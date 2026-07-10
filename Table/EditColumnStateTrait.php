<?php

namespace MADB\Table;

/** Keeps column metadata consistent with primary-key index state and shared table editor values. */
trait EditColumnStateTrait {

  /** Checks is primary index row for table editor decisions. */
  private static function isPrimaryIndexRow($index) {
    return
      ($index['INDEX_NAME'] ?? '') === 'PRIMARY' ||
      strtoupper(self::normalizeValue($index['INDEX_TYPE'] ?? '')) === 'PRIMARY';
  }

  /** Synchronizes primary index column state inside the table editor. */
  private static function syncPrimaryIndexColumn($oldName, $newName, $enabled) {
    $oldName = trim((string) $oldName);
    $newName = trim((string) $newName);
    $primaryRows = [];
    foreach (self::$indexes as $index) {
      if (!self::isPrimaryIndexRow($index)) {
        continue;
      }
      $columnName = trim((string) ($index['COLUMN_NAME'] ?? ''));
      if ($columnName === '') {
        continue;
      }
      if ($columnName === $oldName) {
        $columnName = $newName;
      }
      if (!$enabled && ($columnName === $oldName || $columnName === $newName)) {
        continue;
      }
      if ($columnName === '' || isset($primaryRows[$columnName])) {
        continue;
      }
      $index['INDEX_NAME'] = 'PRIMARY';
      $index['NON_UNIQUE'] = 0;
      $index['INDEX_TYPE'] = 'BTREE';
      $index['COLUMN_NAME'] = $columnName;
      $index['COLLATION'] = $index['COLLATION'] ?? 'A';
      $primaryRows[$columnName] = $index;
    }

    if ($enabled && $newName !== '' && !isset($primaryRows[$newName])) {
      $primaryRows[$newName] = [
        'INDEX_NAME' => 'PRIMARY',
        'NON_UNIQUE' => 0,
        'SEQ_IN_INDEX' => count($primaryRows) + 1,
        'COLUMN_NAME' => $newName,
        'COLLATION' => 'A',
        'CARDINALITY' => '',
        'INDEX_TYPE' => 'BTREE'
      ];
    }

    $sequence = 1;
    foreach ($primaryRows as &$row) {
      $row['SEQ_IN_INDEX'] = $sequence;
      $sequence++;
    }
    unset($row);

    self::$indexes = array_values(array_filter(self::$indexes, fn($index) => !self::isPrimaryIndexRow($index)));
    array_push(self::$indexes, ...array_values($primaryRows));
  }

  /** Synchronizes column keys from indexes state inside the table editor. */
  private static function syncColumnKeysFromIndexes() {
    $primaryColumns = [];
    $uniqueColumns = [];
    foreach (self::$indexes as $index) {
      $column = trim((string) ($index['COLUMN_NAME'] ?? ''));
      if ($column === '') {
        continue;
      }
      if (self::isPrimaryIndexRow($index)) {
        $primaryColumns[$column] = true;
        continue;
      }
      if ((int) ($index['NON_UNIQUE'] ?? 1) === 0) {
        $uniqueColumns[$column] = true;
      }
    }
    foreach (self::$columns as &$column) {
      $name = trim((string) ($column['COLUMN_NAME'] ?? ''));
      if ($name === '') {
        continue;
      }
      if (isset($primaryColumns[$name])) {
        $column['COLUMN_KEY'] = 'PRI';
      } elseif (isset($uniqueColumns[$name])) {
        $column['COLUMN_KEY'] = 'UNI';
      } else {
        $column['COLUMN_KEY'] = '';
      }
    }
    unset($column);
  }

  /** Synchronizes column order from list state inside the table editor. */
  private static function syncColumnOrderFromList() {
    $list = self::listElement('table-editor-columns');
    if ($list === false) {
      return;
    }
    $order = array_values(array_filter($list->getOrderValue(), fn($name) => self::normalizeValue($name) !== ''));
    if (empty($order)) {
      return;
    }
    $columnsByName = [];
    foreach (self::$columns as $column) {
      $name = self::normalizeValue($column['COLUMN_NAME'] ?? '');
      if ($name !== '') {
        $columnsByName[$name] = $column;
      }
    }
    $columns = [];
    $added = [];
    foreach ($order as $name) {
      if (isset($columnsByName[$name])) {
        $columns[] = $columnsByName[$name];
        $added[$name] = true;
      }
    }
    foreach (self::$columns as $column) {
      $name = self::normalizeValue($column['COLUMN_NAME'] ?? '');
      if ($name === '' || !isset($added[$name])) {
        $columns[] = $column;
      }
    }
    self::$columns = $columns;
  }

  /** Escapes identifier for SQL built by the table editor. */
  private static function quoteIdentifier($identifier) {
    return '`' . str_replace('`', '``', $identifier) . '`';
  }

  /** Escapes qualified table for SQL built by the table editor. */
  private static function quoteQualifiedTable($schema, $table) {
    return self::quoteIdentifier($schema) . '.' . self::quoteIdentifier($table);
  }

  /** Escapes string for SQL built by the table editor. */
  private static function quoteString($value) {
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
  }

  /** Coordinates text value work in the table editor. */
  private static function textValue($value) {
    if (is_array($value)) {
      return implode("\n", $value);
    }
    return (string) $value;
  }

  /** Coordinates named value work in the table editor. */
  private static function namedValue($name, $values = []) {
    if (array_key_exists($name, $values)) {
      return $values[$name];
    }
    $element = \SPTK\Element::byName($name, self::panel());
    if ($element === false) {
      return '';
    }
    return $element->getValue();
  }

  /** Coordinates current table name work in the table editor. */
  private static function currentTableName() {
    $table = trim(self::textValue(self::namedValue('table-name')));
    if ($table !== '') {
      return $table;
    }
    return self::$table === false ? '' : self::$table;
  }

  /** Applies select options values to table editor state or controls. */
  private static function setSelectOptions($name, $options, $selected = null) {
    $element = \SPTK\Element::byName($name);
    if ($element !== false) {
      $element->setOptions($options);
      if ($selected !== null) {
        $element->setValue($selected);
      }
    }
  }

}
