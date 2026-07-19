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
 * Starts query execution from the editor and prepares batch state before handing work to the background job system.
 */
trait ScreenExecutionTrait {

  /** Coordinates execute query work in the query workspace. */
  public static function executeQuery() {
    self::confirmExecuteStatements(false);
  }

  /** Coordinates execute current query work in the query workspace. */
  public static function executeCurrentQuery() {
    self::confirmExecuteStatements(true);
  }

  /** Executes an existing query tab by id without opening the normal confirmation prompt. */
  public static function executeQueryById($connectionName, $queryId) {
    $connection = self::getCurrentConnection();
    if ($connection === false || ($connection['name'] ?? false) !== $connectionName) {
      self::loadConnection($connectionName);
    }
    if (self::$connectionName === false || self::$queryList->get(self::$connectionName, $queryId) === false) {
      return;
    }
    self::$queryList->setActive(self::$connectionName, $queryId);
    self::renderList();
    self::showQuery($queryId);
    self::executeStatements(false);
  }

  /** Coordinates do execute query work in the query workspace. */
  public static function doExecuteQuery($confirmationPanel = null) {
    if ($confirmationPanel !== null) {
      $confirmationPanel->remove();
    }
    self::executeStatements(false);
  }

  /** Coordinates do execute current query work in the query workspace. */
  public static function doExecuteCurrentQuery($confirmationPanel = null) {
    if ($confirmationPanel !== null) {
      $confirmationPanel->remove();
    }
    self::executeStatements(true);
  }

  /** Opens or handles the execute statements confirmation step in the query workspace. */
  private static function confirmExecuteStatements($currentOnly) {
    $connection = self::getCurrentConnection();
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before executing a query.');
      return;
    }
    if (self::$connectionName !== $connection['name']) {
      self::loadConnection($connection['name']);
    }
    $query = self::ensureActiveQuery();
    if ($query === false) {
      return;
    }
    if (self::isLocked($query)) {
      \SPTK\Elements\WarningPanel::forge('Query is locked', 'This query has already started running and cannot be executed again.');
      return;
    }
    if (!self::hasResult($query) || !self::shouldWarnBeforeClear($query)) {
      self::executeStatements($currentOnly);
      return;
    }
    \SPTK\Elements\WarningPanel::forge(
      $currentOnly ? 'Execute query' : 'Execute queries',
      "Execute query '" . ($query['name'] ?? 'NEW') . "' and replace its result set?",
      [
        ['text' => 'Execute', 'hotKey' => 'RETURN', 'onPress' => $currentOnly ? '\MADB\Query\QueryExecutionController::doExecuteCurrentQuery' : '\MADB\Query\QueryExecutionController::doExecuteQuery'],
        ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close']
      ]
    );
    Element::refresh();
  }

  /** Coordinates execute statements work in the query workspace. */
  private static function executeStatements($currentOnly) {
    $connection = self::getCurrentConnection();
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before executing a query.');
      return;
    }
    if (self::$connectionName !== $connection['name']) {
      self::loadConnection($connection['name']);
    }
    $query = self::ensureActiveQuery();
    if ($query === false) {
      return;
    }
    if (self::isLocked($query)) {
      \SPTK\Elements\WarningPanel::forge('Query is locked', 'This query has already started running and cannot be executed again.');
      return;
    }
    $sql = self::editorText();
    $allStatements = SqlSplitter::split($sql);
    foreach ($allStatements as $index => $statement) {
      $allStatements[$index]['index'] = $index;
    }
    if (empty($allStatements)) {
      \SPTK\Elements\WarningPanel::forge('Query is empty', 'Please enter a query before executing it.');
      return;
    }
    $activeStatement = 0;
    $statements = $allStatements;
    if ($currentOnly) {
      $statement = SqlSplitter::statementAt($sql, self::byteOffsetFromCursorState($sql, self::captureEditorState()));
      if ($statement === false) {
        \SPTK\Elements\WarningPanel::forge('Query is empty', 'Please enter a query before executing it.');
        return;
      }
      foreach ($allStatements as $index => $candidate) {
        if (($candidate['start'] ?? false) === ($statement['start'] ?? null) && ($candidate['end'] ?? false) === ($statement['end'] ?? null)) {
          $activeStatement = $index;
          break;
        }
      }
      $statements = [$allStatements[$activeStatement]];
    }
    $pendingStatements = [];
    $startedAt = microtime(true);
    foreach ($allStatements as $statement) {
      $index = $statement['index'] ?? count($pendingStatements);
      $willRun = !$currentOnly || $index === $activeStatement;
      $pendingStatements[] = [
        'index' => $index,
        'sql' => trim((string) ($statement['sql'] ?? '')),
        'status' => $willRun ? 'PENDING' : 'NOT RUN',
        'startedAt' => $willRun ? $startedAt : false,
        'range' => [
          'start' => $statement['start'] ?? 0,
          'end' => $statement['end'] ?? 0
        ]
      ];
    }
    self::saveCurrentEditor();
    $query = self::$queryList->getActive(self::$connectionName);
    $schema = self::currentSchema($query);
    $keptResults = [];
    if ($currentOnly) {
      foreach (($query['results'] ?? []) as $result) {
        if ((int) ($result['statementIndex'] ?? -1) === $activeStatement) {
          ResultStore::delete($result['file'] ?? false);
          continue;
        }
        $keptResults[] = $result;
      }
      $pendingStatements = self::preserveStatementResults($pendingStatements, $query['statements'] ?? [], $activeStatement);
    } else {
      ResultStore::delete($query['resultFile'] ?? false);
      ResultStore::deleteMany($query['results'] ?? []);
    }
    $resultFile = ResultStore::relativePath(self::$connectionName, $query['id']);
    $resultFiles = [];
    foreach ($statements as $index => $statement) {
      $resultFileIndex = $currentOnly ? ($statement['index'] ?? $index) : $index;
      $resultFiles[] = ResultStore::absolutePath(ResultStore::relativePathForResult(self::$connectionName, $query['id'], $resultFileIndex));
    }
    $query = self::$queryList->update(self::$connectionName, $query['id'], [
      'status' => 'running',
      'result' => false,
      'resultFile' => $resultFile,
      'statements' => $pendingStatements,
      'results' => $keptResults,
      'activeResult' => 0,
      'activeStatement' => $activeStatement,
      'unseenResult' => false,
      'error' => false,
      'info' => []
    ]);
    self::renderList();
    self::showQuery($query['id']);
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'queryBatch',
      'arguments' => [$statements, $resultFiles, $schema],
      'queryId' => $query['id'],
      'callback' => ['\MADB\Main\ScreenController', 'queryResult']
    ]);
    Element::refresh();
  }

}
