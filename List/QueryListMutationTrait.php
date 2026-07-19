<?php

namespace MADB\List;

/** Mutates query tabs by creating, updating, sorting, pinning, cloning, and deleting them. */
trait QueryListMutationTrait {

  /** Returns pinned count data used by the query list store. */
  private function getPinnedCount($connectionName) {
    $count = 0;
    foreach ($this->queryList[$connectionName]['queries'] as $query) {
      if (!empty($query['pinned'])) {
        $count++;
      }
    }
    return $count;
  }

  /** Creates blank data for the query list store. */
  public function createBlank($connectionName, $defaults = []) {
    return $this->add($connectionName, array_merge([
      'name' => 'NEW',
      'sql' => ''
    ], $defaults));
  }

  /** Coordinates update work in the query list store. */
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

  /** Coordinates sort work in the query list store. */
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

  /** Coordinates toggle pinned work in the query list store. */
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

  /** Coordinates clone active work in the query list store. */
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
    $query['statements'] = [];
    $query['results'] = [];
    $query['activeResult'] = 0;
    unset($query['statusVisible']);
    $query['exportFile'] = false;
    $query['info'] = [];
    $query['error'] = false;
    return $this->add($connectionName, $query);
  }

  /** Removes active from the query list store. */
  public function deleteActive($connectionName) {
    $activeId = $this->getActiveId($connectionName);
    $index = $this->findIndex($connectionName, $activeId);
    if ($index === false) {
      return false;
    }
    $query = $this->queryList[$connectionName]['queries'][$index];
    \MADB\Result\ResultStore::delete($query['resultFile'] ?? false);
    \MADB\Result\ResultStore::deleteMany($query['results'] ?? []);
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

  /** Deletes all saved queries and result files for a connection. */
  public function deleteConnection($connectionName): bool {
    if ($connectionName === false || $connectionName === '' || !isset($this->queryList[$connectionName])) {
      return false;
    }
    foreach ($this->queryList[$connectionName]['queries'] ?? [] as $query) {
      \MADB\Result\ResultStore::delete($query['resultFile'] ?? false);
      \MADB\Result\ResultStore::deleteMany($query['results'] ?? []);
    }
    unset($this->queryList[$connectionName]);
    $this->save();
    return true;
  }

}
