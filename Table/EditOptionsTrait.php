<?php

namespace MADB\Table;

/** Loads and applies charset, collation, engine, and foreign-key option lists used by the table editor controls. */
trait EditOptionsTrait {

  /** Applies character options values to table editor controls. */
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

  /** Loads character options data for the table editor. */
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

  /** Coordinates table names work in the table editor. */
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

  /** Coordinates column names work in the table editor. */
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

  /** Coordinates reset foreign key options for connection work in the table editor. */
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

  /** Applies foreign key schema options values to table editor controls. */
  private static function applyForeignKeySchemaOptions($selectedSchema) {
    self::setSelectOptions('foreign-key-target-schema', self::$foreignKeySchemas, $selectedSchema);
  }

  /** Applies foreign key table options values to table editor controls. */
  private static function applyForeignKeyTableOptions($selectedTable) {
    self::setSelectOptions('foreign-key-target-table', self::$foreignKeyTables, $selectedTable);
  }

  /** Loads foreign key schemas data for the table editor. */
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

  /** Loads foreign key tables data for the table editor. */
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

  /** Applies foreign key column list values to table editor state or controls. */
  private static function setForeignKeyColumnList($name, $columns, $selectedColumns) {
    $list = \SPTK\Element::byName($name, self::itemPanel('table-foreign-key-editor'));
    if ($list === false) {
      return;
    }
    $list->clear();
    $items = [];
    foreach ($columns as $column) {
      if ($column === '') {
        continue;
      }
      $items[] = [
        'value' => $column,
        'selectable' => true,
        'filterable' => true
      ];
    }
    $list->setItems($items);
    $list->setSelectedValues($selectedColumns);
  }

  /** Applies foreign key source column list values to table editor state or controls. */
  private static function setForeignKeySourceColumnList($selectedColumns) {
    self::setForeignKeyColumnList('foreign-key-column', self::columnNames(self::$columns), $selectedColumns);
  }

  /** Applies foreign key target column list values to table editor state or controls. */
  private static function setForeignKeyTargetColumnList($selectedColumns) {
    self::setForeignKeyColumnList('foreign-key-target-column', self::$foreignKeyTargetFields, $selectedColumns);
  }

  /** Loads foreign key target fields data for the table editor. */
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

  /** Coordinates table option clauses work in the table editor. */
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

}
