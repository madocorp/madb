<?php

namespace MADB\Connection;

class ConnectionMongoDB extends Connection {

  public static function getDefaults() {
    return [
      'name' => 'mongo',
    ];
  }

  public function connect() {
    // ...
  }

  public function test() {
    return "Test passed";
  }

  public function schemaList() {
    // ...
  }

  public function query() {
    // ...
  }

}
