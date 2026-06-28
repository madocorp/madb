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
        'focus' => 'editor',
        'schema' => false,
        'table' => false,
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
      if (!in_array($this->queryList[$connectionName]['focus'] ?? false, ['editor', 'result', 'list'])) {
        $this->queryList[$connectionName]['focus'] = 'editor';
      }
      $this->queryList[$connectionName]['schema'] = $this->queryList[$connectionName]['schema'] ?? false;
      $this->queryList[$connectionName]['table'] = $this->queryList[$connectionName]['table'] ?? false;
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
        'focus' => 'editor',
        'schema' => false,
        'table' => false,
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
      'pinned' => false,
      'result' => false,
      'resultFile' => false,
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

  public function getFocus($connectionName) {
    if (!$this->ensureConnection($connectionName)) {
      return 'editor';
    }
    return $this->queryList[$connectionName]['focus'] ?? 'editor';
  }

  public function setFocus($connectionName, $focus) {
    if (!$this->ensureConnection($connectionName)) {
      return false;
    }
    if (!in_array($focus, ['editor', 'result', 'list'])) {
      return false;
    }
    $this->queryList[$connectionName]['focus'] = $focus;
    $this->save();
    return true;
  }

  public function getSchema($connectionName) {
    if (!$this->ensureConnection($connectionName)) {
      return false;
    }
    return $this->queryList[$connectionName]['schema'] ?? false;
  }

  public function getTable($connectionName) {
    if (!$this->ensureConnection($connectionName)) {
      return false;
    }
    return $this->queryList[$connectionName]['table'] ?? false;
  }

  public function setSchemaAndTable($connectionName, $schema, $table = false) {
    if (!$this->ensureConnection($connectionName)) {
      return false;
    }
    $this->queryList[$connectionName]['schema'] = $schema;
    $this->queryList[$connectionName]['table'] = $table;
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
    $insertAt = $this->getPinnedCount($connectionName);
    array_splice($this->queryList[$connectionName]['queries'], $insertAt, 0, [$query]);
    $this->queryList[$connectionName]['active'] = $query['id'];
    $this->save();
    return $query;
  }

  private function getPinnedCount($connectionName) {
    $count = 0;
    foreach ($this->queryList[$connectionName]['queries'] as $query) {
      if (!empty($query['pinned'])) {
        $count++;
      }
    }
    return $count;
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

  public function sort($connectionName, $order) {
    if (!$this->ensureConnection($connectionName)) {
      return false;
    }
    $byId = [];
    foreach ($this->queryList[$connectionName]['queries'] as $query) {
      $byId[$query['id']] = $query;
    }
    $pinned = [];
    $normal = [];
    foreach ($order as $id) {
      if (isset($byId[$id])) {
        if (!empty($byId[$id]['pinned'])) {
          $pinned[] = $byId[$id];
        } else {
          $normal[] = $byId[$id];
        }
        unset($byId[$id]);
      }
    }
    foreach ($byId as $query) {
      if (!empty($query['pinned'])) {
        $pinned[] = $query;
      } else {
        $normal[] = $query;
      }
    }
    $this->queryList[$connectionName]['queries'] = array_merge($pinned, $normal);
    $this->save();
    return true;
  }

  public function togglePinned($connectionName) {
    $activeId = $this->getActiveId($connectionName);
    $index = $this->findIndex($connectionName, $activeId);
    if ($index === false) {
      return false;
    }
    $query = $this->queryList[$connectionName]['queries'][$index];
    array_splice($this->queryList[$connectionName]['queries'], $index, 1);
    if (empty($query['pinned'])) {
      $query['pinned'] = true;
      $insertAt = $this->getPinnedCount($connectionName);
    } else {
      $query['pinned'] = false;
      $insertAt = $this->getPinnedCount($connectionName);
    }
    array_splice($this->queryList[$connectionName]['queries'], $insertAt, 0, [$query]);
    $this->queryList[$connectionName]['active'] = $query['id'];
    $this->save();
    return $query;
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
    $query['resultFile'] = false;
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
    \MADB\Query\ResultStore::delete($this->queryList[$connectionName]['queries'][$index]['resultFile'] ?? false);
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
