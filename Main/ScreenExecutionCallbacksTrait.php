<?php

namespace MADB\Main;

use \SPTK\SDLWrapper\KeyCombo;
use \SPTK\SDLWrapper\Action;
use \SPTK\SDLWrapper\KeyCode;
use \SPTK\SDLWrapper\KeyModifier;
use \SPTK\SDLWrapper\ScanCode;
use \SPTK\SDLWrapper\SDL;
use \SPTK\Element;
use \MADB\Query\QueryList;
use \MADB\Query\ResultStore;
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
    if ($response['status'] === 'OK' && is_array($response['result'] ?? false) && isset($response['result']['statements'])) {
      self::queryBatchResult($connectionName, $queryId, $response);
      return;
    }
    $result = $response['status'] === 'OK' ? $response['result'] : false;
    $resultFile = false;
    if (is_array($result) && isset($result['columns'], $result['rowCount'])) {
      $query = self::$queryList->get($connectionName, $queryId);
      $resultFile = $query['resultFile'] ?? ResultStore::relativePath($connectionName, $queryId);
      $result['file'] = $resultFile;
    } else {
      $query = self::$queryList->get($connectionName, $queryId);
      ResultStore::delete($query['resultFile'] ?? false);
    }
    $isActive = self::$connectionName === $connectionName && self::$queryList->getActiveId($connectionName) === $queryId;
    $updates = [
      'status' => $response['status'] === 'OK' ? 'ok' : 'error',
      'result' => $result,
      'resultFile' => $resultFile,
      'unseenResult' => !$isActive,
      'error' => $response['status'] === 'OK' ? false : $response['result'],
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
      'statusVisible' => true,
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
      'statusVisible' => empty($results),
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
