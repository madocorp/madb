<?php

namespace MADB\Main;

use \SPTK\Element;

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
    if (!self::$suppressFocusChange) {
      self::$queryReviewLayout = false;
    }
    self::$editor->removeClass('active-box');
    self::$editor->removeVariant('active');
    self::$editor->removeClass('query-editor-readonly');
    self::$title->removeClass('query-title-readonly');
    self::$title->removeClass('active-title');
    self::applyQueryViewMenu();
    if (!self::$suppressFocusChange) {
      self::applyActiveQueryWorkspaceLayout();
    }
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
    $refreshResult = !self::$suppressFocusChange && self::$resultInfoVisible && !self::$queryReviewLayout;
    if (!self::$suppressFocusChange) {
      self::$queryResultOnlyLayout = false;
      self::$resultQueryEditor = true;
      if (!self::$queryReviewLayout) {
        self::$resultInfoVisible = false;
      }
    }
    self::$result->removeClass('active-box');
    self::$result->removeVariant('active');
    self::$resultStatus->removeVariant('active');
    self::$resultTable->removeVariant('active');
    self::$resultPreview->removeVariant('active');
    self::setResultTableHeaderActive(false);
    if (!self::$suppressFocusChange) {
      self::hideResultFastPreview();
    }
    self::applyResultInfoMenu();
    if ($refreshResult && self::$connectionName !== false) {
      $query = self::$queryList->getActive(self::$connectionName);
      if ($query !== false) {
        self::showQuery($query['id']);
      }
    }
    self::applyQueryViewMenu();
    if (!self::$suppressFocusChange) {
      self::applyActiveQueryWorkspaceLayout();
    }
  }

  /** Switches the active result set shown in the result panel. */
  private static function switchResult($index, bool $preserveReview = false): bool {
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
    if ($preserveReview) {
      self::$queryReviewLayout = true;
      self::withSuppressedFocusChange(function(): void {
        self::activateEditor();
      });
      self::applyActiveQueryWorkspaceLayout();
      Element::refresh();
      return true;
    }
    self::deactivateEditor();
    self::deactivateList();
    self::activateResult();
    Element::refresh();
    return true;
  }

  /** Switches to the latest statement/result that actually reached execution in the active query. */
  private static function switchLatestExecutedStatement(bool $preserveReview = false): bool {
    if (self::$connectionName === false) {
      return false;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false) {
      return false;
    }
    $statements = $query['statements'] ?? [];
    if (is_array($statements) && !empty($statements)) {
      for ($offset = count($statements) - 1; $offset >= 0; $offset--) {
        $statement = $statements[$offset] ?? false;
        if (!is_array($statement) || !in_array(($statement['status'] ?? ''), ['RUNNING', 'OK', 'ERROR'], true)) {
          continue;
        }
        return self::switchResult((int)($statement['index'] ?? $offset), $preserveReview);
      }
      return false;
    }
    $results = $query['results'] ?? [];
    if (is_array($results) && !empty($results)) {
      return self::switchResult(count($results) - 1, $preserveReview);
    }
    return false;
  }

  /** Moves between executed statements/results in the active query. */
  private static function switchExecutedStatementRelative(int $direction, bool $preserveReview = false): bool {
    if (self::$connectionName === false) {
      return false;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false) {
      return false;
    }
    $statements = $query['statements'] ?? [];
    if (is_array($statements) && !empty($statements)) {
      $indexes = [];
      foreach ($statements as $offset => $statement) {
        if (!is_array($statement) || !in_array(($statement['status'] ?? ''), ['RUNNING', 'OK', 'ERROR'], true)) {
          continue;
        }
        $indexes[] = (int)($statement['index'] ?? $offset);
      }
      if (empty($indexes)) {
        return false;
      }
      sort($indexes);
      $active = (int)($query['activeStatement'] ?? $indexes[0]);
      $position = 0;
      foreach ($indexes as $offset => $index) {
        if ($direction > 0 && $index > $active) {
          return self::switchResult($index, $preserveReview);
        }
        if ($index <= $active) {
          $position = $offset;
        }
      }
      $nextPosition = max(0, min(count($indexes) - 1, $position + ($direction < 0 ? -1 : 1)));
      if ($indexes[$nextPosition] === $active) {
        return false;
      }
      return self::switchResult($indexes[$nextPosition], $preserveReview);
    }
    $results = $query['results'] ?? [];
    if (!is_array($results) || empty($results)) {
      return false;
    }
    $active = max(0, min((int)($query['activeResult'] ?? 0), count($results) - 1));
    $next = max(0, min(count($results) - 1, $active + ($direction < 0 ? -1 : 1)));
    return $next === $active ? false : self::switchResult($next, $preserveReview);
  }

  /** Toggles between batch status and active result output. */
  private static function toggleResultStatus(): bool {
    self::$resultInfoVisible = !self::$resultInfoVisible;
    self::applyResultInfoMenu();
    if (self::$connectionName !== false) {
      $query = self::$queryList->getActive(self::$connectionName);
      if ($query !== false) {
        self::showResult($query);
        if (!self::$queryReviewLayout && self::$activeBox !== self::RESULT) {
          self::deactivateEditor();
          self::deactivateList();
          self::activateResult();
        }
      }
    }
    Element::refresh();
    return true;
  }

  /** Restores normal result output after temporary info mode. */
  private static function exitResultInfoMode(): bool {
    if (!self::$resultInfoVisible) {
      return false;
    }
    self::$resultInfoVisible = false;
    self::applyResultInfoMenu();
    if (self::$connectionName !== false) {
      $query = self::$queryList->getActive(self::$connectionName);
      if ($query !== false) {
        self::showQuery($query['id']);
      }
    }
    return true;
  }

  /** Routes result info menu action to the result status toggle. */
  public static function toggleResultInfo($item = null): bool {
    return self::toggleResultStatus();
  }

  /** Toggles review layout for the read-only query editor and its result panel. */
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
      \SPTK\Elements\WarningPanel::forge('No read-only result', 'Execute the query before opening review layout.');
      return false;
    }
    if (self::$queryReviewLayout) {
      self::exitQueryReviewLayout();
      Element::refresh();
      return true;
    }
    self::$queryReviewLayout = true;
    self::deactivateList();
    if (self::$activeBox !== self::EDITOR) {
      self::withSuppressedFocusChange(function(): void {
        self::deactivateResult();
        self::activateEditor();
      });
    } else {
      self::applyActiveQueryWorkspaceLayout();
    }
    Element::refresh();
    return true;
  }

  /** Restores normal result/query geometry after temporary review layout. */
  private static function exitQueryReviewLayout(): bool {
    if (!self::$queryReviewLayout) {
      return false;
    }
    self::$queryReviewLayout = false;
    self::applyResultInfoMenu();
    self::deactivateEditor();
    self::deactivateList();
    self::activateResult();
    if (self::$connectionName !== false) {
      $query = self::$queryList->getActive(self::$connectionName);
      if ($query !== false) {
        self::showQuery($query['id']);
      }
    }
    return true;
  }

  /** Applies the Result > View menu marker for review layout. */
  private static function applyQueryViewMenu(): void {
    $menuItem = Element::byName('menu-query-view');
    if ($menuItem === false || !method_exists($menuItem, 'setLeft')) {
      return;
    }
    $menuItem->setLeft(self::$queryReviewLayout ? 'X' : '');
  }

  /** Moves keyboard focus to the query list. */
  public static function activateList() {
    self::$activeBox = self::LIST;
    self::saveFocus('list');
    self::$list->addClass('active-box');
    self::$list->addVariant('active');
    self::$list->raise();
    self::applyQueryViewMenu();
    if (!self::$suppressFocusChange) {
      self::applyActiveQueryWorkspaceLayout();
    }
  }

  /** Clears query list focus state. */
  public static function deactivateList() {
    self::$list->removeClass('active-box');
    self::$list->removeVariant('active');
    self::applyQueryViewMenu();
    self::applyActiveQueryWorkspaceLayout();
  }

}
