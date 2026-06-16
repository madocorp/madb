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
    $file = \SPTK\Config::getFilePath($this->name);
    if (!\SPTK\Config::exists($file)) {
      return;
    }
    $data = \SPTK\Config::load($file);
  }

  public function save() {
    $data = [];
    $file = \SPTK\Config::getFilePath($this->name);
    \SPTK\Config::save($file, $data, 'queryList');
  }

}
