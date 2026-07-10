<?php

namespace MADB\Connection;

/** Persists configured connections and menu separators in the user configuration file. */
class ConnectionList {

  private static $instance;

  private $connectionList = [];
  private $fileName = 'connections.json';
  public $current = false;

  /** Initializes connection menu state. */
  public function __construct() {
    self::$instance = $this;
    $this->load();
  }

  /** Returns instance data used by the connection menu. */
  public static function getInstance() {
    return self::$instance;
  }

  /** Loads connection definitions and separators from the user configuration file. */
  public function load() {
    $connectionListFile = \SPTK\Config::getFilePath($this->fileName);
    if (!file_exists($connectionListFile)) {
      return;
    }
    $data = \SPTK\Config::load($connectionListFile);
    $this->connectionList = [];
    foreach ($data['connections'] as $connectionData) {
      $this->connectionList[] = $connectionData;
    }
  }

  /** Returns name list data used by the connection menu. */
  public function getNameList() {
    $nameList = [];
    foreach ($this->connectionList as $connectionData) {
      $nameList[] = $connectionData['name'];
    }
    return $nameList;
  }

  /** Returns name and type list data used by the connection menu. */
  public function getNameAndTypeList() {
    $nameList = [];
    foreach ($this->connectionList as $connectionData) {
      $nameList[$connectionData['name']] = $connectionData['type'];
    }
    return $nameList;
  }

  /** Returns a configured connection by name. */
  public function get($name) {
    foreach ($this->connectionList as $connectionData) {
      if ($connectionData['name'] === $name) {
        return $connectionData;
      }
    }
    return false;
  }

  /** Returns separators data used by the connection menu. */
  public function getSeparators() {
    $separators = [];
    foreach ($this->connectionList as $connectionData) {
      if (isset($connectionData['separator'])) {
        $separators[] = $connectionData['name'];
      }
    }
    return $separators;
  }

  /** Adds or replaces a connection definition in the saved connection list. */
  public function add($connectionData) {
    foreach ($this->connectionList as $i => $item) {
      if ($connectionData['name'] == $item['name']) {
        $this->connectionList[$i] = $connectionData;
        return;
      }
    }
    $this->connectionList[] = $connectionData;
  }

  /** Writes connection definitions and separators to the user configuration file. */
  public function save() {
    $connectionListFile = \SPTK\Config::getFilePath($this->fileName);
    \SPTK\Config::save($connectionListFile, $this->connectionList, 'connections');
    $currentName = false;
    if ($this->current !== false) {
      $currentName = $this->current['name'];
    }
    $this->setCurrent($currentName);
  }

  /** Applies current values to connection menu state or controls. */
  public function setCurrent($name) {
    $this->current = false;
    foreach ($this->connectionList as $connectionData) {
      if ($connectionData['name'] == $name) {
        $this->current = $connectionData;
        return;
      }
    }
  }

  /** Deletes the current connection from the saved connection list. */
  public function delete() {
    foreach ($this->connectionList as $i => $connectionData) {
      if ($connectionData['name'] === $this->current['name']) {
        unset($this->connectionList[$i]);
        break;
      }
    }
  }

  /** Returns count data used by the connection menu. */
  public function getCount() {
    return count($this->connectionList);
  }

  /** Applies the connection-menu order saved by the sort panel. */
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
