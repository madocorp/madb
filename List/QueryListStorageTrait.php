<?php

namespace MADB\List;

/** Loads, saves, normalizes, and initializes query-list storage from the user configuration file. */
trait QueryListStorageTrait {

  /** Initializes query list store state. */
  public function __construct() {
    self::$instance = $this;
    $this->load();
  }

  /** Returns instance data used by the query list store. */
  public static function getInstance() {
    return self::$instance;
  }

  /** Loads load data for the query list store. */
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

  /** Saves save values from the query list store panel or state. */
  public function save() {
    $file = \SPTK\Config::getFilePath($this->fileName);
    \SPTK\Config::save($file, $this->queryList, 'queryList');
  }

  /** Coordinates ensure connection work in the query list store. */
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

  /** Creates id data for the query list store. */
  private function createId() {
    return bin2hex(random_bytes(8));
  }

  /** Normalizes normalize data for query list store comparisons. */
  private function normalize($query) {
    $now = time();
    $normalized = array_merge([
      'id' => $this->createId(),
      'name' => 'NEW',
      'sql' => '',
      'schema' => '',
      'table' => '',
      'status' => 'new',
      'pinned' => false,
      'result' => false,
      'resultFile' => false,
      'statements' => [],
      'results' => [],
      'activeResult' => 0,
      'exportFile' => false,
      'info' => [],
      'error' => false,
      'createdAt' => $now,
      'updatedAt' => $now
    ], is_array($query) ? $query : []);
    unset($normalized['statusVisible']);
    if (($normalized['status'] ?? 'new') === 'running') {
      $normalized = $this->normalizeStaleRunningQuery($normalized);
    }
    return $normalized;
  }

  /** Marks persisted running queries as failed because no worker callback survived app restart. */
  private function normalizeStaleRunningQuery(array $query): array {
    $error = 'Query was interrupted before MADB could receive the execution result.';
    $finished = time();
    foreach (is_array($query['statements'] ?? null) ? $query['statements'] : [] as $index => $statement) {
      if (in_array(($statement['status'] ?? ''), ['PENDING', 'RUNNING'], true)) {
        $started = (float) ($statement['startedAt'] ?? $finished);
        $query['statements'][$index]['status'] = 'ERROR';
        $query['statements'][$index]['error'] = $error;
        $query['statements'][$index]['finishedAt'] = $finished;
        $query['statements'][$index]['time'] = round(max(0, $finished - $started), 4);
      }
    }
    $query['status'] = 'error';
    $query['error'] = $error;
    $query['result'] = false;
    $query['resultFile'] = false;
    $query['results'] = [];
    return $query;
  }

}
