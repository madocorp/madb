<?php

namespace MADB\Connection;

class ConnectionList {

  private static $instance;

  private $connectionList = [];
  private $fileName = 'connections.xml';
  public $current = false;

  public function __construct() {
    self::$instance = $this;
    $this->load();
  }

  public static function getInstance() {
    return self::$instance;
  }

  public function load() {
    $configDir = (new \MADB\Config\ConfigDir)->getPath();
    $connectionListFile = "{$configDir}/{$this->fileName}";
    if (!file_exists($connectionListFile)) {
      return;
    }
    $xml = new \MADB\Config\XML($connectionListFile);
    $xmlData = $xml->load();
    $this->connectionList = [];
    foreach ($xmlData['connections'] as $connectionData) {
      $this->connectionList[] = $connectionData;
    }
  }

  public function getNameList() {
    $nameList = [];
    foreach ($this->connectionList as $connectionData) {
      $nameList[] = $connectionData['name'];
    }
    return $nameList;
  }

  public function add($connectionData) {
    foreach ($this->connectionList as $i => $item) {
      if ($connectionData['name'] == $item['name']) {
        $this->connectionList[$i] = $connectionData;
        return;
      }
    }
    $this->connectionList[] = $connectionData;
  }

  public function save() {
    $configDir = (new \MADB\Config\ConfigDir)->getPath();
    $connectionListFile = "{$configDir}/{$this->fileName}";
    $xml = new \MADB\Config\XML($connectionListFile);
    $xml->save($this->connectionList, 'connections');
    $currentName = false;
    if ($this->current !== false) {
      $currentName = $this->current['name'];
    }
    $this->setCurrent($currentName);
  }

  public function setCurrent($name) {
    $this->current = false;
    foreach ($this->connectionList as $connectionData) {
      if ($connectionData['name'] == $name) {
        $this->current = $connectionData;
        return;
      }
    }
  }

}
