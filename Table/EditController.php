<?php

namespace MADB\Table;

class EditController {

  private const TAB_MAIN = 0;
  private const TAB_COLUMN = 1;
  private const TAB_INDEX = 2;
  private const TAB_FOREIGN_KEY = 3;
  private const TAB_TRIGGER = 4;

  private static $mode = 'edit';
  private static $schema = false;
  private static $table = false;
  private static $definition = false;
  private static $columns = [];
  private static $indexes = [];
  private static $foreignKeys = [];
  private static $triggers = [];
  private static $editingItem = false;
  private static $addingItem = false;
  private static $charsets = [];
  private static $collations = [];
  private static $engines = [];
  private static $characterOptionsConnection = false;
  private static $foreignKeySchemas = [];
  private static $foreignKeyTables = [];
  private static $foreignKeyTablesSchema = false;
  private static $foreignKeyPendingTable = '';
  private static $foreignKeyTargetFields = [];
  private static $foreignKeyTargetFieldsSchema = false;
  private static $foreignKeyTargetFieldsTable = false;
  private static $foreignKeyPendingTargetColumns = [];
  private static $foreignKeyOptionsConnection = false;

  private static function schemaLabel() {
    $labels = \MADB\Connection\MenuController::getMenuLabels();
    return strtolower($labels['schema']);
  }

  private static function tableLabel() {
    $labels = \MADB\Connection\MenuController::getMenuLabels();
    return strtolower($labels['table']);
  }

  private static function panel() {
    return \SPTK\Element::byName('table-editor');
  }

  private static function tabs() {
    return \SPTK\Element::byName('table-editor-tabs');
  }

  private static function addButton() {
    return \SPTK\Element::byName('table-editor-add', self::panel());
  }

  private static function deleteButton() {
    return \SPTK\Element::byName('table-editor-delete', self::panel());
  }

  private static function addSpace() {
    return \SPTK\Element::byName('table-editor-add-space', self::panel());
  }

  private static function deleteSpace() {
    return \SPTK\Element::byName('table-editor-delete-space', self::panel());
  }

  private static function listElement($name) {
    return \SPTK\Element::byName($name, self::panel());
  }

  private static function itemPanel($name) {
    return \SPTK\Element::byName($name);
  }

  private static function showItemPanel($panel, $inputName) {
    $panel->show();
    $panel->activateInput($inputName);
    \SPTK\Element::refresh();
  }

  private static function selectedSchema() {
    $schema = \MADB\Table\MenuController::getCurrentSchema();
    return $schema === '' ? false : $schema;
  }

  private static function selectedTable() {
    $table = \MADB\Table\MenuController::getCurrentTable();
    return $table === '' ? false : $table;
  }

  private static function currentConnection() {
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    return $connectionList->current;
  }

  private static function validateContext($requiresTable) {
    if (self::currentConnection() === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return false;
    }
    if (self::selectedSchema() === false) {
      \SPTK\Elements\WarningPanel::forge('No ' . self::schemaLabel() . ' selected!', 'Please select a ' . self::schemaLabel() . ' before preforming this operation.');
      return false;
    }
    if ($requiresTable && self::selectedTable() === false) {
      \SPTK\Elements\WarningPanel::forge('No ' . self::tableLabel() . ' selected!', 'Please select a ' . self::tableLabel() . ' before preforming this operation.');
      return false;
    }
    return true;
  }

  private static function resetLists($message = 'No metadata loaded') {
    foreach ([
      'table-editor-columns',
      'table-editor-indexes',
      'table-editor-foreign-keys',
      'table-editor-triggers'
    ] as $name) {
      self::setPlaceholder($name, $message === null ? self::emptyListMessage($name) : $message);
    }
  }

  private static function emptyListMessage($listName) {
    $messages = [
      'table-editor-columns' => 'No fields defined yet.',
      'table-editor-indexes' => 'No indices defined yet.',
      'table-editor-foreign-keys' => 'No foreign keys defined yet.',
      'table-editor-triggers' => 'No triggers defined yet.'
    ];
    return $messages[$listName] ?? 'No items defined yet.';
  }

  private static function setEmptyListPlaceholder($listName) {
    self::setPlaceholder($listName, self::emptyListMessage($listName));
  }

  private static function setPlaceholder($listName, $message) {
    $list = self::listElement($listName);
    if ($list === false) {
      return;
    }
    $list->clear();
    $item = new \SPTK\Elements\ListItem($list);
    $item->setText($message);
  }

  private static function makeItemKey($parts) {
    return implode("\t", array_map('strval', $parts));
  }

  private static function splitItemKey($key) {
    return explode("\t", (string) $key);
  }

  private static function addListItem($list, $text, $right = false) {
    $item = new \SPTK\Elements\ListItem($list);
    $item->setText($text);
    if ($right !== false && $right !== '') {
      $item->setRight($right);
    }
  }

  private static function addCell($item, $class, $text) {
    $cell = new \SPTK\Element($item, null, $class, 'Cell');
    $cell->addText(self::textValue($text));
  }

  private static function hasNamedColumns() {
    return self::firstNamedColumn() !== '';
  }

  private static function firstNamedColumn() {
    foreach (self::$columns as $column) {
      $name = trim((string) ($column['COLUMN_NAME'] ?? ''));
      if ($name !== '') {
        return $name;
      }
    }
    return '';
  }

  private static function warnNoColumnsFor($itemType) {
    \SPTK\Elements\WarningPanel::forge(
      'No fields defined',
      'Please add at least one field before adding ' . $itemType . '.'
    );
  }

  private static function isPrimaryIndexRow($index) {
    return
      ($index['INDEX_NAME'] ?? '') === 'PRIMARY' ||
      strtoupper(self::normalizeValue($index['INDEX_TYPE'] ?? '')) === 'PRIMARY';
  }

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

  private static function quoteIdentifier($identifier) {
    return '`' . str_replace('`', '``', $identifier) . '`';
  }

  private static function quoteQualifiedTable($schema, $table) {
    return self::quoteIdentifier($schema) . '.' . self::quoteIdentifier($table);
  }

  private static function quoteString($value) {
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
  }

  private static function textValue($value) {
    if (is_array($value)) {
      return implode("\n", $value);
    }
    return (string) $value;
  }

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

  private static function currentTableName() {
    $table = trim(self::textValue(self::namedValue('table-name')));
    if ($table !== '') {
      return $table;
    }
    return self::$table === false ? '' : self::$table;
  }

  private static function setSelectOptions($name, $options, $selected = null) {
    $element = \SPTK\Element::byName($name);
    if ($element !== false) {
      $element->setOptions($options);
      if ($selected !== null) {
        $element->setValue($selected);
      }
    }
  }

  private static function applyCharacterOptions() {
    $charsets = array_merge([''], self::$charsets);
    $collations = array_merge([''], self::$collations);
    $engines = array_merge([''], self::$engines);
    self::setSelectOptions('table-charset', $charsets);
    self::setSelectOptions('column-charset', $charsets);
    self::setSelectOptions('table-collation', $collations);
    self::setSelectOptions('column-collation', $collations);
    self::setSelectOptions('table-engine', $engines);
  }

  private static function loadCharacterOptions() {
    $connection = self::currentConnection();
    if ($connection === false) {
      return;
    }
    if (self::$characterOptionsConnection !== $connection['name']) {
      self::$charsets = [];
      self::$collations = [];
      self::$engines = [];
      self::$characterOptionsConnection = $connection['name'];
    }
    self::applyCharacterOptions();
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'characterSetsAndCollations',
      'callback' => ['\MADB\Table\EditController', 'setCharacterOptions'],
      'cache' => 'CharacterSetsAndCollations'
    ]);
  }

  private static function tableNames($tables) {
    $names = [];
    foreach ($tables as $table) {
      if (is_array($table)) {
        $name = $table['name'] ?? '';
      } else {
        $name = $table;
      }
      if ($name !== '') {
        $names[] = $name;
      }
    }
    return $names;
  }

  private static function columnNames($columns) {
    $names = [];
    foreach ($columns as $column) {
      if (is_array($column)) {
        $name = $column['COLUMN_NAME'] ?? '';
      } else {
        $name = $column;
      }
      if ($name !== '') {
        $names[] = $name;
      }
    }
    return $names;
  }

  private static function resetForeignKeyOptionsForConnection($connection) {
    if ($connection === false) {
      return;
    }
    if (self::$foreignKeyOptionsConnection !== $connection['name']) {
      self::$foreignKeySchemas = [];
      self::$foreignKeyTables = [];
      self::$foreignKeyTablesSchema = false;
      self::$foreignKeyPendingTable = '';
      self::$foreignKeyTargetFields = [];
      self::$foreignKeyTargetFieldsSchema = false;
      self::$foreignKeyTargetFieldsTable = false;
      self::$foreignKeyPendingTargetColumns = [];
      self::$foreignKeyOptionsConnection = $connection['name'];
    }
  }

  private static function applyForeignKeySchemaOptions($selectedSchema) {
    self::setSelectOptions('foreign-key-target-schema', self::$foreignKeySchemas, $selectedSchema);
  }

  private static function applyForeignKeyTableOptions($selectedTable) {
    self::setSelectOptions('foreign-key-target-table', self::$foreignKeyTables, $selectedTable);
  }

  private static function loadForeignKeySchemas($selectedSchema) {
    $connection = self::currentConnection();
    if ($connection === false) {
      return;
    }
    self::resetForeignKeyOptionsForConnection($connection);
    self::applyForeignKeySchemaOptions($selectedSchema);
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'schemaList',
      'callback' => ['\MADB\Table\EditController', 'setForeignKeySchemas'],
      'targetSchema' => $selectedSchema,
      'cache' => 'SchemaList'
    ]);
  }

  private static function loadForeignKeyTables($schema, $selectedTable = '') {
    $connection = self::currentConnection();
    if ($connection === false || $schema === '') {
      self::$foreignKeyTables = [];
      self::$foreignKeyTablesSchema = false;
      self::$foreignKeyPendingTable = '';
      self::applyForeignKeyTableOptions('');
      return;
    }
    self::resetForeignKeyOptionsForConnection($connection);
    if (self::$foreignKeyTablesSchema !== $schema) {
      self::$foreignKeyTables = [];
    }
    self::$foreignKeyTablesSchema = $schema;
    self::$foreignKeyPendingTable = $selectedTable;
    if (!empty(self::$foreignKeyTables)) {
      self::applyForeignKeyTableOptions($selectedTable);
    } else {
      self::setSelectOptions('foreign-key-target-table', [], $selectedTable);
    }
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'tableList',
      'arguments' => [$schema],
      'callback' => ['\MADB\Table\EditController', 'setForeignKeyTables'],
      'schema' => $schema,
      'targetSchema' => $schema,
      'cache' => 'TableList:' . $schema
    ]);
  }

  private static function setForeignKeyColumnList($name, $columns, $selectedColumns) {
    $list = \SPTK\Element::byName($name, self::itemPanel('table-foreign-key-editor'));
    if ($list === false) {
      return;
    }
    $list->clear();
    foreach ($columns as $column) {
      if ($column === '') {
        continue;
      }
      $item = new \SPTK\Elements\ListItem($list);
      $item->setValue($column);
      $item->setSelectable(true);
      $item->setFilterable(true);
    }
    $list->setSelectedValues($selectedColumns);
  }

  private static function setForeignKeySourceColumnList($selectedColumns) {
    self::setForeignKeyColumnList('foreign-key-column', self::columnNames(self::$columns), $selectedColumns);
  }

  private static function setForeignKeyTargetColumnList($selectedColumns) {
    self::setForeignKeyColumnList('foreign-key-target-column', self::$foreignKeyTargetFields, $selectedColumns);
  }

  private static function loadForeignKeyTargetFields($schema, $table, $selectedColumns = []) {
    $connection = self::currentConnection();
    if ($connection === false || $schema === '' || $table === '') {
      self::$foreignKeyTargetFields = [];
      self::$foreignKeyTargetFieldsSchema = false;
      self::$foreignKeyTargetFieldsTable = false;
      self::$foreignKeyPendingTargetColumns = [];
      self::setForeignKeyTargetColumnList([]);
      return;
    }
    self::resetForeignKeyOptionsForConnection($connection);
    if (self::$foreignKeyTargetFieldsSchema !== $schema || self::$foreignKeyTargetFieldsTable !== $table) {
      self::$foreignKeyTargetFields = [];
    }
    self::$foreignKeyTargetFieldsSchema = $schema;
    self::$foreignKeyTargetFieldsTable = $table;
    self::$foreignKeyPendingTargetColumns = $selectedColumns;
    self::setForeignKeyTargetColumnList($selectedColumns);
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'tableFields',
      'arguments' => [$schema, $table],
      'callback' => ['\MADB\Table\EditController', 'setForeignKeyTargetFields'],
      'schema' => $schema,
      'table' => $table,
      'cache' => 'TableFields:' . $schema . ':' . $table
    ]);
  }

  private static function tableOptionClauses($engine, $charset, $collation, $comment) {
    $clauses = [];
    if ($engine !== '') {
      $clauses[] = 'ENGINE = ' . $engine;
    }
    if ($charset !== '') {
      $clauses[] = 'DEFAULT CHARACTER SET ' . $charset;
    }
    if ($collation !== '') {
      $clauses[] = 'COLLATE ' . $collation;
    }
    if ($comment !== '') {
      $clauses[] = 'COMMENT = ' . self::quoteString($comment);
    }
    return $clauses;
  }

  private static function queryName($prefix, $table) {
    return $prefix . ' ' . self::$schema . '.' . $table;
  }

  private static function normalizeValue($value) {
    return $value === null ? '' : (string) $value;
  }

  private static function defaultClause($value) {
    if ($value === null || $value === '') {
      return '';
    }
    $upper = strtoupper((string) $value);
    if (in_array($upper, ['NULL', 'CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP()'])) {
      return ' DEFAULT ' . $upper;
    }
    return ' DEFAULT ' . self::quoteString((string) $value);
  }

  private static function columnDefinition($column) {
    $name = self::normalizeValue($column['COLUMN_NAME'] ?? '');
    $type = self::normalizeValue($column['COLUMN_TYPE'] ?? '');
    $sql = self::quoteIdentifier($name) . ' ' . $type;
    $charset = self::normalizeValue($column['CHARACTER_SET_NAME'] ?? '');
    $collation = self::normalizeValue($column['COLLATION_NAME'] ?? '');
    $comment = self::normalizeValue($column['COLUMN_COMMENT'] ?? '');
    if ($charset !== '') {
      $sql .= ' CHARACTER SET ' . $charset;
    }
    if ($collation !== '') {
      $sql .= ' COLLATE ' . $collation;
    }
    if (($column['IS_NULLABLE'] ?? '') === 'NO') {
      $sql .= ' NOT NULL';
    } else {
      $sql .= ' NULL';
    }
    $sql .= self::defaultClause($column['COLUMN_DEFAULT'] ?? null);
    if (stripos(self::normalizeValue($column['EXTRA'] ?? ''), 'auto_increment') !== false) {
      $sql .= ' AUTO_INCREMENT';
    }
    if ($comment !== '') {
      $sql .= ' COMMENT ' . self::quoteString($comment);
    }
    return $sql;
  }

  private static function columnPositionClause($previousColumn) {
    return $previousColumn === false ? ' FIRST' : ' AFTER ' . self::quoteIdentifier($previousColumn);
  }

  private static function previousNamedColumn($columns, $index) {
    for ($i = $index - 1; $i >= 0; $i--) {
      $name = self::normalizeValue($columns[$i]['COLUMN_NAME'] ?? '');
      if ($name !== '') {
        return $name;
      }
    }
    return false;
  }

  private static function normalizeColumn($column) {
    return [
      'name' => self::normalizeValue($column['COLUMN_NAME'] ?? ''),
      'type' => strtolower(self::normalizeValue($column['COLUMN_TYPE'] ?? '')),
      'nullable' => self::normalizeValue($column['IS_NULLABLE'] ?? ''),
      'default' => self::normalizeValue($column['COLUMN_DEFAULT'] ?? ''),
      'extra' => strtolower(self::normalizeValue($column['EXTRA'] ?? '')),
      'charset' => self::normalizeValue($column['CHARACTER_SET_NAME'] ?? ''),
      'collation' => self::normalizeValue($column['COLLATION_NAME'] ?? ''),
      'comment' => self::normalizeValue($column['COLUMN_COMMENT'] ?? '')
    ];
  }

  private static function groupIndexes($indexes) {
    $groups = [];
    foreach ($indexes as $index) {
      $name = self::normalizeValue($index['INDEX_NAME'] ?? '');
      if ($name === '') {
        continue;
      }
      if (!isset($groups[$name])) {
        $groups[$name] = [
          'name' => $name,
          'nonUnique' => (int) ($index['NON_UNIQUE'] ?? ($name === 'PRIMARY' ? 0 : 1)),
          'type' => strtoupper(self::normalizeValue($index['INDEX_TYPE'] ?? '')),
          'columns' => []
        ];
      }
      $column = self::normalizeValue($index['COLUMN_NAME'] ?? '');
      if ($column !== '') {
        $groups[$name]['columns'][] = [
          'name' => $column,
          'collation' => self::normalizeValue($index['COLLATION'] ?? 'A'),
          'sequence' => (int) ($index['SEQ_IN_INDEX'] ?? count($groups[$name]['columns']) + 1)
        ];
      }
    }
    foreach ($groups as &$group) {
      usort($group['columns'], fn($a, $b) => $a['sequence'] <=> $b['sequence']);
    }
    unset($group);
    return $groups;
  }

  private static function normalizeIndex($index) {
    $type = strtoupper($index['type']);
    if ($type === 'DEFAULT' || $type === '') {
      $type = 'BTREE';
    }
    return [
      'name' => $index['name'],
      'nonUnique' => (int) $index['nonUnique'],
      'type' => $type,
      'columns' => array_map(fn($column) => [
        'name' => $column['name'],
        'collation' => $column['collation'] === 'D' ? 'D' : 'A'
      ], $index['columns'])
    ];
  }

  private static function indexDefinition($index) {
    $type = strtoupper($index['type']);
    $name = $index['name'];
    if ($name === 'PRIMARY' || $type === 'PRIMARY') {
      $sql = 'PRIMARY KEY';
    } elseif ($type === 'FULLTEXT') {
      $sql = 'FULLTEXT KEY ' . self::quoteIdentifier($name);
    } elseif ($type === 'SPATIAL') {
      $sql = 'SPATIAL KEY ' . self::quoteIdentifier($name);
    } elseif ((int) $index['nonUnique'] === 0) {
      $sql = 'UNIQUE KEY ' . self::quoteIdentifier($name);
    } else {
      $sql = 'KEY ' . self::quoteIdentifier($name);
    }
    $columns = [];
    foreach ($index['columns'] as $column) {
      $columns[] = self::quoteIdentifier($column['name']) . (($column['collation'] ?? '') === 'D' ? ' DESC' : '');
    }
    return $sql . ' (' . implode(', ', $columns) . ')';
  }

  private static function groupForeignKeys($foreignKeys) {
    $groups = [];
    foreach ($foreignKeys as $foreignKey) {
      $name = self::normalizeValue($foreignKey['CONSTRAINT_NAME'] ?? '');
      if ($name === '') {
        continue;
      }
      if (!isset($groups[$name])) {
        $groups[$name] = [
          'name' => $name,
          'targetSchema' => self::normalizeValue($foreignKey['REFERENCED_TABLE_SCHEMA'] ?? ''),
          'targetTable' => self::normalizeValue($foreignKey['REFERENCED_TABLE_NAME'] ?? ''),
          'updateRule' => self::normalizeValue($foreignKey['UPDATE_RULE'] ?? ''),
          'deleteRule' => self::normalizeValue($foreignKey['DELETE_RULE'] ?? ''),
          'columns' => []
        ];
      }
      $groups[$name]['columns'][] = [
        'source' => self::normalizeValue($foreignKey['COLUMN_NAME'] ?? ''),
        'target' => self::normalizeValue($foreignKey['REFERENCED_COLUMN_NAME'] ?? ''),
        'sequence' => (int) ($foreignKey['ORDINAL_POSITION'] ?? count($groups[$name]['columns']) + 1)
      ];
    }
    foreach ($groups as &$group) {
      usort($group['columns'], fn($a, $b) => $a['sequence'] <=> $b['sequence']);
    }
    unset($group);
    return $groups;
  }

  private static function normalizeForeignKey($foreignKey) {
    return [
      'name' => $foreignKey['name'],
      'targetSchema' => $foreignKey['targetSchema'],
      'targetTable' => $foreignKey['targetTable'],
      'updateRule' => $foreignKey['updateRule'],
      'deleteRule' => $foreignKey['deleteRule'],
      'columns' => array_map(fn($column) => [
        'source' => $column['source'],
        'target' => $column['target']
      ], $foreignKey['columns'])
    ];
  }

  private static function foreignKeyDefinition($foreignKey) {
    $sourceColumns = [];
    $targetColumns = [];
    foreach ($foreignKey['columns'] as $column) {
      $sourceColumns[] = self::quoteIdentifier($column['source']);
      $targetColumns[] = self::quoteIdentifier($column['target']);
    }
    $sql =
      'CONSTRAINT ' . self::quoteIdentifier($foreignKey['name']) .
      ' FOREIGN KEY (' . implode(', ', $sourceColumns) . ')' .
      ' REFERENCES ' . self::quoteQualifiedTable($foreignKey['targetSchema'], $foreignKey['targetTable']) .
      ' (' . implode(', ', $targetColumns) . ')';
    if ($foreignKey['updateRule'] !== '') {
      $sql .= ' ON UPDATE ' . $foreignKey['updateRule'];
    }
    if ($foreignKey['deleteRule'] !== '') {
      $sql .= ' ON DELETE ' . $foreignKey['deleteRule'];
    }
    return $sql;
  }

  private static function normalizeTrigger($trigger) {
    return [
      'name' => self::normalizeValue($trigger['TRIGGER_NAME'] ?? ''),
      'timing' => self::normalizeValue($trigger['ACTION_TIMING'] ?? ''),
      'event' => self::normalizeValue($trigger['EVENT_MANIPULATION'] ?? ''),
      'statement' => trim(self::textValue($trigger['ACTION_STATEMENT'] ?? ''))
    ];
  }

  private static function triggerCreateSql($table, $trigger) {
    $trigger = self::normalizeTrigger($trigger);
    return
      'CREATE TRIGGER ' . self::quoteIdentifier(self::$schema) . '.' . self::quoteIdentifier($trigger['name']) . "\n" .
      $trigger['timing'] . ' ' . $trigger['event'] . ' ON ' . self::quoteQualifiedTable(self::$schema, $table) . "\n" .
      "FOR EACH ROW\n" .
      $trigger['statement'] . ';';
  }

  private static function parseColumnType($columnType) {
    $columnType = trim((string) $columnType);
    $unsigned = stripos($columnType, ' unsigned') !== false;
    $zerofill = stripos($columnType, ' zerofill') !== false;
    $clean = trim(str_ireplace([' unsigned', ' zerofill'], '', $columnType));
    if (preg_match('/^([a-z0-9]+)(?:\((.*)\))?$/i', $clean, $matches)) {
      return [
        'type' => strtoupper($matches[1]),
        'parameter' => $matches[2] ?? '',
        'unsigned' => $unsigned,
        'zerofill' => $zerofill
      ];
    }
    return [
      'type' => strtoupper($clean),
      'parameter' => '',
      'unsigned' => $unsigned,
      'zerofill' => $zerofill
    ];
  }

  private static function buildColumnType($type, $parameter, $unsigned, $zerofill) {
    $type = strtoupper(trim((string) $type));
    $parameter = trim((string) $parameter);
    $columnType = $type;
    if ($parameter !== '') {
      $columnType .= '(' . $parameter . ')';
    }
    if ($unsigned) {
      $columnType .= ' unsigned';
    }
    if ($zerofill) {
      $columnType .= ' zerofill';
    }
    return $columnType;
  }

  private static function selectColumnTypeInList($type) {
    $list = \SPTK\Element::byName('column-type', self::itemPanel('table-column-editor'));
    if ($list === false) {
      return;
    }
    foreach ($list->getDescendants() as $index => $item) {
      if ($item->getValue() === $type) {
        $list->moveCursor($index);
        return;
      }
    }
  }

  private static function setTitle($title) {
    $panelTitle = \SPTK\Element::firstByType('PanelTitle', self::panel());
    if ($panelTitle !== false) {
      $panelTitle->setText($title);
    }
  }

  private static function currentTabInputName($contentName = false) {
    if ($contentName === false) {
      $tabs = self::tabs();
      $contentName = $tabs === false ? false : $tabs->getCurrentTabContentName();
    }
    $inputs = [
      'table-editor-main' => 'table-name',
      'table-editor-column' => 'table-editor-columns',
      'table-editor-index' => 'table-editor-indexes',
      'table-editor-foreign-key' => 'table-editor-foreign-keys',
      'table-editor-trigger' => 'table-editor-triggers'
    ];
    return $inputs[$contentName] ?? false;
  }

  private static function activateCurrentTabInput($contentName = false) {
    $panel = self::panel();
    if ($panel === false || !$panel->isDisplayed()) {
      return;
    }
    $inputName = self::currentTabInputName($contentName);
    if ($inputName !== false) {
      $panel->activateInput($inputName);
    }
  }

  private static function open($tab, $requiresTable, $mode = null) {
    if (!self::validateContext($requiresTable)) {
      return;
    }
    self::$mode = $mode ?? ($requiresTable ? 'edit' : 'create');
    self::$schema = self::selectedSchema();
    self::$table = $requiresTable ? self::selectedTable() : false;
    self::$definition = false;
    self::$columns = [];
    self::$indexes = [];
    self::$foreignKeys = [];
    self::$triggers = [];
    self::$editingItem = false;
    self::$addingItem = false;
    self::loadCharacterOptions();
    $panel = self::panel();
    $tabs = self::tabs();
    if ($panel === false || $tabs === false) {
      return;
    }
    $tabs->selectTab($tab);
    self::updateAddButton($tabs);
    if (self::$mode === 'create') {
      self::setTitle('Create table in ' . self::$schema);
      $panel->setValue([
        'table-name' => '',
        'table-charset' => '',
        'table-collation' => '',
        'table-engine' => '',
        'table-comment' => ''
      ]);
      self::resetLists(null);
      $panel->show();
      $panel->activateInput('table-name');
      \SPTK\Element::refresh();
      return;
    }

    $title = self::$mode === 'modify' ? 'Modify ' : 'Edit ';
    self::setTitle($title . self::$schema . '.' . self::$table);
    $panel->setValue([
      'table-name' => self::$table,
      'table-charset' => '',
      'table-collation' => '',
      'table-engine' => '',
      'table-comment' => ''
    ]);
    self::resetLists('Loading...');
    $panel->show();
    self::activateCurrentTabInput();
    \SPTK\Element::refresh();
    self::loadDefinition();
  }

  private static function loadDefinition() {
    $connection = self::currentConnection();
    if ($connection === false || self::$schema === false || self::$table === false) {
      return;
    }
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'tableDefinition',
      'arguments' => [self::$schema, self::$table],
      'callback' => ['\MADB\Table\EditController', 'setDefinition'],
      'schema' => self::$schema,
      'table' => self::$table,
      'cache' => 'TableDefinition:' . self::$schema . ':' . self::$table
    ]);
  }

  public static function openCreate() {
    self::open(self::TAB_MAIN, false);
  }

  public static function openModify() {
    self::open(self::TAB_MAIN, true, 'modify');
  }

  public static function openColumns() {
    self::open(self::TAB_COLUMN, true);
  }

  public static function openIndexes() {
    self::open(self::TAB_INDEX, true);
  }

  public static function openForeignKeys() {
    self::open(self::TAB_FOREIGN_KEY, true);
  }

  public static function openTriggers() {
    self::open(self::TAB_TRIGGER, true);
  }

  public static function updateAddButton($tabs = null) {
    if ($tabs === null || $tabs === false) {
      $tabs = self::tabs();
    }
    $contentName = $tabs === false ? false : $tabs->getCurrentTabContentName();
    $inputName = self::currentTabInputName($contentName);
    $button = self::addButton();
    if ($button === false) {
      return $inputName;
    }
    $space = self::addSpace();
    $deleteButton = self::deleteButton();
    $deleteSpace = self::deleteSpace();
    if (in_array($contentName, [
      'table-editor-column',
      'table-editor-index',
      'table-editor-foreign-key',
      'table-editor-trigger'
    ])) {
      $button->show();
      if ($deleteButton !== false) {
        $deleteButton->show();
      }
      if ($space !== false) {
        $space->show();
      }
      if ($deleteSpace !== false) {
        $deleteSpace->show();
      }
    } else {
      $button->hide();
      if ($deleteButton !== false) {
        $deleteButton->hide();
      }
      if ($space !== false) {
        $space->hide();
      }
      if ($deleteSpace !== false) {
        $deleteSpace->hide();
      }
    }
    $panel = self::panel();
    if ($panel !== false && $panel->isDisplayed()) {
      $panel->refreshInputList($inputName);
      \SPTK\Element::refresh();
    }
    return $inputName;
  }

  public static function setDefinition($response) {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not inspect table', $response['result']);
      return;
    }
    $definition = $response['result'];
    self::$definition = $definition;
    self::$columns = $definition['columns'] ?? [];
    self::$indexes = $definition['indexes'] ?? [];
    self::$foreignKeys = $definition['foreignKeys'] ?? [];
    self::$triggers = $definition['triggers'] ?? [];
    self::syncColumnKeysFromIndexes();
    $table = $definition['table'] ?? [];
    self::panel()->setValue([
      'table-name' => $table['name'] ?? ($response['table'] ?? ''),
      'table-charset' => $table['charset'] ?? '',
      'table-collation' => $table['collation'] ?? '',
      'table-engine' => $table['engine'] ?? '',
      'table-comment' => $table['comment'] ?? ''
    ]);
    self::setColumns(self::$columns);
    self::setIndexes(self::$indexes);
    self::setForeignKeys(self::$foreignKeys);
    self::setTriggers(self::$triggers);
    \SPTK\Element::refresh();
  }

  public static function setCharacterOptions($response) {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not load character sets', $response['result']);
      return;
    }
    $result = $response['result'];
    self::$charsets = $result['charsets'] ?? [];
    self::$collations = $result['collations'] ?? [];
    self::$engines = $result['engines'] ?? [];
    self::$characterOptionsConnection = $response['connection']['name'] ?? false;
    self::applyCharacterOptions();
    \SPTK\Element::refresh();
  }

  public static function setForeignKeySchemas($response) {
    if ($response['status'] !== 'OK') {
      return;
    }
    $connection = self::currentConnection();
    $responseConnection = $response['connection']['name'] ?? false;
    if ($connection !== false && $responseConnection !== false && $responseConnection !== $connection['name']) {
      return;
    }
    self::$foreignKeySchemas = array_values(array_filter(array_map('strval', $response['result']), fn($schema) => $schema !== ''));
    self::$foreignKeyOptionsConnection = $responseConnection ?: self::$foreignKeyOptionsConnection;
    $selectedSchema = $response['targetSchema'] ?? '';
    $schemaList = \SPTK\Element::byName('foreign-key-target-schema', self::itemPanel('table-foreign-key-editor'));
    if ($selectedSchema === '' && $schemaList !== false) {
      $selectedSchema = (string) $schemaList->getValue();
    }
    self::applyForeignKeySchemaOptions($selectedSchema);
    \SPTK\Element::refresh();
  }

  public static function setForeignKeyTables($response) {
    if ($response['status'] !== 'OK') {
      return;
    }
    $schema = $response['targetSchema'] ?? ($response['schema'] ?? self::$foreignKeyTablesSchema);
    if ($schema !== self::$foreignKeyTablesSchema) {
      return;
    }
    $connection = self::currentConnection();
    $responseConnection = $response['connection']['name'] ?? false;
    if ($connection !== false && $responseConnection !== false && $responseConnection !== $connection['name']) {
      return;
    }
    self::$foreignKeyTables = self::tableNames($response['result']);
    self::$foreignKeyOptionsConnection = $responseConnection ?: self::$foreignKeyOptionsConnection;
    self::applyForeignKeyTableOptions(self::$foreignKeyPendingTable);
    \SPTK\Element::refresh();
  }

  public static function setForeignKeyTargetFields($response) {
    if ($response['status'] !== 'OK') {
      return;
    }
    $schema = $response['schema'] ?? self::$foreignKeyTargetFieldsSchema;
    $table = $response['table'] ?? self::$foreignKeyTargetFieldsTable;
    if ($schema !== self::$foreignKeyTargetFieldsSchema || $table !== self::$foreignKeyTargetFieldsTable) {
      return;
    }
    $connection = self::currentConnection();
    $responseConnection = $response['connection']['name'] ?? false;
    if ($connection !== false && $responseConnection !== false && $responseConnection !== $connection['name']) {
      return;
    }
    self::$foreignKeyTargetFields = self::columnNames($response['result']);
    self::$foreignKeyOptionsConnection = $responseConnection ?: self::$foreignKeyOptionsConnection;
    self::setForeignKeyTargetColumnList(self::$foreignKeyPendingTargetColumns);
    \SPTK\Element::refresh();
  }

  public static function changeForeignKeyTargetSchema($list) {
    $schema = (string) $list->getValue();
    self::loadForeignKeyTables($schema);
    self::loadForeignKeyTargetFields('', '');
    \SPTK\Element::refresh();
  }

  public static function changeForeignKeyTargetTable($list) {
    $schemaElement = \SPTK\Element::byName('foreign-key-target-schema', self::itemPanel('table-foreign-key-editor'));
    $schema = $schemaElement === false ? '' : (string) $schemaElement->getValue();
    $table = (string) $list->getValue();
    self::loadForeignKeyTargetFields($schema, $table);
    \SPTK\Element::refresh();
  }

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

  private static function findColumn($name) {
    foreach (self::$columns as $index => $column) {
      if (($column['COLUMN_NAME'] ?? '') === $name) {
        return [$index, $column];
      }
    }
    return [false, false];
  }

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

  private static function findTrigger($name) {
    foreach (self::$triggers as $index => $trigger) {
      if (($trigger['TRIGGER_NAME'] ?? '') === $name) {
        return [$index, $trigger];
      }
    }
    return [false, false];
  }

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

  private static function selectedListValue($listName) {
    $list = self::listElement($listName);
    return $list === false ? false : $list->getValue();
  }

  private static function warnNoItemSelected($itemType) {
    \SPTK\Elements\WarningPanel::forge('No ' . $itemType . ' selected', 'Please select a ' . $itemType . ' before deleting.');
  }

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

  public static function syncColumnEditor($element = null) {
    $panel = self::itemPanel('table-column-editor');
    if ($panel === false || !self::applyColumnEditorValues($panel)) {
      return;
    }
    \SPTK\Element::refresh();
  }

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

  public static function generate($panel = null) {
    $connection = self::currentConnection();
    if ($connection === false || self::$schema === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection and ' . self::schemaLabel() . ' before saving.');
      return;
    }
    $values = self::panel()->getValue();
    self::syncColumnOrderFromList();
    $table = trim(self::textValue(self::namedValue('table-name', $values)));
    if ($table === '') {
      \SPTK\Elements\WarningPanel::forge('No ' . self::tableLabel() . ' name!', 'Please enter a ' . self::tableLabel() . ' name before saving.');
      return;
    }
    if (!self::hasNamedColumns()) {
      \SPTK\Elements\WarningPanel::forge('No fields defined', 'Please add at least one field before saving.');
      return;
    }
    $charset = trim(self::textValue(self::namedValue('table-charset', $values)));
    $collation = trim(self::textValue(self::namedValue('table-collation', $values)));
    $engine = trim(self::textValue(self::namedValue('table-engine', $values)));
    $comment = trim(self::textValue(self::namedValue('table-comment', $values)));
    if (self::$mode === 'create') {
      $sql = self::generateCreateSql($table, $engine, $charset, $collation, $comment);
      \MADB\Main\ScreenController::addQuery(self::queryName('CREATE', $table), $sql, $connection['name'], self::$schema, $table);
      return;
    }
    $sql = self::generateAlterSql($table, $engine, $charset, $collation, $comment);
    if ($sql === false) {
      return;
    }
    if ($sql === '') {
      \SPTK\Elements\WarningPanel::forge('No changes', 'No table changes were detected.');
      return;
    }
    \MADB\Main\ScreenController::addQuery(self::queryName('ALTER', self::$table), $sql, $connection['name'], self::$schema, $table);
  }

  private static function generateCreateSql($table, $engine, $charset, $collation, $comment) {
    $definitions = [];
    foreach (self::$columns as $column) {
      if (self::normalizeValue($column['COLUMN_NAME'] ?? '') !== '') {
        $definitions[] = self::columnDefinition($column);
      }
    }
    foreach (self::groupIndexes(self::$indexes) as $index) {
      if (!empty($index['columns'])) {
        $definitions[] = self::indexDefinition($index);
      }
    }
    foreach (self::groupForeignKeys(self::$foreignKeys) as $foreignKey) {
      if (!empty($foreignKey['columns'])) {
        $definitions[] = self::foreignKeyDefinition($foreignKey);
      }
    }
    if (empty($definitions)) {
      $definitions[] = '[COLUMNS]';
    }
    $sql = 'CREATE TABLE ' . self::quoteQualifiedTable(self::$schema, $table) . " (\n  " . implode(",\n  ", $definitions) . "\n)";
    $clauses = self::tableOptionClauses($engine, $charset, $collation, $comment);
    if (!empty($clauses)) {
      $sql .= "\n" . implode("\n", $clauses);
    }
    $statements = [$sql . ';'];
    foreach (self::$triggers as $trigger) {
      if (self::normalizeValue($trigger['TRIGGER_NAME'] ?? '') !== '') {
        $statements[] = self::triggerCreateSql($table, $trigger);
      }
    }
    return implode("\n\n", $statements);
  }

  private static function generateAlterSql($table, $engine, $charset, $collation, $comment) {
    $original = self::$definition['table'] ?? [];
    if (empty($original)) {
      \SPTK\Elements\WarningPanel::forge('Table metadata not loaded', 'Please wait until the table metadata has loaded before saving.');
      return false;
    }
    $currentName = $original['name'] ?? self::$table;
    $currentEngine = $original['engine'] ?? '';
    $currentCharset = $original['charset'] ?? '';
    $currentCollation = $original['collation'] ?? '';
    $currentComment = $original['comment'] ?? '';
    $statements = [];
    if ($table !== $currentName) {
      $statements[] =
        'ALTER TABLE ' . self::quoteQualifiedTable(self::$schema, $currentName) .
        ' RENAME TO ' . self::quoteQualifiedTable(self::$schema, $table) . ';';
    }
    $clauses = [];
    if ($engine !== '' && $engine !== $currentEngine) {
      $clauses[] = 'ENGINE = ' . $engine;
    }
    if ($charset !== '' && $charset !== $currentCharset) {
      $clauses[] = 'DEFAULT CHARACTER SET ' . $charset;
    }
    if ($collation !== '' && $collation !== $currentCollation) {
      $clauses[] = 'COLLATE ' . $collation;
    }
    if ($comment !== $currentComment) {
      $clauses[] = 'COMMENT = ' . self::quoteString($comment);
    }
    if (!empty($clauses)) {
      $targetName = $table !== $currentName ? $table : $currentName;
      $statements[] =
        'ALTER TABLE ' . self::quoteQualifiedTable(self::$schema, $targetName) . "\n  " .
        implode(",\n  ", $clauses) . ';';
    }
    $targetName = $table !== $currentName ? $table : $currentName;
    $foreignKeyClauses = self::generateForeignKeyAlterClauses();
    foreach ($foreignKeyClauses['drop'] as $clause) {
      $statements[] =
        'ALTER TABLE ' . self::quoteQualifiedTable(self::$schema, $targetName) . "\n  " .
        $clause . ';';
    }
    foreach (self::generateColumnAlterClauses() as $clause) {
      $statements[] =
        'ALTER TABLE ' . self::quoteQualifiedTable(self::$schema, $targetName) . "\n  " .
        $clause . ';';
    }
    foreach (self::generateIndexAlterClauses() as $clause) {
      $statements[] =
        'ALTER TABLE ' . self::quoteQualifiedTable(self::$schema, $targetName) . "\n  " .
        $clause . ';';
    }
    foreach ($foreignKeyClauses['add'] as $clause) {
      $statements[] =
        'ALTER TABLE ' . self::quoteQualifiedTable(self::$schema, $targetName) . "\n  " .
        $clause . ';';
    }
    foreach (self::generateTriggerStatements($currentName, $targetName) as $statement) {
      $statements[] = $statement;
    }
    return implode("\n\n", $statements);
  }

  private static function generateColumnAlterClauses() {
    $clauses = [];
    $originalColumns = self::$definition['columns'] ?? [];
    $matchedOriginals = [];
    $originalByName = [];
    foreach ($originalColumns as $index => $column) {
      $originalByName[self::normalizeValue($column['COLUMN_NAME'] ?? '')] = $index;
    }
    foreach (self::$columns as $index => $column) {
      $name = self::normalizeValue($column['COLUMN_NAME'] ?? '');
      if ($name === '') {
        continue;
      }
      $originalIndex = $originalByName[$name] ?? false;
      if ($originalIndex === false && isset($originalColumns[$index])) {
        $originalIndex = $index;
      }
      if ($originalIndex === false || !isset($originalColumns[$originalIndex])) {
        $previousName = self::previousNamedColumn(self::$columns, $index);
        $clauses[] = 'ADD COLUMN ' . self::columnDefinition($column) . self::columnPositionClause($previousName);
        continue;
      }
      $matchedOriginals[$originalIndex] = true;
      $original = $originalColumns[$originalIndex];
      $originalName = self::normalizeValue($original['COLUMN_NAME'] ?? '');
      $previousName = self::previousNamedColumn(self::$columns, $index);
      $originalPreviousName = self::previousNamedColumn($originalColumns, $originalIndex);
      $positionChanged = $previousName !== $originalPreviousName;
      if (self::normalizeColumn($original) === self::normalizeColumn($column) && !$positionChanged) {
        continue;
      }
      if ($name !== $originalName) {
        $clauses[] = 'CHANGE COLUMN ' . self::quoteIdentifier($originalName) . ' ' . self::columnDefinition($column) . self::columnPositionClause($previousName);
      } else {
        $clauses[] = 'MODIFY COLUMN ' . self::columnDefinition($column) . self::columnPositionClause($previousName);
      }
    }
    foreach ($originalColumns as $index => $column) {
      $name = self::normalizeValue($column['COLUMN_NAME'] ?? '');
      if ($name !== '' && !isset($matchedOriginals[$index])) {
        $clauses[] = 'DROP COLUMN ' . self::quoteIdentifier($name);
      }
    }
    return $clauses;
  }

  private static function indexDropClause($index) {
    return ($index['name'] === 'PRIMARY' || strtoupper($index['type']) === 'PRIMARY') ?
      'DROP PRIMARY KEY' :
      'DROP INDEX ' . self::quoteIdentifier($index['name']);
  }

  private static function generateIndexAlterClauses() {
    $clauses = [];
    $originalIndexes = self::groupIndexes(self::$definition['indexes'] ?? []);
    $currentIndexes = self::groupIndexes(self::$indexes);
    foreach ($originalIndexes as $name => $index) {
      if (!isset($currentIndexes[$name])) {
        $clauses[] = self::indexDropClause($index);
      }
    }
    foreach ($currentIndexes as $name => $index) {
      if (empty($index['columns'])) {
        continue;
      }
      if (isset($originalIndexes[$name]) && self::normalizeIndex($originalIndexes[$name]) === self::normalizeIndex($index)) {
        continue;
      }
      if (isset($originalIndexes[$name])) {
        $clauses[] = self::indexDropClause($originalIndexes[$name]);
      }
      $clauses[] = 'ADD ' . self::indexDefinition($index);
    }
    return $clauses;
  }

  private static function generateForeignKeyAlterClauses() {
    $clauses = [
      'drop' => [],
      'add' => []
    ];
    $originalForeignKeys = self::groupForeignKeys(self::$definition['foreignKeys'] ?? []);
    $currentForeignKeys = self::groupForeignKeys(self::$foreignKeys);
    foreach ($originalForeignKeys as $name => $foreignKey) {
      if (!isset($currentForeignKeys[$name])) {
        $clauses['drop'][] = 'DROP FOREIGN KEY ' . self::quoteIdentifier($name);
      }
    }
    foreach ($currentForeignKeys as $name => $foreignKey) {
      if (empty($foreignKey['columns'])) {
        continue;
      }
      if (isset($originalForeignKeys[$name]) && self::normalizeForeignKey($originalForeignKeys[$name]) === self::normalizeForeignKey($foreignKey)) {
        continue;
      }
      if (isset($originalForeignKeys[$name])) {
        $clauses['drop'][] = 'DROP FOREIGN KEY ' . self::quoteIdentifier($name);
      }
      $clauses['add'][] = 'ADD ' . self::foreignKeyDefinition($foreignKey);
    }
    return $clauses;
  }

  private static function triggerMap($triggers) {
    $map = [];
    foreach ($triggers as $trigger) {
      $name = self::normalizeValue($trigger['TRIGGER_NAME'] ?? '');
      if ($name !== '') {
        $map[$name] = $trigger;
      }
    }
    return $map;
  }

  private static function generateTriggerStatements($currentName, $targetName) {
    $statements = [];
    $originalTriggers = self::triggerMap(self::$definition['triggers'] ?? []);
    $currentTriggers = self::triggerMap(self::$triggers);
    foreach ($originalTriggers as $name => $trigger) {
      if (!isset($currentTriggers[$name]) || self::normalizeTrigger($trigger) !== self::normalizeTrigger($currentTriggers[$name]) || $currentName !== $targetName) {
        $statements[] = 'DROP TRIGGER ' . self::quoteIdentifier(self::$schema) . '.' . self::quoteIdentifier($name) . ';';
      }
    }
    foreach ($currentTriggers as $name => $trigger) {
      if (!isset($originalTriggers[$name]) || self::normalizeTrigger($originalTriggers[$name]) !== self::normalizeTrigger($trigger) || $currentName !== $targetName) {
        $statements[] = self::triggerCreateSql($targetName, $trigger);
      }
    }
    return $statements;
  }

  public static function close($panel) {
    $panel->hide();
    \SPTK\Element::refresh();
  }

}
