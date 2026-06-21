<?php

namespace MADB\Connection;

abstract class Connection {

  public $queryTime;

  public function __construct($data) {
    $defaults = static::getDefaults();
    $this->data = [];
    foreach ($defaults as $key => $default) {
      if (isset($data[$key])) {
        $this->data[$key] = $data[$key];
      } else {
        $this->data[$key] = $default;
      }
    }
  }

  abstract static public function getDefaults();
  public static function getMenuLabels() {
    return [
      'schema' => 'Schema',
      'table' => 'Table'
    ];
  }

  abstract public function connect();
  abstract public function test();
  abstract public function schemaList();
  abstract public function tableList($schema);
  abstract public function query();

}
