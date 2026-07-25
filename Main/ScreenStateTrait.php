<?php

namespace MADB\Main;

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
 * Keeps shared query workspace state such as active connection, schema/table context, editor cursor state, and result files.
 */
trait ScreenStateTrait {

  /** Returns the default directory for query import/export file panels. */
  private static function homePath() {
    $home = getenv('HOME');
    if ($home !== false && $home !== '') {
      return $home;
    }
    return getcwd();
  }

  /** Opens the query file panel panel or view in the query workspace. */
  private static function openQueryFilePanel($path, $create, $callback): void {
    $window = self::$editor->findAncestorByType('Window');
    if ($window === false) {
      \SPTK\Elements\ErrorPanel::forge('Could not open file selector', 'No application window was found.');
      return;
    }
    $panel = new \SPTK\Elements\FilePanel($window);
    $panel->setFileFilter(true);
    $panel->setPath($path);
    $panel->setCreate($create);
    $panel->setOnSelect($callback);
    $panel->show();
    Element::refresh();
  }

  /** Captures editor cursor and selection state for the active query tab. */
  private static function captureEditorState() {
    if (method_exists(self::$editor, 'saveState')) {
      return self::$editor->saveState();
    }
    return false;
  }

  /** Restores editor cursor and selection state for a query tab. */
  private static function restoreEditorState($state): void {
    if ($state !== false && method_exists(self::$editor, 'restoreState')) {
      self::$editor->restoreState($state);
    }
  }

  /** Stores the current editor state before switching query tabs or focus. */
  private static function rememberCurrentEditorState(): void {
    if (self::$queryList === null || self::$connectionName === false) {
      return;
    }
    if (self::$editor !== false && method_exists(self::$editor, 'isDisplayed') && !self::$editor->isDisplayed()) {
      return;
    }
    $connectionName = self::$editorConnectionName !== false ? self::$editorConnectionName : self::$connectionName;
    $queryId = self::$editorQueryId !== false ? self::$editorQueryId : self::$queryList->getActiveId($connectionName);
    if ($connectionName === false || $queryId === false) {
      return;
    }
    self::$editorStates[$connectionName][$queryId] = self::captureEditorState();
  }

  /** Restores normal editor geometry before loading cursor/scroll state into the editor. */
  private static function prepareEditorForStateRestore($query): void {
    if (self::$editor === false || self::$editorContainer === false || self::$resultContainer === false) {
      return;
    }
    self::$editor->show();
    self::$editorContainer->removeClass('query-editor-title-only');
    self::$resultContainer->removeClass('query-result-expanded');
    if ($query !== false && (self::hasResult($query) || (($query['status'] ?? 'new') === 'running' && !empty($query['statements'])))) {
      self::$editorContainer->removeClass('query-editor-full');
    } else {
      self::$editorContainer->addClass('query-editor-full');
    }
    self::recalculateWorkArea();
  }

  /** Creates or returns the active query tab for the current connection. */
  private static function ensureActiveQuery() {
    if (self::$connectionName === false) {
      return false;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query !== false) {
      return $query;
    }
    return self::$queryList->createBlank(self::$connectionName);
  }

  /** Returns the language service for the active or supplied connection. */
  private static function language($connectionName = null): \MADB\Engine\EngineLanguageInterface {
    $engine = null;
    if ($connectionName !== null) {
      $connection = \MADB\Connection\ConnectionList::getInstance()->get($connectionName);
      $engine = \MADB\Engine\EngineRegistry::connectionEngine($connection);
    } else if (self::$connectionName !== false) {
      $connection = \MADB\Connection\ConnectionList::getInstance()->get(self::$connectionName);
      $engine = \MADB\Engine\EngineRegistry::connectionEngine($connection);
    }
    return \MADB\Engine\EngineRegistry::language($engine);
  }

  /** Returns the primary object context for an editor tab or the current navigation selection. */
  private static function currentPrimary($query = []) {
    $primary = \MADB\Table\MenuController::getCurrentSchema();
    if ($primary !== false && $primary !== '') {
      return $primary;
    }
    return $query['primary'] ?? '';
  }

  /** Returns the secondary object context for an editor tab or the current navigation selection. */
  private static function currentSecondary($query = []) {
    $secondary = \MADB\Table\MenuController::getCurrentTable();
    if ($secondary !== false && $secondary !== '') {
      return $secondary;
    }
    return $query['secondary'] ?? '';
  }

  /** Checks has result for query workspace decisions. */
  private static function hasResult($query) {
    $status = $query['status'] ?? 'new';
    return $status === 'ok' || $status === 'error';
  }

  /** Checks is locked for query workspace decisions. */
  private static function isLocked($query) {
    $status = $query['status'] ?? 'new';
    return $status === 'running';
  }

  /** Checks whether query text can be modified in the editor. */
  private static function canEditQueryText($query): bool {
    return $query === false || ($query['status'] ?? 'new') === 'new';
  }

  /** Checks whether the query editor should be read-only for a query. */
  private static function queryEditorReadOnly($query): bool {
    return !self::canEditQueryText($query);
  }

  /** Applies read-only state and active-focus styling to the query editor. */
  private static function applyQueryEditorReadOnly($query): void {
    if (self::$editor === false) {
      return;
    }
    $readOnly = $query !== false && self::queryEditorReadOnly($query);
    if (method_exists(self::$editor, 'setReadOnly')) {
      self::$editor->setReadOnly($readOnly);
    }
    if ($readOnly && self::$activeBox === self::EDITOR) {
      self::$editor->addClass('query-editor-readonly');
      self::$title->addClass('query-title-readonly');
    } else {
      self::$editor->removeClass('query-editor-readonly');
      self::$title->removeClass('query-title-readonly');
    }
    self::applyQueryViewMenu();
  }

  /** Returns the stored result file size for result summaries. */
  private static function resultFileSize($path) {
    $file = ResultStore::absolutePath($path);
    if ($file === false || !file_exists($file)) {
      return 0;
    }
    return filesize($file) ?: 0;
  }

  /** Returns the visible result-set size for title and status display. */
  private static function resultSetSize($query): int {
    $size = self::resultFileSize($query['resultFile'] ?? false);
    foreach (($query['results'] ?? []) as $result) {
      if (is_array($result)) {
        $size += self::resultFileSize($result['file'] ?? false);
      }
    }
    return $size;
  }

  /** Runs duration through the query workspace. */
  private static function queryDuration($query): float {
    $times = $query['info']['times'] ?? [];
    if (!empty($times['s']) && !empty($times['f'])) {
      return (float) $times['f'] - (float) $times['s'];
    }
    $duration = 0.0;
    foreach (($query['statements'] ?? []) as $statement) {
      $duration += (float) ($statement['time'] ?? 0);
    }
    return $duration;
  }

  /** Checks should warn before clear for query workspace decisions. */
  private static function shouldWarnBeforeClear($query): bool {
    return self::resultSetSize($query) > self::CLEAR_WARNING_RESULT_BYTES
      || self::queryDuration($query) > self::CLEAR_WARNING_SECONDS;
  }

  /** Clears query results state from the query workspace. */
  private static function clearQueryResults($query): void {
    ResultStore::delete($query['resultFile'] ?? false);
    ResultStore::deleteMany($query['results'] ?? []);
  }

  /** Prevents shortcut keypresses from leaking into focused text inputs. */
  private static function supressShortcutTextInput(): void {
    if (SDL::$instance !== null) {
      SDL::$instance->supressTextInput();
    }
  }

  /** Checks whether the active query tab currently has a saved result. */
  private static function activeQueryHasResult() {
    if (self::$connectionName === false) {
      return false;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    return $query !== false && self::hasResult($query);
  }

  /** Chooses a valid editor/list/result focus target for the active query. */
  private static function normalizeFocus($focus, $query) {
    if ($focus === 'list') {
      return 'list';
    }
    if ($focus === 'editor') {
      return 'editor';
    }
    if ($query !== false && self::hasResult($query)) {
      return 'result';
    }
    return 'editor';
  }

  /** Activates the requested workspace focus target. */
  private static function activateFocus($focus) {
    self::deactivateEditor();
    self::deactivateResult();
    self::deactivateList();
    switch ($focus) {
      case 'list':
        self::activateList();
        break;
      case 'result':
        self::activateResult();
        break;
      default:
        self::activateEditor();
        break;
    }
  }

  /** Restores main workspace focus when no transient panels remain visible. */
  public static function restoreFocusAfterPanelClose(): void {
    if (self::hasVisiblePanel()) {
      return;
    }
    if (self::$connectionName === false) {
      return;
    }
    self::restoreFocus();
  }

  /** Returns whether any visible SPTK panel still owns focus. */
  private static function hasVisiblePanel(): bool {
    $window = Element::firstByType('Window');
    if ($window === false) {
      return false;
    }
    foreach (['Panel', 'WarningPanel', 'ErrorPanel', 'FilePanel', 'SelectPanel'] as $type) {
      foreach (Element::allByType($type, $window) as $panel) {
        if ($panel->isDisplayed()) {
          return true;
        }
      }
    }
    return false;
  }

  /** Saves focus values from the query workspace panel or state. */
  private static function saveFocus($focus) {
    if (self::$connectionName === false || self::$suppressFocusChange) {
      return;
    }
    self::$queryList->setFocus(self::$connectionName, $focus);
  }

  /** Shows or hides the editor and result areas for the active query state. */
  private static function updateWorkArea($query = false) {
    if (self::$connectionName === false) {
      self::deactivateEditor();
      self::deactivateResult();
      self::deactivateList();
      self::applyQueryEditorReadOnly(false);
      self::$editorContainer->hide();
      self::$resultContainer->hide();
      self::$listContainer->hide();
      return;
    }
    self::$editorContainer->show();
    self::$listContainer->show();
    $showResult = $query !== false && (self::hasResult($query) || (($query['status'] ?? 'new') === 'running' && !empty($query['statements'])));
    if ($showResult) {
      self::$resultContainer->show();
      self::applyResultWorkspaceLayout($query);
      if (!self::$suppressFocusChange && self::$activeBox === self::EDITOR) {
        self::deactivateEditor();
        self::activateResult();
      }
    } else {
      self::$resultContainer->hide();
      self::applyResultWorkspaceLayout(false);
      self::$editorContainer->addClass('query-editor-full');
      if (!self::$suppressFocusChange && self::$activeBox === self::RESULT) {
        self::deactivateResult();
        self::activateEditor();
      }
    }
  }

  /** Applies result/editor split based on result-only view preferences. */
  private static function applyResultWorkspaceLayout($query = false): void {
    if (self::$editor === false || self::$editorContainer === false || self::$resultContainer === false) {
      return;
    }
    $showResult = $query !== false && (self::hasResult($query) || (($query['status'] ?? 'new') === 'running' && !empty($query['statements'])));
    self::$editorContainer->removeClass('query-editor-full');
    self::$editorContainer->removeClass('query-editor-title-only');
    self::$resultContainer->removeClass('query-result-expanded');
    if (!$showResult) {
      self::$editor->show();
      self::$editorContainer->addClass('query-editor-full');
      return;
    }
    if (self::$resultQueryEditor) {
      self::$editor->show();
      return;
    }
    if (self::$activeBox !== self::RESULT || !self::queryHasTableResult($query)) {
      self::$editor->show();
      return;
    }
    self::$editor->hide();
    if (!self::$suppressFocusChange && self::$activeBox === self::EDITOR) {
      self::deactivateEditor();
      self::activateResult();
    }
    if (self::$resultFastPreview && self::queryHasTableResult($query)) {
      return;
    }
    self::$editorContainer->addClass('query-editor-title-only');
    self::$resultContainer->addClass('query-result-expanded');
  }

  /** Reapplies the workspace layout for the active query after focus changes. */
  private static function applyActiveQueryWorkspaceLayout(): void {
    if (self::$connectionName === false || self::$queryList === null) {
      return;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query !== false) {
      self::applyResultWorkspaceLayout($query);
      self::recalculateWorkArea();
    }
  }

  /** Checks whether the active query result can feed the fast preview area. */
  private static function queryHasTableResult($query): bool {
    if (!is_array($query)) {
      return false;
    }
    $result = false;
    $results = $query['results'] ?? [];
    if (is_array($results) && !empty($results)) {
      $activeStatement = $query['activeStatement'] ?? false;
      $entry = false;
      if ($activeStatement !== false) {
        $entry = self::resultForStatement($results, (int)$activeStatement);
      }
      if ($entry === false) {
        $active = max(0, min((int)($query['activeResult'] ?? count($results) - 1), count($results) - 1));
        $entry = $results[$active] ?? false;
      }
      $result = is_array($entry) ? ($entry['result'] ?? false) : false;
    } else {
      $result = $query['result'] ?? false;
    }
    return is_array($result) && isset($result['columns'], $result['rowCount'], $result['file']);
  }

  /** Adds a new editor tab with supplied text and primary/secondary context. */
  public static function addQuery($name, $text, $connection, $primary, $secondary) {
    self::saveCurrentEditor();
    if (self::$connectionName !== $connection) {
      self::loadConnection($connection);
    }
    $query = self::$queryList->add($connection, [
      'name' => $name,
      'text' => $text,
      'primary' => $primary,
      'secondary' => $secondary,
      'status' => 'new'
    ]);
    self::renderList();
    self::showQuery($query['id']);
    self::deactivateList();
    self::activateEditor();
    self::recalculateWorkArea();
    Element::refresh();
    return $query;
  }

}
