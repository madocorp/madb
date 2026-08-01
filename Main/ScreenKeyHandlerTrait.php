<?php

namespace MADB\Main;

use \SPTK\SDLWrapper\KeyCombo;
use \SPTK\SDLWrapper\Action;
use \SPTK\SDLWrapper\KeyCode;
use \SPTK\SDLWrapper\KeyModifier;
use \SPTK\SDLWrapper\ScanCode;
use \SPTK\Element;

/** Handles global key events for query editor shortcuts, focus movement, and result navigation. */
trait ScreenKeyHandlerTrait {

  /** Coordinates key press handler work in the query workspace. */
  public static function keyPressHandler($element, $event) {
    $action = KeyCombo::resolve($event['mod'], $event['scancode'], $event['key']);
    $mod = $event['mod'] ?? 0;
    $key = $event['key'] ?? false;
    $scancode = $event['scancode'] ?? false;
    $readOnlyEditorActive = self::$activeBox === self::EDITOR
      && method_exists(self::$editor, 'getReadOnly')
      && self::$editor->getReadOnly();
    if (self::$queryReviewLayout) {
      if ($action === Action::CLOSE) {
        self::exitQueryReviewLayout();
        Element::refresh();
        return true;
      }
      if (
        ($mod & (KeyModifier::CTRL | KeyModifier::SHIFT | KeyModifier::ALT | KeyModifier::GUI)) === 0 &&
        $key === KeyCode::V
      ) {
        self::supressShortcutTextInput();
        return self::toggleQueryView();
      }
      if (
        ($mod & (KeyModifier::CTRL | KeyModifier::SHIFT | KeyModifier::ALT | KeyModifier::GUI)) === 0 &&
        $key === KeyCode::NUM_0
      ) {
        self::supressShortcutTextInput();
        self::switchLatestExecutedStatement(true);
        return true;
      }
      if (
        ($mod & (KeyModifier::CTRL | KeyModifier::SHIFT | KeyModifier::ALT | KeyModifier::GUI)) === 0 &&
        $key >= KeyCode::NUM_1 &&
        $key <= KeyCode::NUM_9
      ) {
        self::supressShortcutTextInput();
        self::switchResult($key - KeyCode::NUM_1, true);
        return true;
      }
      if (
        ($mod & KeyModifier::CTRL) !== 0 &&
        ($mod & (KeyModifier::SHIFT | KeyModifier::ALT | KeyModifier::GUI)) === 0 &&
        in_array($action, [Action::SWITCH_UP, Action::SWITCH_DOWN], true)
      ) {
        self::supressShortcutTextInput();
        self::switchExecutedStatementRelative($action === Action::SWITCH_UP ? -1 : 1, true);
        return true;
      }
      self::supressShortcutTextInput();
      return true;
    }
    if (
      $readOnlyEditorActive &&
      ($mod & (KeyModifier::CTRL | KeyModifier::SHIFT | KeyModifier::ALT | KeyModifier::GUI)) === 0 &&
      in_array($key, [KeyCode::V, KeyCode::Q, KeyCode::S], true)
    ) {
      self::supressShortcutTextInput();
      if ($key === KeyCode::V) {
        return self::toggleQueryView();
      }
      return $key === KeyCode::Q ? self::toggleResultQueryEditor() : self::toggleResultStatus();
    }
    if (
      ($readOnlyEditorActive || (self::$activeBox !== self::EDITOR && self::$activeBox !== self::LIST)) &&
      ($mod & (KeyModifier::CTRL | KeyModifier::SHIFT | KeyModifier::ALT | KeyModifier::GUI)) === 0 &&
      $key === KeyCode::NUM_0
    ) {
      self::supressShortcutTextInput();
      return self::switchLatestExecutedStatement();
    }
    if ($scancode === ScanCode::RETURN || $key === KeyCode::RETURN) {
      if ($mod & KeyModifier::CTRL) {
        self::executeQuery();
        return true;
      }
      if ($mod & KeyModifier::SHIFT) {
        self::executeCurrentQuery();
        return true;
      }
    }
    if (self::$activeBox !== self::EDITOR && self::$activeBox !== self::LIST && ($mod & (KeyModifier::CTRL | KeyModifier::SHIFT | KeyModifier::ALT | KeyModifier::GUI)) === 0) {
      if (self::$activeBox === self::RESULT && ($scancode === ScanCode::RETURN || $key === KeyCode::RETURN)) {
        self::supressShortcutTextInput();
        if (self::editActiveMongoDocument()) {
          return true;
        }
        \MADB\Table\RowsController::updateRow();
        return true;
      }
      if (self::$activeBox === self::RESULT && ($scancode === ScanCode::INSERT || $key === KeyCode::INSERT)) {
        self::supressShortcutTextInput();
        if (self::insertActiveMongoDocument()) {
          return true;
        }
        \MADB\Table\RowsController::insertRow();
        return true;
      }
      if (self::$activeBox === self::RESULT && ($scancode === ScanCode::DELETE || $key === KeyCode::DELETE)) {
        self::supressShortcutTextInput();
        \MADB\Table\RowsController::deleteRows();
        return true;
      }
      if ($key >= KeyCode::NUM_1 && $key <= KeyCode::NUM_9) {
        $switched = self::switchResult($key - KeyCode::NUM_1);
        if (self::$activeBox === self::RESULT) {
          self::supressShortcutTextInput();
          return true;
        }
        return $switched;
      }
      switch ($key) {
        case KeyCode::SPACE:
          if (self::$activeBox === self::RESULT) {
            self::supressShortcutTextInput();
            return self::showActiveFieldValue();
          }
          break;
        case KeyCode::R:
          self::supressShortcutTextInput();
          self::executeQuery();
          return true;
        case KeyCode::X:
          self::supressShortcutTextInput();
          self::executeCurrentQuery();
          return true;
        case KeyCode::V:
          self::supressShortcutTextInput();
          return self::toggleQueryView();
        case KeyCode::E:
          self::supressShortcutTextInput();
          self::editQuery();
          return true;
        case KeyCode::F:
          self::supressShortcutTextInput();
          self::searchResult();
          return true;
        case KeyCode::O:
          self::supressShortcutTextInput();
          self::exportResult();
          return true;
        case KeyCode::S:
          self::supressShortcutTextInput();
          return self::toggleResultStatus();
        case KeyCode::P:
          self::supressShortcutTextInput();
          return self::toggleResultFastPreview();
        case KeyCode::Q:
          self::supressShortcutTextInput();
          return self::toggleResultQueryEditor();
        case KeyCode::N:
          self::supressShortcutTextInput();
          return self::toggleResultRowNumbers();
      }
      if (self::$activeBox === self::RESULT && self::isPrintableKey($key)) {
        self::supressShortcutTextInput();
        return true;
      }
    }
    if (
      self::$activeBox !== self::LIST &&
      ($mod & (KeyModifier::CTRL | KeyModifier::SHIFT | KeyModifier::ALT | KeyModifier::GUI)) === 0 &&
      $key === KeyCode::K &&
      self::showInterruptQueryPanel()
    ) {
      self::supressShortcutTextInput();
      return true;
    }
    if (self::$searchSession !== false) {
      switch ($action) {
        case Action::SWITCH_NEXT:
          return self::navigateSearchSession(1);
        case Action::SWITCH_PREVIOUS:
          return self::navigateSearchSession(-1);
        case Action::CLOSE:
          self::closeSearchPanel();
          return true;
      }
    }
    if (self::$resultSearchSession !== false) {
      switch ($action) {
        case Action::SWITCH_NEXT:
          return self::navigateResultSearchSession(1);
        case Action::SWITCH_PREVIOUS:
          return self::navigateResultSearchSession(-1);
        case Action::CLOSE:
          self::clearResultSearchSession(true);
          Element::refresh();
          return true;
      }
    }
    if (
      ($readOnlyEditorActive || (self::$activeBox !== self::EDITOR && self::$activeBox !== self::LIST)) &&
      in_array($action, [Action::SWITCH_UP, Action::SWITCH_DOWN], true)
    ) {
      self::supressShortcutTextInput();
      return self::switchExecutedStatementRelative($action === Action::SWITCH_UP ? -1 : 1);
    }
    if (self::$activeBox === self::LIST && self::$connectionName !== false) {
      if (($event['scancode'] ?? false) === ScanCode::INSERT || ($event['key'] ?? false) === KeyCode::INSERT) {
        self::newQuery();
        return true;
      }
      if ($action === Action::DELETE_FORWARD) {
        self::deleteQuery();
        return true;
      }
      if ($action === Action::DO_IT) {
        self::renameQuery();
        return true;
      }
      if ($action === Action::SELECT_ITEM) {
        self::togglePinQuery();
        return true;
      }
    }
    switch ($action) {
      case Action::CLOSE:
        if (self::exitQueryReviewLayout()) {
          Element::refresh();
          return true;
        }
        if (self::exitResultOnlyLayout()) {
          Element::refresh();
          return true;
        }
        if (self::exitResultInfoMode()) {
          Element::refresh();
          return true;
        }
        if (self::$activeBox === self::RESULT && self::restoreFilteredResult()) {
          Element::refresh();
          return true;
        }
        self::restoreFocus();
        return false;
      case Action::SWITCH_NEXT:
      case Action::SWITCH_PREVIOUS:
      case Action::SWITCH_LEFT:
      case Action::SWITCH_RIGHT:
        if (self::$connectionName === false) {
          return false;
        }
        $mainBox = self::activeQueryHasResult() ? self::RESULT : self::EDITOR;
        if (self::$activeBox === self::LIST) {
          self::deactivateList();
          if ($mainBox === self::RESULT) {
            self::activateResult();
          } else {
            self::activateEditor();
          }
        } else {
          self::withSuppressedFocusChange(function(): void {
            self::deactivateEditor();
            self::deactivateResult();
            self::activateList();
          });
          self::saveFocus('list');
        }
        Element::refresh();
        return true;
    }
    return false;
  }

  /** Checks whether an unhandled keypress can generate a text input event. */
  private static function isPrintableKey($key): bool {
    return is_int($key) && $key >= KeyCode::SPACE && $key <= KeyCode::Z;
  }

}
