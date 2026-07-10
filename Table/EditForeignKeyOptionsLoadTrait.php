<?php

namespace MADB\Table;

/** Receives asynchronous foreign-key option loads and applies them to target schema/table/column controls. */
trait EditForeignKeyOptionsLoadTrait {

  /** Applies foreign key schemas values to table editor state or controls. */
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

  /** Applies foreign key tables values to table editor state or controls. */
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

  /** Applies foreign key target fields values to table editor state or controls. */
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

  /** Coordinates change foreign key target schema work in the table editor. */
  public static function changeForeignKeyTargetSchema($list) {
    $schema = (string) $list->getValue();
    self::loadForeignKeyTables($schema);
    self::loadForeignKeyTargetFields('', '');
    \SPTK\Element::refresh();
  }

  /** Coordinates change foreign key target table work in the table editor. */
  public static function changeForeignKeyTargetTable($list) {
    $schemaElement = \SPTK\Element::byName('foreign-key-target-schema', self::itemPanel('table-foreign-key-editor'));
    $schema = $schemaElement === false ? '' : (string) $schemaElement->getValue();
    $table = (string) $list->getValue();
    self::loadForeignKeyTargetFields($schema, $table);
    \SPTK\Element::refresh();
  }

}
