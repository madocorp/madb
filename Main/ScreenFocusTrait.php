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

/** Controls keyboard focus between the query editor, query list, and result panel in the main workspace. */
trait ScreenFocusTrait {

  /** Moves keyboard focus to the query editor. */
  public static function activateEditor() {
    self::$activeBox = self::EDITOR;
    self::saveFocus('editor');
    self::$editor->addClass('active-box');
    self::$editor->addVariant('active');
    self::$title->addClass('active-title');
    self::$editor->raise();
  }

  /** Clears query editor focus state. */
  public static function deactivateEditor() {
    self::$editor->removeClass('active-box');
    self::$editor->removeVariant('active');
    self::$title->removeClass('active-title');
  }

  /** Moves keyboard focus to the result panel. */
  public static function activateResult() {
    self::$activeBox = self::RESULT;
    self::saveFocus('result');
    self::$result->addClass('active-box');
    self::$result->addVariant('active');
    self::setResultTableHeaderActive(true);
    self::$result->raise();
  }

  /** Clears result panel focus state. */
  public static function deactivateResult() {
    self::$result->removeClass('active-box');
    self::$result->removeVariant('active');
    self::setResultTableHeaderActive(false);
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
    $updates = [
      'statusVisible' => false
    ];
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
    if (self::$connectionName === false) {
      return false;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false || empty($query['statements']) || !is_array($query['statements'])) {
      return false;
    }
    $query = self::$queryList->update(self::$connectionName, $query['id'], [
      'statusVisible' => empty($query['statusVisible'])
    ]);
    self::showQuery($query['id']);
    self::deactivateEditor();
    self::deactivateList();
    self::activateResult();
    Element::refresh();
    return true;
  }

  /** Routes result info menu action to the result status toggle. */
  public static function toggleResultInfo($item = null): bool {
    return self::toggleResultStatus();
  }

  /** Moves keyboard focus to the query list. */
  public static function activateList() {
    self::$activeBox = self::LIST;
    self::saveFocus('list');
    self::$list->addClass('active-box');
    self::$list->addVariant('active');
    self::$list->raise();
  }

  /** Clears query list focus state. */
  public static function deactivateList() {
    self::$list->removeClass('active-box');
    self::$list->removeVariant('active');
  }

}
