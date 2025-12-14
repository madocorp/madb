<?php

namespace MADB\Connection;

class ConnectionList {

  private static $instance;

  private $connectionList = [];
  private $fileName = 'connections.xml';

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
    foreach ($xmlData['connections']['connection'] as $connectionData) {
      $this->connectionList[] = new Connection($connectionData);
    }
  }

  public function getNameList() {
    $nameList = [];
    foreach ($this->connectionList as $connection) {
      $nameList[] = $connection->name;
    }
    return $nameList;
  }

}
