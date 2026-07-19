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

/**
 * Processes background query execution callbacks and updates the status window, result panel, and editor highlights.
 */
trait ScreenExecutionCallbacksTrait {

  /** Runs result through the query workspace. */
  public static function queryResult($response) {
    $connectionName = $response['connection']['name'] ?? self::$connectionName;
    $queryId = $response['queryId'] ?? false;
    if ($connectionName === false || $queryId === false) {
      return;
    }
    if (!empty($response['progress']) && is_array($response['result'] ?? false) && isset($response['result']['statements'])) {
      self::queryBatchProgress($connectionName, $queryId, $response);
      return;
    }
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not execute query', self::formatExecutionError($response['result'] ?? 'Unknown error'));
    }
    if ($response['status'] === 'OK' && is_array($response['result'] ?? false) && isset($response['result']['statements'])) {
      self::queryBatchResult($connectionName, $queryId, $response);
      return;
    }
    $result = $response['status'] === 'OK' ? $response['result'] : false;
    $resultFile = false;
    $query = self::$queryList->get($connectionName, $queryId);
    if (is_array($result) && isset($result['columns'], $result['rowCount'])) {
      $resultFile = $query['resultFile'] ?? ResultStore::relativePath($connectionName, $queryId);
      $result['file'] = $resultFile;
    } else {
      ResultStore::delete($query['resultFile'] ?? false);
    }
    $statements = $response['status'] === 'OK' ? ($query['statements'] ?? []) : self::failRunningStatements($query['statements'] ?? [], self::formatExecutionError($response['result'] ?? 'Unknown error'));
    $isActive = self::$connectionName === $connectionName && self::$queryList->getActiveId($connectionName) === $queryId;
    $updates = [
      'status' => $response['status'] === 'OK' ? 'ok' : 'error',
      'result' => $result,
      'resultFile' => $resultFile,
      'statements' => $statements,
      'results' => [],
      'unseenResult' => !$isActive,
      'error' => $response['status'] === 'OK' ? false : self::formatExecutionError($response['result'] ?? 'Unknown error'),
      'info' => [
        'pid' => $response['pid'] ?? false,
        'times' => $response['times'] ?? []
      ]
    ];
    self::$queryList->update($connectionName, $queryId, $updates);
    if (self::$connectionName === $connectionName) {
      self::renderList();
      if ($isActive) {
        self::showQuery($queryId, false);
      }
      Element::refresh();
    }
  }

  /** Marks statements that never reached the engine as failed after a top-level execution error. */
  private static function failRunningStatements($statements, $error): array {
    $finished = microtime(true);
    foreach (is_array($statements) ? $statements : [] as $index => $statement) {
      if (in_array(($statement['status'] ?? ''), ['PENDING', 'RUNNING'], true)) {
        $started = (float) ($statement['startedAt'] ?? $finished);
        $statements[$index]['status'] = 'ERROR';
        $statements[$index]['error'] = $error;
        $statements[$index]['finishedAt'] = $finished;
        $statements[$index]['time'] = round(max(0, $finished - $started), 4);
      }
    }
    return is_array($statements) ? $statements : [];
  }

  /** Formats top-level execution errors for panels and query status fields. */
  private static function formatExecutionError($error): string {
    if (is_scalar($error) || $error === null) {
      return (string) ($error ?? 'Unknown error');
    }
    $json = json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? 'Unknown error' : $json;
  }

  /** Runs batch progress through the query workspace. */
  private static function queryBatchProgress($connectionName, $queryId, $response): void {
    $query = self::$queryList->get($connectionName, $queryId);
    if ($query === false) {
      return;
    }
    $statements = self::mergeStatements($query['statements'] ?? [], $response['result']['statements'] ?? []);
    $results = self::batchResults($connectionName, $queryId, $statements);
    $activeStatement = self::runningStatementIndex($statements, $query['activeStatement'] ?? 0);
    $activeResult = $query['activeResult'] ?? 0;
    $resultOffset = self::resultOffsetForStatement($results, $activeStatement);
    if ($resultOffset !== false) {
      $activeResult = $resultOffset;
    }
    self::$queryList->update($connectionName, $queryId, [
      'status' => 'running',
      'result' => [
        'statements' => $statements,
        'results' => $results
      ],
      'statements' => $statements,
      'results' => $results,
      'activeResult' => $activeResult,
      'activeStatement' => $activeStatement,
      'info' => [
        'pid' => $response['pid'] ?? false,
        'times' => $response['times'] ?? []
      ]
    ]);
    if (self::$connectionName === $connectionName && self::$queryList->getActiveId($connectionName) === $queryId) {
      self::renderList();
      self::showQuery($queryId, false);
      Element::refresh();
    }
  }

  /** Runs batch result through the query workspace. */
  private static function queryBatchResult($connectionName, $queryId, $response): void {
    $query = self::$queryList->get($connectionName, $queryId);
    if ($query === false) {
      return;
    }
    $statements = self::mergeStatements($query['statements'] ?? [], $response['result']['statements'] ?? []);
    $results = self::batchResults($connectionName, $queryId, $statements);
    $hasError = false;
    foreach ($statements as $statement) {
      if (($statement['status'] ?? '') === 'ERROR') {
        $hasError = true;
      }
    }
    $activeResult = empty($results) ? 0 : count($results) - 1;
    $activeStatement = self::lastReturnedStatementIndex($response['result']['statements'] ?? [], $query['activeStatement'] ?? 0);
    $isActive = self::$connectionName === $connectionName && self::$queryList->getActiveId($connectionName) === $queryId;
    $updates = [
      'status' => $hasError ? 'error' : 'ok',
      'result' => [
        'statements' => $statements,
        'results' => $results
      ],
      'resultFile' => false,
      'statements' => $statements,
      'results' => $results,
      'activeResult' => $activeResult,
      'activeStatement' => $activeStatement,
      'unseenResult' => !$isActive,
      'error' => $hasError ? self::firstBatchError($statements) : false,
      'info' => [
        'pid' => $response['pid'] ?? false,
        'times' => $response['times'] ?? []
      ]
    ];
    self::$queryList->update($connectionName, $queryId, $updates);
    if (self::$connectionName === $connectionName) {
      self::renderList();
      if ($isActive) {
        self::showQuery($queryId, false);
      }
      Element::refresh();
    }
  }

}
