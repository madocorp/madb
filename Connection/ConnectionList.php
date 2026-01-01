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
    $configDir = \MADB\Config\ConfigDir::getPath();
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

  public function getNameAndTypeList() {
    $nameList = [];
    foreach ($this->connectionList as $connectionData) {
      $nameList[$connectionData['name']] = $connectionData['type'];
    }
    return $nameList;
  }

  public function get($name) {
    foreach ($this->connectionList as $connectionData) {
      if ($connectionData['name'] === $name) {
        return $connectionData;
      }
    }
    return false;
  }

  public function getSeparators() {
    $separators = [];
    foreach ($this->connectionList as $connectionData) {
      if (isset($connectionData['separator'])) {
        $separators[] = $connectionData['name'];
      }
    }
    return $separators;
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
    $configDir = \MADB\Config\ConfigDir::getPath();
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

  public function delete() {
    foreach ($this->connectionList as $i => $connectionData) {
      if ($connectionData['name'] === $this->current['name']) {
        unset($this->connectionList[$i]);
        break;
      }
    }
  }

  public function getCount() {
    return count($this->connectionList);
  }

  public function sort($order) {
    $sortedList = [];
    $j = 0;
    foreach ($order as $name) {
      if (strpos($name, SortController::SEPARATOR_STRING) === 0) {
        if ($j > 0) {
          $sortedList[$j - 1]['separator'] = true;
        }
      } else {
        foreach ($this->connectionList as $i => $connectionData) {
          if ($connectionData['name'] == $name) {
            unset($connectionData['separator']);
            $sortedList[$j] = $connectionData;
            $j++;
            unset($this->connectionList[$i]);
            break;
          }
        }
      }
    }
    $this->connectionList = $sortedList;
  }

}
