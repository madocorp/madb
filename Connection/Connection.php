<?php

namespace MADB\Connection;

class Connection {

  public $name;

  public function __construct($data) {
    $this->name = $data['name'];
  }

}
