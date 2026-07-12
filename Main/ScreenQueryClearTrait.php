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
      \SPTK\Elements\WarningPanel::forge('Query is running', 'This query is still running and cannot be cleared.');
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
        ['text' => 'Clear', 'hotKey' => 'RETURN', 'onPress' => '\MADB\Main\QueryEditorController::doClearQuery'],
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
        ['text' => 'Edit', 'hotKey' => 'RETURN', 'onPress' => '\MADB\Main\QueryEditorController::doEditQuery'],
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
      'sql' => '',
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
