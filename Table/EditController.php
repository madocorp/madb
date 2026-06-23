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

  private static function addListItem($list, $text, $right = false) {
    $item = new \SPTK\Elements\ListItem($list);
    $item->setText($text);
    if ($right !== false && $right !== '') {
      $item->setRight($right);
    }
  }

  private static function setTitle($title) {
    $panelTitle = \SPTK\Element::firstByType('PanelTitle', self::panel());
    if ($panelTitle !== false) {
      $panelTitle->setText($title);
    }
  }

  private static function open($tab, $requiresTable) {
    if (!self::validateContext($requiresTable)) {
      return;
    }
    self::$mode = $requiresTable ? 'edit' : 'create';
    self::$schema = self::selectedSchema();
    self::$table = $requiresTable ? self::selectedTable() : false;
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

    self::setTitle('Edit ' . self::$schema . '.' . self::$table);
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
    $table = $definition['table'] ?? [];
    self::panel()->setValue([
      'table-name' => $table['name'] ?? ($response['table'] ?? ''),
      'table-charset' => $table['charset'] ?? '',
      'table-collation' => $table['collation'] ?? '',
      'table-comment' => $table['comment'] ?? ''
    ]);
    self::setColumns($definition['columns'] ?? []);
    self::setIndexes($definition['indexes'] ?? []);
    self::setForeignKeys($definition['foreignKeys'] ?? []);
    self::setTriggers($definition['triggers'] ?? []);
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
      $nullable = ($column['IS_NULLABLE'] ?? '') === 'YES' ? 'NULL' : 'NOT NULL';
      $extra = $column['EXTRA'] ?? '';
      $right = trim($nullable . ' ' . $extra);
      self::addListItem($list, trim($name . ' ' . $type), $right);
    }
  }

  private static function setIndexes($indexes) {
    $list = self::listElement('table-editor-indexes');
    $list->clear();
    if (empty($indexes)) {
      self::setPlaceholder('table-editor-indexes', 'No indexes loaded');
      return;
    }
    foreach ($indexes as $index) {
      $name = $index['INDEX_NAME'] ?? '';
      $column = $index['COLUMN_NAME'] ?? '';
      $sequence = $index['SEQ_IN_INDEX'] ?? '';
      $type = $index['INDEX_TYPE'] ?? '';
      $unique = ((int) ($index['NON_UNIQUE'] ?? 1)) === 0 ? 'unique' : 'index';
      self::addListItem($list, "{$name} #{$sequence} {$column}", trim("{$unique} {$type}"));
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
      self::addListItem($list, "{$name} {$column} -> {$targetSchema}.{$targetTable}.{$targetColumn}", $rules);
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
      self::addListItem($list, $name, trim("{$timing} {$event}"));
    }
  }

  public static function generate() {
    \SPTK\Elements\WarningPanel::forge('Not implemented', 'Table editing SQL generation is not implemented yet.');
  }

  public static function close($panel) {
    $panel->hide();
    \SPTK\Element::refresh();
  }

}
