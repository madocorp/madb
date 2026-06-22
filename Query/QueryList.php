<?php

namespace MADB\Query;

class QueryList {

  private static $instance;

  private $fileName = 'queries.json';
  private $queryList = [];

  public function __construct() {
    self::$instance = $this;
    $this->load();
  }

  public static function getInstance() {
    return self::$instance;
  }

  public function load() {
    $file = \SPTK\Config::getFilePath($this->fileName);
    if (!file_exists($file)) {
      return;
    }
    $data = \SPTK\Config::load($file);
    $this->queryList = $data['queryList'] ?? [];
    foreach ($this->queryList as $connectionName => $connectionQueries) {
      if (isset($connectionQueries['queries'])) {
        continue;
      }
      $this->queryList[$connectionName] = [
        'active' => false,
        'queries' => is_array($connectionQueries) ? $connectionQueries : []
      ];
    }
    foreach ($this->queryList as $connectionName => $connectionQueries) {
      $queries = $connectionQueries['queries'] ?? [];
      foreach ($queries as $index => $query) {
        $this->queryList[$connectionName]['queries'][$index] = $this->normalize($query);
      }
      $active = $this->queryList[$connectionName]['active'] ?? false;
      if ($active === false || $this->findIndex($connectionName, $active) === false) {
        $first = $this->queryList[$connectionName]['queries'][0]['id'] ?? false;
        $this->queryList[$connectionName]['active'] = $first;
      }
    }
  }

  public function save() {
    $file = \SPTK\Config::getFilePath($this->fileName);
    \SPTK\Config::save($file, $this->queryList, 'queryList');
  }

  private function ensureConnection($connectionName) {
    if ($connectionName === false || $connectionName === '') {
      return false;
    }
    if (!isset($this->queryList[$connectionName])) {
      $this->queryList[$connectionName] = [
        'active' => false,
        'queries' => []
      ];
    }
    return true;
  }

  private function createId() {
    return bin2hex(random_bytes(8));
  }

  private function normalize($query) {
    $now = time();
    return array_merge([
      'id' => $this->createId(),
      'name' => 'NEW',
      'sql' => '',
      'schema' => '',
      'table' => '',
      'status' => 'new',
      'result' => false,
      'info' => [],
      'error' => false,
      'createdAt' => $now,
      'updatedAt' => $now
    ], is_array($query) ? $query : []);
  }

  public function getAll($connectionName) {
    if (!$this->ensureConnection($connectionName)) {
      return [];
    }
    return $this->queryList[$connectionName]['queries'];
  }

  public function getActiveId($connectionName) {
    if (!$this->ensureConnection($connectionName)) {
      return false;
    }
    return $this->queryList[$connectionName]['active'];
  }

  public function setActive($connectionName, $id) {
    if (!$this->ensureConnection($connectionName)) {
      return false;
    }
    if ($id !== false && $this->findIndex($connectionName, $id) === false) {
      return false;
    }
    $this->queryList[$connectionName]['active'] = $id;
    $this->save();
    return true;
  }

  public function getActive($connectionName) {
    $id = $this->getActiveId($connectionName);
    if ($id === false) {
      return false;
    }
    return $this->get($connectionName, $id);
  }

  public function get($connectionName, $id) {
    $index = $this->findIndex($connectionName, $id);
    if ($index === false) {
      return false;
    }
    return $this->queryList[$connectionName]['queries'][$index];
  }

  public function findIndex($connectionName, $id) {
    if (!$this->ensureConnection($connectionName)) {
      return false;
    }
    foreach ($this->queryList[$connectionName]['queries'] as $index => $query) {
      if (($query['id'] ?? false) === $id) {
        return $index;
      }
    }
    return false;
  }

  public function add($connectionName, $query) {
    if (!$this->ensureConnection($connectionName)) {
      return false;
    }
    $query = $this->normalize($query);
    $query['updatedAt'] = time();
    array_unshift($this->queryList[$connectionName]['queries'], $query);
    $this->queryList[$connectionName]['active'] = $query['id'];
    $this->save();
    return $query;
  }

  public function createBlank($connectionName, $defaults = []) {
    return $this->add($connectionName, array_merge([
      'name' => 'NEW',
      'sql' => ''
    ], $defaults));
  }

  public function update($connectionName, $id, $updates) {
    $index = $this->findIndex($connectionName, $id);
    if ($index === false) {
      return false;
    }
    $updates['updatedAt'] = time();
    $this->queryList[$connectionName]['queries'][$index] = array_merge(
      $this->queryList[$connectionName]['queries'][$index],
      $updates
    );
    $this->save();
    return $this->queryList[$connectionName]['queries'][$index];
  }

  public function cloneActive($connectionName) {
    $query = $this->getActive($connectionName);
    if ($query === false) {
      return false;
    }
    unset($query['id'], $query['createdAt'], $query['updatedAt']);
    $query['name'] .= ' copy';
    $query['status'] = 'new';
    $query['result'] = false;
    $query['info'] = [];
    $query['error'] = false;
    return $this->add($connectionName, $query);
  }

  public function deleteActive($connectionName) {
    $activeId = $this->getActiveId($connectionName);
    $index = $this->findIndex($connectionName, $activeId);
    if ($index === false) {
      return false;
    }
    array_splice($this->queryList[$connectionName]['queries'], $index, 1);
    $queries = $this->queryList[$connectionName]['queries'];
    if (empty($queries)) {
      $this->queryList[$connectionName]['active'] = false;
    } else {
      $newIndex = min($index, count($queries) - 1);
      $this->queryList[$connectionName]['active'] = $queries[$newIndex]['id'];
    }
    $this->save();
    return true;
  }

}
