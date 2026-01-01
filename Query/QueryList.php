<?php

namespace MADB\Query;

class QueryList {

  private $name;
  private $queryList = [];

  public function __construct($name) {
    $this->name = $name;
    $this->load();
  }

  private function load() {
    $file = \MADB\Config\ConfigDir::getFilePath($this->name);
    $xml = new \MADB\Config\XML($file);
    $xml = $xml->load();
  }

  public function save() {
    $data = [];
    $file = \MADB\Config\ConfigDir::getFilePath($this->name);
    $xml = new \MADB\Config\XML($file);
    $xml->save($data, 'queryList');
  }

}
