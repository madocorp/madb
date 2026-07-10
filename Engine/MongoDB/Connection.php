<?php

namespace MADB\Engine\MongoDB;

/** Provides the MongoDB connection placeholder for the MADB engine interface. */
class Connection extends \MADB\Connection\Connection {

  /** Returns defaults data used by the MongoDB engine. */
  public static function getDefaults() {
    return [
      'name' => 'mongo',
    ];
  }

  /** Returns menu labels data used by the MongoDB engine. */
  public static function getMenuLabels() {
    return [
      'schema' => 'Database',
      'table' => 'Collection'
    ];
  }

  /** Coordinates connect work in the MongoDB engine. */
  public function connect() {
    // ...
  }

  /** Coordinates test work in the MongoDB engine. */
  public function test() {
    return "Test passed";
  }

  /** Coordinates schema list work in the MongoDB engine. */
  public function schemaList() {
    return [];
  }

  /** Creates schema data for the MongoDB engine. */
  public function createSchema($schema) {
    throw new \Exception('MongoDB database creation is not implemented yet.');
  }

  /** Coordinates schema info work in the MongoDB engine. */
  public function schemaInfo($schema) {
    throw new \Exception('MongoDB database statistics are not implemented yet.');
  }

  /** Coordinates rename schema info work in the MongoDB engine. */
  public function renameSchemaInfo($schema, $targetSchema) {
    throw new \Exception('MongoDB database rename is not implemented yet.');
  }

  /** Coordinates rename schema work in the MongoDB engine. */
  public function renameSchema($schema, $targetSchema) {
    throw new \Exception('MongoDB database rename is not implemented yet.');
  }

  /** Coordinates drop schema work in the MongoDB engine. */
  public function dropSchema($schema) {
    throw new \Exception('MongoDB database drop is not implemented yet.');
  }

  /** Coordinates character sets and collations work in the MongoDB engine. */
  public function characterSetsAndCollations() {
    return [
      'charsets' => [],
      'collations' => [],
      'engines' => []
    ];
  }

  /** Coordinates table list work in the MongoDB engine. */
  public function tableList($schema) {
    return [];
  }

  /** Coordinates table fields work in the MongoDB engine. */
  public function tableFields($schema, $table) {
    return [];
  }

  /** Coordinates table definition work in the MongoDB engine. */
  public function tableDefinition($schema, $table) {
    return [
      'table' => [
        'name' => $table,
        'type' => 'COLLECTION',
        'charset' => '',
        'collation' => '',
        'comment' => ''
      ],
      'columns' => [],
      'indexes' => [],
      'foreignKeys' => [],
      'triggers' => []
    ];
  }

  /** Runs query through the MongoDB engine. */
  public function query($sql) {
    throw new \Exception('MongoDB query execution is not implemented yet.');
  }

  /** Runs batch through the MongoDB engine. */
  public function queryBatch($statements, $resultFiles = [], $schema = false, $progress = false) {
    throw new \Exception('MongoDB query execution is not implemented yet.');
  }

}
