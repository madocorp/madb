<?php

namespace MADB\List;

/** Reads active query, focus, schema/table context, and query records from the per-connection query list. */
trait QueryListAccessTrait {

  /** Returns all data used by the query list store. */
  public function getAll($connectionName) {
    if (!$this->ensureConnection($connectionName)) {
      return [];
    }
    return $this->queryList[$connectionName]['queries'];
  }

  /** Returns saved query count for a connection without creating a new connection entry. */
  public function countForConnection($connectionName): int {
    if ($connectionName === false || $connectionName === '' || !isset($this->queryList[$connectionName])) {
      return 0;
    }
    return count($this->queryList[$connectionName]['queries'] ?? []);
  }

  /** Returns active id data used by the query list store. */
  public function getActiveId($connectionName) {
    if (!$this->ensureConnection($connectionName)) {
      return false;
    }
    return $this->queryList[$connectionName]['active'];
  }

  /** Applies active values to query list store state or controls. */
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

  /** Returns focus data used by the query list store. */
  public function getFocus($connectionName) {
    if (!$this->ensureConnection($connectionName)) {
      return 'editor';
    }
    return $this->queryList[$connectionName]['focus'] ?? 'editor';
  }

  /** Applies focus values to query list store state or controls. */
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

  /** Returns primary object data used by the query list store. */
  public function getPrimary($connectionName) {
    if (!$this->ensureConnection($connectionName)) {
      return false;
    }
    return $this->queryList[$connectionName]['primary'] ?? false;
  }

  /** Returns secondary object data used by the query list store. */
  public function getSecondary($connectionName) {
    if (!$this->ensureConnection($connectionName)) {
      return false;
    }
    return $this->queryList[$connectionName]['secondary'] ?? false;
  }

  /** Applies primary and secondary object values to query list store state or controls. */
  public function setPrimaryAndSecondary($connectionName, $primary, $secondary = false) {
    if (!$this->ensureConnection($connectionName)) {
      return false;
    }
    $this->queryList[$connectionName]['primary'] = $primary;
    $this->queryList[$connectionName]['secondary'] = $secondary;
    $this->save();
    return true;
  }

  /** Returns active data used by the query list store. */
  public function getActive($connectionName) {
    $id = $this->getActiveId($connectionName);
    if ($id === false) {
      return false;
    }
    return $this->get($connectionName, $id);
  }

  /** Returns get data used by the query list store. */
  public function get($connectionName, $id) {
    $index = $this->findIndex($connectionName, $id);
    if ($index === false) {
      return false;
    }
    return $this->queryList[$connectionName]['queries'][$index];
  }

  /** Finds index data inside the query list store. */
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

  /** Coordinates add work in the query list store. */
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

}
