<?php

namespace MADB\Engine\MongoDB;

class Connection extends \MADB\Connection\Connection {

  public static function getDefaults() {
    return [
      'name' => 'mongo',
    ];
  }

  public static function getMenuLabels() {
    return [
      'schema' => 'Database',
      'table' => 'Collection'
    ];
  }

  public function connect() {
    // ...
  }

  public function test() {
    return "Test passed";
  }

  public function schemaList() {
    return [];
  }

  public function createSchema($schema) {
    throw new \Exception('MongoDB database creation is not implemented yet.');
  }

  public function schemaInfo($schema) {
    throw new \Exception('MongoDB database statistics are not implemented yet.');
  }

  public function renameSchemaInfo($schema, $targetSchema) {
    throw new \Exception('MongoDB database rename is not implemented yet.');
  }

  public function renameSchema($schema, $targetSchema) {
    throw new \Exception('MongoDB database rename is not implemented yet.');
  }

  public function dropSchema($schema) {
    throw new \Exception('MongoDB database drop is not implemented yet.');
  }

  public function characterSetsAndCollations() {
    return [
      'charsets' => [],
      'collations' => [],
      'engines' => []
    ];
  }

  public function tableList($schema) {
    return [];
  }

  public function tableFields($schema, $table) {
    return [];
  }

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

  public function query($sql) {
    throw new \Exception('MongoDB query execution is not implemented yet.');
  }

  public function queryBatch($statements, $resultFiles = [], $progress = false) {
    throw new \Exception('MongoDB query execution is not implemented yet.');
  }

}
