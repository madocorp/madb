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

/** Controls keyboard focus between the query editor, query list, and result panel in the main workspace. */
trait ScreenFocusTrait {

  /** Moves keyboard focus to the query editor. */
  public static function activateEditor() {
    self::$activeBox = self::EDITOR;
    self::saveFocus('editor');
    self::$editor->addClass('active-box');
    self::$editor->addVariant('active');
    self::$title->addClass('active-title');
    if (method_exists(self::$editor, 'getReadOnly') && self::$editor->getReadOnly()) {
      self::$editor->addClass('query-editor-readonly');
      self::$title->addClass('query-title-readonly');
    }
    self::$editor->raise();
    self::applyQueryViewMenu();
    self::applyActiveQueryWorkspaceLayout();
  }

  /** Clears query editor focus state. */
  public static function deactivateEditor() {
    self::$editor->removeClass('active-box');
    self::$editor->removeVariant('active');
    self::$editor->removeClass('query-editor-readonly');
    self::$title->removeClass('query-title-readonly');
    self::$title->removeClass('active-title');
    self::applyQueryViewMenu();
    self::applyActiveQueryWorkspaceLayout();
  }

  /** Moves keyboard focus to the result panel. */
  public static function activateResult() {
    self::$activeBox = self::RESULT;
    self::saveFocus('result');
    self::$result->addClass('active-box');
    self::$result->addVariant('active');
    self::$resultStatus->addVariant('active');
    self::$resultTable->addVariant('active');
    self::$resultPreview->addVariant('active');
    self::setResultTableHeaderActive(true);
    self::$result->raise();
    self::applyQueryViewMenu();
    self::applyActiveQueryWorkspaceLayout();
    self::syncResultFastPreview();
  }

  /** Clears result panel focus state. */
  public static function deactivateResult() {
    self::$result->removeClass('active-box');
    self::$result->removeVariant('active');
    self::$resultStatus->removeVariant('active');
    self::$resultTable->removeVariant('active');
    self::$resultPreview->removeVariant('active');
    self::setResultTableHeaderActive(false);
    self::hideResultFastPreview();
    self::applyQueryViewMenu();
    self::applyActiveQueryWorkspaceLayout();
  }

  /** Switches the active result set shown in the result panel. */
  private static function switchResult($index): bool {
    if (self::$connectionName === false) {
      return false;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false) {
      return false;
    }
    $index = (int) $index;
    $statements = $query['statements'] ?? [];
    $updates = [];
    if (is_array($statements) && !empty($statements)) {
      if (self::statementByIndex($statements, $index) === false) {
        return false;
      }
      $updates['activeStatement'] = $index;
      $resultOffset = self::resultOffsetForStatement($query['results'] ?? [], $index);
      if ($resultOffset !== false) {
        $updates['activeResult'] = $resultOffset;
      }
    } else {
      if (empty($query['results']) || !is_array($query['results']) || $index < 0 || $index >= count($query['results'])) {
        return false;
      }
      $updates['activeResult'] = $index;
    }
    $query = self::$queryList->update(self::$connectionName, $query['id'], $updates);
    self::showQuery($query['id']);
    self::deactivateEditor();
    self::deactivateList();
    self::activateResult();
    Element::refresh();
    return true;
  }

  /** Toggles between batch status and active result output. */
  private static function toggleResultStatus(): bool {
    self::$resultInfoVisible = !self::$resultInfoVisible;
    self::saveResultInfoSetting();
    self::applyResultInfoMenu();
    if (self::$connectionName !== false) {
      $query = self::$queryList->getActive(self::$connectionName);
      if ($query !== false) {
        self::showQuery($query['id']);
        self::deactivateEditor();
        self::deactivateList();
        self::activateResult();
      }
    }
    Element::refresh();
    return true;
  }

  /** Routes result info menu action to the result status toggle. */
  public static function toggleResultInfo($item = null): bool {
    return self::toggleResultStatus();
  }

  /** Toggles focus between the read-only query editor and its result panel. */
  public static function toggleQueryView($item = null): bool {
    if (self::$connectionName === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before toggling the query view.');
      return false;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false) {
      \SPTK\Elements\WarningPanel::forge('No query selected!', 'Please select a query before toggling the query view.');
      return false;
    }
    $hasResultArea = self::hasResult($query) || (($query['status'] ?? 'new') === 'running' && !empty($query['statements']));
    if (!$hasResultArea || !self::queryEditorReadOnly($query)) {
      \SPTK\Elements\WarningPanel::forge('No read-only result', 'Execute the query before toggling between the read-only editor and result.');
      return false;
    }
    self::deactivateList();
    if (self::$activeBox === self::EDITOR) {
      self::deactivateEditor();
      self::activateResult();
    } else {
      self::deactivateResult();
      self::activateEditor();
    }
    Element::refresh();
    return true;
  }

  /** Applies the Result > View menu marker for read-only editor focus. */
  private static function applyQueryViewMenu(): void {
    $menuItem = Element::byName('menu-query-view');
    if ($menuItem === false || !method_exists($menuItem, 'setLeft')) {
      return;
    }
    $active = self::$activeBox === self::EDITOR
      && self::$editor !== false
      && method_exists(self::$editor, 'getReadOnly')
      && self::$editor->getReadOnly();
    $menuItem->setLeft($active ? 'X' : '');
  }

  /** Moves keyboard focus to the query list. */
  public static function activateList() {
    self::$activeBox = self::LIST;
    self::saveFocus('list');
    self::$list->addClass('active-box');
    self::$list->addVariant('active');
    self::$list->raise();
    self::applyQueryViewMenu();
    self::applyActiveQueryWorkspaceLayout();
  }

  /** Clears query list focus state. */
  public static function deactivateList() {
    self::$list->removeClass('active-box');
    self::$list->removeVariant('active');
    self::applyQueryViewMenu();
    self::applyActiveQueryWorkspaceLayout();
  }

}
