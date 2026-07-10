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
    $activeId = self::$queryList->getActiveId(self::$connectionName);
    if ($activeId === false) {
      return;
    }
    self::$editorStates[self::$connectionName][$activeId] = self::captureEditorState();
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

  /** Returns the schema context for a query or the current query list selection. */
  private static function currentSchema($query = []) {
    $schema = \MADB\Table\MenuController::getCurrentSchema();
    if ($schema !== false && $schema !== '') {
      return $schema;
    }
    return $query['schema'] ?? '';
  }

  /** Returns the table context for a query or the current query list selection. */
  private static function currentTable($query = []) {
    $table = \MADB\Table\MenuController::getCurrentTable();
    if ($table !== false && $table !== '') {
      return $table;
    }
    return $query['table'] ?? '';
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
      self::$editorContainer->hide();
      self::$resultContainer->hide();
      self::$listContainer->hide();
      return;
    }
    self::$editorContainer->show();
    self::$listContainer->show();
    $showResult = $query !== false && (self::hasResult($query) || (($query['status'] ?? 'new') === 'running' && !empty($query['statements'])));
    if ($showResult) {
      self::$editorContainer->removeClass('query-editor-full');
      self::$resultContainer->show();
      if (!self::$suppressFocusChange && self::$activeBox === self::EDITOR) {
        self::deactivateEditor();
        self::activateResult();
      }
    } else {
      self::$resultContainer->hide();
      self::$editorContainer->addClass('query-editor-full');
      if (!self::$suppressFocusChange && self::$activeBox === self::RESULT) {
        self::deactivateResult();
        self::activateEditor();
      }
    }
  }

  /** Adds a new query tab with supplied SQL and schema/table context. */
  public static function addQuery($name, $sql, $connection, $schema, $table) {
    self::saveCurrentEditor();
    if (self::$connectionName !== $connection) {
      self::loadConnection($connection);
    }
    $query = self::$queryList->add($connection, [
      'name' => $name,
      'sql' => $sql,
      'schema' => $schema,
      'table' => $table,
      'status' => 'new'
    ]);
    self::renderList();
    self::showQuery($query['id']);
    self::deactivateList();
    self::activateEditor();
    Element::refresh();
    return $query;
  }

}
