<?php

namespace MADB\Query;

use \SPTK\SDLWrapper\KeyCombo;
use \SPTK\SDLWrapper\Action;
use \SPTK\SDLWrapper\KeyCode;
use \SPTK\SDLWrapper\KeyModifier;
use \SPTK\SDLWrapper\ScanCode;
use \SPTK\SDLWrapper\SDL;
use \SPTK\Element;
use \MADB\List\QueryList;
use \MADB\Result\ResultStore;
use \MADB\Query\SqlSplitter;

/** Provides statement merge, progress, and batch-result helpers used by query execution callbacks. */
trait ScreenExecutionSupportTrait {

  /** Coordinates merge statements work in the query workspace. */
  private static function mergeStatements($storedStatements, $returnedStatements): array {
    $merged = [];
    foreach (is_array($storedStatements) ? $storedStatements : [] as $statement) {
      $index = (int) ($statement['index'] ?? count($merged));
      $merged[$index] = $statement;
      $merged[$index]['index'] = $index;
    }
    foreach (is_array($returnedStatements) ? $returnedStatements : [] as $statement) {
      $index = (int) ($statement['index'] ?? count($merged));
      $merged[$index] = array_merge($merged[$index] ?? [], $statement, ['index' => $index]);
    }
    ksort($merged);
    return array_values($merged);
  }

  /** Coordinates preserve statement results work in the query workspace. */
  private static function preserveStatementResults($newStatements, $oldStatements, int $activeStatement): array {
    $oldByIndex = [];
    foreach (is_array($oldStatements) ? $oldStatements : [] as $statement) {
      $oldByIndex[(int) ($statement['index'] ?? count($oldByIndex))] = $statement;
    }
    foreach ($newStatements as $offset => $statement) {
      $index = (int) ($statement['index'] ?? $offset);
      if ($index === $activeStatement || !isset($oldByIndex[$index])) {
        continue;
      }
      foreach (['status', 'result', 'resultIndex', 'startedAt', 'time', 'finishedAt', 'error'] as $key) {
        if (array_key_exists($key, $oldByIndex[$index])) {
          $newStatements[$offset][$key] = $oldByIndex[$index][$key];
        }
      }
    }
    return $newStatements;
  }

  /** Coordinates last returned statement index work in the query workspace. */
  private static function lastReturnedStatementIndex($statements, $fallback = 0): int {
    $index = (int) $fallback;
    foreach (is_array($statements) ? $statements : [] as $statement) {
      $index = (int) ($statement['index'] ?? $index);
    }
    return $index;
  }

  /** Runs the query workspace operation. */
  private static function runningStatementIndex($statements, $fallback = 0): int {
    foreach (is_array($statements) ? $statements : [] as $statement) {
      if (($statement['status'] ?? '') === 'RUNNING') {
        return (int) ($statement['index'] ?? $fallback);
      }
    }
    return (int) $fallback;
  }

  /** Coordinates batch results work in the query workspace. */
  private static function batchResults($connectionName, $queryId, $statements): array {
    $results = [];
    foreach ($statements as $statement) {
      if (!isset($statement['resultIndex'], $statement['result']) || !is_array($statement['result'])) {
        continue;
      }
      $resultIndex = (int) $statement['resultIndex'];
      $statementIndex = (int) ($statement['index'] ?? $resultIndex);
      $result = $statement['result'];
      if (isset($result['columns'], $result['rowCount']) && empty($result['file'])) {
        $result['file'] = ResultStore::relativePathForResult($connectionName, $queryId, $resultIndex);
      }
      $results[$statementIndex] = [
        'index' => $statementIndex,
        'resultIndex' => $resultIndex,
        'statementIndex' => $statementIndex,
        'range' => $statement['range'] ?? ['start' => 0, 'end' => 0],
        'result' => $result,
        'file' => $result['file'] ?? false,
        'columns' => $result['columns'] ?? [],
        'rowCount' => $result['rowCount'] ?? (isset($result['rows']) ? count($result['rows']) : 0)
      ];
    }
    ksort($results);
    return array_values($results);
  }

  /** Coordinates first batch error work in the query workspace. */
  private static function firstBatchError($statements) {
    foreach ($statements as $statement) {
      if (($statement['status'] ?? '') === 'ERROR') {
        return $statement['error'] ?? 'Unknown error';
      }
    }
    return false;
  }

}
