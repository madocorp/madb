<?php

namespace MADB\Table;

/** Coordinates the column and index editor panels inside the table editor. */
trait EditColumnIndexTrait {

  /** Opens the column editor panel or view in the table editor. */
  public static function openColumnEditor($item) {
    [$index, $column] = self::findColumn($item->getValue());
    if ($column === false) {
      return;
    }
    self::$editingItem = $index;
    self::$addingItem = false;
    $type = self::parseColumnType($column['COLUMN_TYPE'] ?? '');
    $key = $column['COLUMN_KEY'] ?? '';
    $extra = $column['EXTRA'] ?? '';
    $panel = self::itemPanel('table-column-editor');
    $panel->setValue([
      'column-name' => $column['COLUMN_NAME'] ?? '',
      'column-parameter' => $type['parameter'],
      'column-primary' => $key === 'PRI',
      'column-unique' => $key === 'UNI',
      'column-not-null' => ($column['IS_NULLABLE'] ?? '') === 'NO',
      'column-auto-increment' => stripos($extra, 'auto_increment') !== false,
      'column-unsigned' => $type['unsigned'],
      'column-zerofill' => $type['zerofill'],
      'column-default' => $column['COLUMN_DEFAULT'] ?? '',
      'column-charset' => $column['CHARACTER_SET_NAME'] ?? '',
      'column-collation' => $column['COLLATION_NAME'] ?? '',
      'column-comment' => $column['COLUMN_COMMENT'] ?? ''
    ]);
    self::selectColumnTypeInList($type['type']);
    self::showItemPanel($panel, 'column-name');
  }

  /** Saves column editor values from the table editor panel or state. */
  public static function saveColumnEditor($panel) {
    $values = $panel->getValue();
    if (trim(self::textValue($values['column-name'] ?? '')) === '') {
      \SPTK\Elements\WarningPanel::forge('No field name', 'Please enter a field name before saving.');
      return;
    }
    if (!self::applyColumnEditorValues($panel)) {
      return;
    }
    self::$addingItem = false;
    self::closeItemEditor($panel);
  }

  /** Synchronizes column editor state inside the table editor. */
  public static function syncColumnEditor($element = null) {
    $panel = self::itemPanel('table-column-editor');
    if ($panel === false || !self::applyColumnEditorValues($panel)) {
      return;
    }
    \SPTK\Element::refresh();
  }

  /** Applies column editor values values to table editor controls. */
  private static function applyColumnEditorValues($panel) {
    if (self::$editingItem === false || !isset(self::$columns[self::$editingItem])) {
      return false;
    }
    $values = $panel->getValue();
    $primary = (bool) ($values['column-primary'] ?? false);
    $unique = (bool) ($values['column-unique'] ?? false);
    $autoIncrement = (bool) ($values['column-auto-increment'] ?? false);
    $oldName = self::$columns[self::$editingItem]['COLUMN_NAME'] ?? '';
    $newName = $values['column-name'] ?? '';
    self::$columns[self::$editingItem]['COLUMN_NAME'] = $values['column-name'] ?? '';
    self::$columns[self::$editingItem]['COLUMN_TYPE'] = self::buildColumnType(
      $values['column-type'] ?? '',
      $values['column-parameter'] ?? '',
      (bool) ($values['column-unsigned'] ?? false),
      (bool) ($values['column-zerofill'] ?? false)
    );
    self::$columns[self::$editingItem]['IS_NULLABLE'] = ($values['column-not-null'] ?? false) ? 'NO' : 'YES';
    self::$columns[self::$editingItem]['COLUMN_DEFAULT'] = $values['column-default'] ?? '';
    self::$columns[self::$editingItem]['COLUMN_KEY'] = $primary ? 'PRI' : ($unique ? 'UNI' : '');
    self::$columns[self::$editingItem]['EXTRA'] = $autoIncrement ? 'auto_increment' : '';
    self::$columns[self::$editingItem]['CHARACTER_SET_NAME'] = $values['column-charset'] ?? '';
    self::$columns[self::$editingItem]['COLLATION_NAME'] = $values['column-collation'] ?? '';
    self::$columns[self::$editingItem]['COLUMN_COMMENT'] = $values['column-comment'] ?? '';
    self::syncPrimaryIndexColumn($oldName, $newName, $primary);
    self::setColumns(self::$columns);
    self::setIndexes(self::$indexes);
    $list = self::listElement('table-editor-columns');
    if ($list !== false) {
      $list->moveCursor(self::$editingItem);
    }
    return true;
  }

  /** Applies index column list values to table editor state or controls. */
  private static function setIndexColumnList($selectedColumns) {
    $list = \SPTK\Element::byName('index-columns', self::itemPanel('table-index-editor'));
    if ($list === false) {
      return;
    }
    $list->clear();
    foreach (self::$columns as $column) {
      $name = $column['COLUMN_NAME'] ?? '';
      if ($name === '') {
        continue;
      }
      $item = new \SPTK\Elements\ListItem($list);
      $item->setValue($name);
      $item->setSelectable(true);
      $item->setFilterable(true);
    }
    $list->setSelectedValues(array_map(fn($column) => ltrim($column, '-'), $selectedColumns));
  }

  /** Coordinates add index work in the table editor. */
  public static function addIndex($panel = null) {
    if (!self::hasNamedColumns()) {
      self::warnNoColumnsFor('indices');
      return;
    }
    $name = "\0new-index-" . count(self::$indexes);
    self::$indexes[] = [
      'INDEX_NAME' => $name,
      'NON_UNIQUE' => 1,
      'SEQ_IN_INDEX' => 1,
      'COLUMN_NAME' => '',
      'COLLATION' => 'A',
      'CARDINALITY' => '',
      'INDEX_TYPE' => 'BTREE'
    ];
    self::$editingItem = $name;
    self::$addingItem = ['type' => 'index', 'name' => $name];
    $panel = self::itemPanel('table-index-editor');
    $panel->setValue([
      'index-name' => '',
      'index-type' => 'INDEX',
      'storage-type' => 'DEFAULT'
    ]);
    self::setIndexColumnList([]);
    self::showItemPanel($panel, 'index-name');
  }

  /** Opens the index editor panel or view in the table editor. */
  public static function openIndexEditor($item) {
    $name = $item->getValue();
    $parts = [];
    foreach (self::$indexes as $index) {
      if (($index['INDEX_NAME'] ?? '') === $name) {
        $column = $index['COLUMN_NAME'] ?? '';
        $parts[] = (($index['COLLATION'] ?? '') === 'D' ? '-' : '') . $column;
      }
    }
    if (empty($parts)) {
      return;
    }
    self::$editingItem = $name;
    self::$addingItem = false;
    $index = false;
    foreach (self::$indexes as $candidate) {
      if (($candidate['INDEX_NAME'] ?? '') === $name) {
        $index = $candidate;
        break;
      }
    }
    $panel = self::itemPanel('table-index-editor');
    $kind = ((int) ($index['NON_UNIQUE'] ?? 1)) === 0 ? 'UNIQUE' : 'INDEX';
    if ($name === 'PRIMARY') {
      $kind = 'PRIMARY';
    } elseif (in_array(strtoupper($index['INDEX_TYPE'] ?? ''), ['FULLTEXT', 'SPATIAL'])) {
      $kind = strtoupper($index['INDEX_TYPE']);
    }
    $panel->setValue([
      'index-name' => $name,
      'index-type' => $kind,
      'storage-type' => in_array(strtoupper($index['INDEX_TYPE'] ?? ''), ['BTREE', 'HASH', 'RTREE']) ? $index['INDEX_TYPE'] : 'DEFAULT'
    ]);
    self::setIndexColumnList($parts);
    self::showItemPanel($panel, 'index-name');
  }

  /** Saves index editor values from the table editor panel or state. */
  public static function saveIndexEditor($panel) {
    if (self::$editingItem === false) {
      return;
    }
    $values = $panel->getValue();
    $oldName = self::$editingItem;
    $newName = trim(self::textValue($values['index-name'] ?? ''));
    $kind = strtoupper($values['index-type'] ?? 'INDEX');
    if ($kind === 'PRIMARY') {
      $newName = 'PRIMARY';
    } elseif ($newName === '') {
      \SPTK\Elements\WarningPanel::forge('No index name', 'Please enter an index name before saving.');
      return;
    }
    $columns = $values['index-columns'] ?? [];
    if (!is_array($columns)) {
      $columns = array_map('trim', explode(',', $columns));
    }
    $columns = array_values(array_filter(array_map('trim', $columns), fn($column) => $column !== ''));
    if (empty($columns)) {
      \SPTK\Elements\WarningPanel::forge('No fields selected', 'Please select at least one field before saving.');
      return;
    }
    $template = false;
    foreach (self::$indexes as $index) {
      if (($index['INDEX_NAME'] ?? '') === $oldName) {
        $template = $index;
        break;
      }
    }
    if ($template === false) {
      return;
    }
    $newIndexes = [];
    $sequence = 1;
    foreach ($columns as $column) {
      $descending = strpos($column, '-') === 0;
      $index = $template;
      $index['INDEX_NAME'] = $newName;
      $storageType = strtoupper($values['storage-type'] ?? 'DEFAULT');
      $index['INDEX_TYPE'] = in_array($kind, ['PRIMARY', 'FULLTEXT', 'SPATIAL']) ? $kind : $storageType;
      $index['NON_UNIQUE'] = in_array($kind, ['PRIMARY', 'UNIQUE']) ? 0 : 1;
      $index['COLUMN_NAME'] = ltrim($column, '-');
      $index['COLLATION'] = $descending ? 'D' : 'A';
      $index['CARDINALITY'] = $values['index-cardinality'] ?? '';
      $index['SEQ_IN_INDEX'] = $sequence;
      $newIndexes[] = $index;
      $sequence++;
    }
    $indexes = [];
    $inserted = false;
    foreach (self::$indexes as $index) {
      if (($index['INDEX_NAME'] ?? '') === $oldName) {
        if (!$inserted) {
          array_push($indexes, ...$newIndexes);
          $inserted = true;
        }
        continue;
      }
      $indexes[] = $index;
    }
    self::$indexes = $indexes;
    self::syncColumnKeysFromIndexes();
    self::setColumns(self::$columns);
    self::setIndexes(self::$indexes);
    self::$addingItem = false;
    self::closeItemEditor($panel);
  }

}
