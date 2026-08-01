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

/** Handles query clear/edit confirmation flows for locked or result-bearing query tabs. */
trait ScreenQueryClearTrait {

  /** Clears query state from the query workspace. */
  public static function clearQuery() {
    if (self::$connectionName === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before clearing a query.');
      return;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false) {
      \SPTK\Elements\WarningPanel::forge('No query selected!', 'Please select a query before clearing it.');
      return;
    }
    if (self::isLocked($query)) {
      \SPTK\Elements\WarningPanel::forge(
        'Query is running',
        "This query is marked as running. If MADB was interrupted and the query is no longer running, recover it to clear the stale lock?",
        [
          ['text' => 'Recover', 'hotKey' => 'RETURN', 'onPress' => '\MADB\Query\QueryEditorController::recoverRunningQuery'],
          ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close']
        ]
      );
      return;
    }
    if (!self::shouldWarnBeforeClear($query)) {
      self::doClearQuery();
      return;
    }
    \SPTK\Elements\WarningPanel::forge(
      'Clear query',
      "Clear query '" . ($query['name'] ?? 'NEW') . "' and its result set? This cannot be undone, but Revert can restore the loaded query text.",
      [
        ['text' => 'Clear', 'hotKey' => 'RETURN', 'onPress' => '\MADB\Query\QueryEditorController::doClearQuery'],
        ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close']
      ]
    );
    Element::refresh();
  }

  /** Coordinates edit query work in the query workspace. */
  public static function editQuery() {
    if (self::$connectionName === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before editing a query.');
      return;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false) {
      \SPTK\Elements\WarningPanel::forge('No query selected!', 'Please select a query before editing it.');
      return;
    }
    if (self::isLocked($query)) {
      \SPTK\Elements\WarningPanel::forge('Query is running', 'This query is still running and cannot be edited.');
      return;
    }
    if (!self::shouldWarnBeforeClear($query)) {
      self::doEditQuery();
      return;
    }
    \SPTK\Elements\WarningPanel::forge(
      'Edit query',
      "Edit query '" . ($query['name'] ?? 'NEW') . "' and clear its result set?",
      [
        ['text' => 'Edit', 'hotKey' => 'RETURN', 'onPress' => '\MADB\Query\QueryEditorController::doEditQuery'],
        ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close']
      ]
    );
    Element::refresh();
  }

  /** Coordinates do edit query work in the query workspace. */
  public static function doEditQuery($confirmationPanel = null) {
    if (self::$connectionName === false) {
      return;
    }
    self::saveCurrentEditor();
    $activeId = self::$queryList->getActiveId(self::$connectionName);
    if ($activeId === false) {
      return;
    }
    $query = self::$queryList->get(self::$connectionName, $activeId);
    if ($query !== false && self::isLocked($query)) {
      return;
    }
    if ($query !== false) {
      self::clearQueryResults($query);
    }
    $query = self::$queryList->update(self::$connectionName, $activeId, [
      'status' => 'new',
      'result' => false,
      'resultFile' => false,
      'statements' => [],
      'results' => [],
      'activeResult' => 0,
      'error' => false,
      'info' => []
    ]);
    if ($confirmationPanel !== null) {
      $confirmationPanel->remove();
    }
    if ($query !== false) {
      self::showQuery($activeId);
    }
    self::deactivateList();
    self::deactivateResult();
    self::activateEditor();
    Element::refresh();
  }

  /** Marks the active running query as failed so it can be edited or executed again. */
  public static function recoverRunningQuery($confirmationPanel = null) {
    if (self::$connectionName === false) {
      return;
    }
    $activeId = self::$queryList->getActiveId(self::$connectionName);
    if ($activeId === false) {
      return;
    }
    $query = self::$queryList->get(self::$connectionName, $activeId);
    if ($query === false || !self::isLocked($query)) {
      if ($confirmationPanel !== null) {
        $confirmationPanel->remove();
      }
      return;
    }
    $error = 'Query was marked failed during manual recovery.';
    $finished = microtime(true);
    $statements = $query['statements'] ?? [];
    foreach (is_array($statements) ? $statements : [] as $index => $statement) {
      if (in_array(($statement['status'] ?? ''), ['PENDING', 'RUNNING'], true)) {
        $started = (float) ($statement['startedAt'] ?? $finished);
        $statements[$index]['status'] = 'ERROR';
        $statements[$index]['error'] = $error;
        $statements[$index]['finishedAt'] = $finished;
        $statements[$index]['time'] = round(max(0, $finished - $started), 4);
      }
    }
    $query = self::$queryList->update(self::$connectionName, $activeId, [
      'status' => 'error',
      'result' => false,
      'resultFile' => false,
      'statements' => $statements,
      'results' => [],
      'activeResult' => 0,
      'error' => $error
    ]);
    if ($confirmationPanel !== null) {
      $confirmationPanel->remove();
    }
    if ($query !== false) {
      self::showQuery($activeId, false);
      self::renderList();
    }
    Element::refresh();
  }

  /** Coordinates do clear query work in the query workspace. */
  public static function doClearQuery($confirmationPanel = null) {
    if (self::$connectionName === false) {
      return;
    }
    $activeId = self::$queryList->getActiveId(self::$connectionName);
    if ($activeId === false) {
      return;
    }
    $query = self::$queryList->get(self::$connectionName, $activeId);
    if ($query !== false && self::isLocked($query)) {
      return;
    }
    if ($query !== false) {
      self::clearQueryResults($query);
    }
    $query = self::$queryList->update(self::$connectionName, $activeId, [
      'text' => '',
      'status' => 'new',
      'result' => false,
      'resultFile' => false,
      'statements' => [],
      'results' => [],
      'activeResult' => 0,
      'error' => false,
      'info' => []
    ]);
    unset(self::$editorStates[self::$connectionName][$activeId]);
    if ($confirmationPanel !== null) {
      $confirmationPanel->remove();
    }
    if ($query !== false) {
      self::showQuery($activeId);
    }
    self::deactivateList();
    self::deactivateResult();
    self::activateEditor();
    Element::refresh();
  }

}
