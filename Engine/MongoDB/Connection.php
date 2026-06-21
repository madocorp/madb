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

  public function tableList($schema) {
    return [];
  }

  public function query() {
    // ...
  }

}
