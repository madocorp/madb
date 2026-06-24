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
      self::setPlaceholder($name, $message);
    }
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
    $cell->addText($text);
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

  private static function tableOptionClauses($charset, $collation, $comment) {
    $clauses = [];
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
    $panel = self::panel();
    $tabs = self::tabs();
    if ($panel === false || $tabs === false) {
      return;
    }
    $tabs->selectTab($tab);
    if (self::$mode === 'create') {
      self::setTitle('Create table in ' . self::$schema);
      $panel->setValue([
        'table-name' => '',
        'table-charset' => '',
        'table-collation' => '',
        'table-comment' => ''
      ]);
      self::resetLists('No table selected');
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
      'table-comment' => ''
    ]);
    self::resetLists('Loading...');
    $panel->show();
    if ($tab === self::TAB_MAIN) {
      $panel->activateInput('table-name');
    }
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
    $table = $definition['table'] ?? [];
    self::panel()->setValue([
      'table-name' => $table['name'] ?? ($response['table'] ?? ''),
      'table-charset' => $table['charset'] ?? '',
      'table-collation' => $table['collation'] ?? '',
      'table-comment' => $table['comment'] ?? ''
    ]);
    self::setColumns(self::$columns);
    self::setIndexes(self::$indexes);
    self::setForeignKeys(self::$foreignKeys);
    self::setTriggers(self::$triggers);
    \SPTK\Element::refresh();
  }

  private static function setColumns($columns) {
    $list = self::listElement('table-editor-columns');
    $list->clear();
    if (empty($columns)) {
      self::setPlaceholder('table-editor-columns', 'No columns loaded');
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
        $attributes[] = 'UNIQUE';
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
      self::setPlaceholder('table-editor-indexes', 'No indexes loaded');
      return;
    }
    $groupedIndexes = [];
    foreach ($indexes as $index) {
      $name = $index['INDEX_NAME'] ?? '';
      if (!isset($groupedIndexes[$name])) {
        $unique = ((int) ($index['NON_UNIQUE'] ?? 1)) === 0 ? 'UNIQUE' : 'INDEX';
        $type = $index['INDEX_TYPE'] ?? '';
        $groupedIndexes[$name] = [
          'type' => trim("{$unique} {$type}"),
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
      self::setPlaceholder('table-editor-foreign-keys', 'No foreign keys loaded');
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
      self::setPlaceholder('table-editor-triggers', 'No triggers loaded');
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

  private static function findTrigger($name) {
    foreach (self::$triggers as $index => $trigger) {
      if (($trigger['TRIGGER_NAME'] ?? '') === $name) {
        return [$index, $trigger];
      }
    }
    return [false, false];
  }

  public static function openColumnEditor($item) {
    [$index, $column] = self::findColumn($item->getValue());
    if ($column === false) {
      return;
    }
    self::$editingItem = $index;
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
    if (!self::applyColumnEditorValues($panel)) {
      return;
    }
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
    self::setColumns(self::$columns);
    $list = self::listElement('table-editor-columns');
    if ($list !== false) {
      $list->moveCursor(self::$editingItem);
    }
    return true;
  }

  public static function selectColumnType($item) {
    $panel = self::itemPanel('table-column-editor');
    $panel->activateInput('column-parameter');
    self::applyColumnEditorValues($panel);
    \SPTK\Element::refresh();
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
    $index = false;
    foreach (self::$indexes as $candidate) {
      if (($candidate['INDEX_NAME'] ?? '') === $name) {
        $index = $candidate;
        break;
      }
    }
    $panel = self::itemPanel('table-index-editor');
    $panel->setValue([
      'index-name' => $name,
      'index-type' => $index['INDEX_TYPE'] ?? '',
      'index-unique' => ((int) ($index['NON_UNIQUE'] ?? 1)) === 0 ? 'YES' : 'NO',
      'index-columns' => implode(', ', $parts),
      'index-cardinality' => $index['CARDINALITY'] ?? ''
    ]);
    self::showItemPanel($panel, 'index-name');
  }

  public static function saveIndexEditor($panel) {
    if (self::$editingItem === false) {
      return;
    }
    $values = $panel->getValue();
    $oldName = self::$editingItem;
    $newName = $values['index-name'] ?? '';
    $columns = array_map('trim', explode(',', $values['index-columns'] ?? ''));
    $sequence = 1;
    foreach (self::$indexes as $i => $index) {
      if (($index['INDEX_NAME'] ?? '') !== $oldName) {
        continue;
      }
      $column = $columns[$sequence - 1] ?? ($index['COLUMN_NAME'] ?? '');
      $descending = strpos($column, '-') === 0;
      self::$indexes[$i]['INDEX_NAME'] = $newName;
      self::$indexes[$i]['INDEX_TYPE'] = $values['index-type'] ?? '';
      self::$indexes[$i]['NON_UNIQUE'] = ($values['index-unique'] ?? '') === 'YES' ? 0 : 1;
      self::$indexes[$i]['COLUMN_NAME'] = ltrim($column, '-');
      self::$indexes[$i]['COLLATION'] = $descending ? 'D' : 'A';
      self::$indexes[$i]['CARDINALITY'] = $values['index-cardinality'] ?? '';
      self::$indexes[$i]['SEQ_IN_INDEX'] = $sequence;
      $sequence++;
    }
    self::setIndexes(self::$indexes);
    self::closeItemEditor($panel);
  }

  public static function openForeignKeyEditor($item) {
    [$index, $foreignKey] = self::findForeignKey($item->getValue());
    if ($foreignKey === false) {
      return;
    }
    self::$editingItem = $index;
    $panel = self::itemPanel('table-foreign-key-editor');
    $panel->setValue([
      'foreign-key-name' => $foreignKey['CONSTRAINT_NAME'] ?? '',
      'foreign-key-column' => $foreignKey['COLUMN_NAME'] ?? '',
      'foreign-key-target-schema' => $foreignKey['REFERENCED_TABLE_SCHEMA'] ?? '',
      'foreign-key-target-table' => $foreignKey['REFERENCED_TABLE_NAME'] ?? '',
      'foreign-key-target-column' => $foreignKey['REFERENCED_COLUMN_NAME'] ?? '',
      'foreign-key-update-rule' => $foreignKey['UPDATE_RULE'] ?? '',
      'foreign-key-delete-rule' => $foreignKey['DELETE_RULE'] ?? ''
    ]);
    self::showItemPanel($panel, 'foreign-key-name');
  }

  public static function saveForeignKeyEditor($panel) {
    if (self::$editingItem === false || !isset(self::$foreignKeys[self::$editingItem])) {
      return;
    }
    $values = $panel->getValue();
    self::$foreignKeys[self::$editingItem]['CONSTRAINT_NAME'] = $values['foreign-key-name'] ?? '';
    self::$foreignKeys[self::$editingItem]['COLUMN_NAME'] = $values['foreign-key-column'] ?? '';
    self::$foreignKeys[self::$editingItem]['REFERENCED_TABLE_SCHEMA'] = $values['foreign-key-target-schema'] ?? '';
    self::$foreignKeys[self::$editingItem]['REFERENCED_TABLE_NAME'] = $values['foreign-key-target-table'] ?? '';
    self::$foreignKeys[self::$editingItem]['REFERENCED_COLUMN_NAME'] = $values['foreign-key-target-column'] ?? '';
    self::$foreignKeys[self::$editingItem]['UPDATE_RULE'] = $values['foreign-key-update-rule'] ?? '';
    self::$foreignKeys[self::$editingItem]['DELETE_RULE'] = $values['foreign-key-delete-rule'] ?? '';
    self::setForeignKeys(self::$foreignKeys);
    self::closeItemEditor($panel);
  }

  public static function openTriggerEditor($item) {
    [$index, $trigger] = self::findTrigger($item->getValue());
    if ($trigger === false) {
      return;
    }
    self::$editingItem = $index;
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
    self::$triggers[self::$editingItem]['TRIGGER_NAME'] = $values['trigger-name'] ?? '';
    self::$triggers[self::$editingItem]['ACTION_TIMING'] = $values['trigger-timing'] ?? '';
    self::$triggers[self::$editingItem]['EVENT_MANIPULATION'] = $values['trigger-event'] ?? '';
    self::$triggers[self::$editingItem]['ACTION_STATEMENT'] = $values['trigger-statement'] ?? '';
    self::setTriggers(self::$triggers);
    self::closeItemEditor($panel);
  }

  public static function closeItemEditor($panel) {
    self::$editingItem = false;
    $panel->hide();
    \SPTK\Element::refresh();
  }

  public static function generate() {
    $connection = self::currentConnection();
    if ($connection === false || self::$schema === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection and ' . self::schemaLabel() . ' before saving.');
      return;
    }
    $values = self::panel()->getValue();
    $table = trim($values['table-name'] ?? '');
    if ($table === '') {
      \SPTK\Elements\WarningPanel::forge('No ' . self::tableLabel() . ' name!', 'Please enter a ' . self::tableLabel() . ' name before saving.');
      return;
    }
    $charset = trim($values['table-charset'] ?? '');
    $collation = trim($values['table-collation'] ?? '');
    $comment = trim($values['table-comment'] ?? '');
    if (self::$mode === 'create') {
      $sql = self::generateCreateSql($table, $charset, $collation, $comment);
      \MADB\Main\ScreenController::addQuery(self::queryName('CREATE', $table), $sql, $connection['name'], self::$schema, $table);
      return;
    }
    $sql = self::generateAlterSql($table, $charset, $collation, $comment);
    if ($sql === false) {
      return;
    }
    if ($sql === '') {
      \SPTK\Elements\WarningPanel::forge('No changes', 'No table changes were detected.');
      return;
    }
    \MADB\Main\ScreenController::addQuery(self::queryName('ALTER', self::$table), $sql, $connection['name'], self::$schema, $table);
  }

  private static function generateCreateSql($table, $charset, $collation, $comment) {
    $sql = 'CREATE TABLE ' . self::quoteQualifiedTable(self::$schema, $table) . " (\n  [COLUMNS]\n)";
    $clauses = self::tableOptionClauses($charset, $collation, $comment);
    if (!empty($clauses)) {
      $sql .= "\n" . implode("\n", $clauses);
    }
    return $sql . ';';
  }

  private static function generateAlterSql($table, $charset, $collation, $comment) {
    $original = self::$definition['table'] ?? [];
    if (empty($original)) {
      \SPTK\Elements\WarningPanel::forge('Table metadata not loaded', 'Please wait until the table metadata has loaded before saving.');
      return false;
    }
    $currentName = $original['name'] ?? self::$table;
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
    return implode("\n\n", $statements);
  }

  public static function close($panel) {
    $panel->hide();
    \SPTK\Element::refresh();
  }

}
