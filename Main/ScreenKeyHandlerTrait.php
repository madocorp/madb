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

/** Handles global key events for query editor shortcuts, focus movement, and result navigation. */
trait ScreenKeyHandlerTrait {

  /** Coordinates key press handler work in the query workspace. */
  public static function keyPressHandler($element, $event) {
    $action = KeyCombo::resolve($event['mod'], $event['scancode'], $event['key']);
    $mod = $event['mod'] ?? 0;
    $key = $event['key'] ?? false;
    $scancode = $event['scancode'] ?? false;
    if (
      ($mod & (KeyModifier::CTRL | KeyModifier::SHIFT | KeyModifier::ALT | KeyModifier::GUI)) === 0 &&
      $key === KeyCode::K &&
      self::showInterruptQueryPanel()
    ) {
      self::supressShortcutTextInput();
      return true;
    }
    $readOnlyEditorActive = self::$activeBox === self::EDITOR
      && method_exists(self::$editor, 'getReadOnly')
      && self::$editor->getReadOnly();
    if (
      $readOnlyEditorActive &&
      ($mod & (KeyModifier::CTRL | KeyModifier::SHIFT | KeyModifier::ALT | KeyModifier::GUI)) === 0 &&
      $key === KeyCode::V
    ) {
      self::supressShortcutTextInput();
      return self::toggleQueryView();
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
    if (self::$activeBox !== self::EDITOR && ($mod & (KeyModifier::CTRL | KeyModifier::SHIFT | KeyModifier::ALT | KeyModifier::GUI)) === 0) {
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
        case KeyCode::C:
          self::supressShortcutTextInput();
          self::clearQuery();
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
          self::deactivateEditor();
          self::deactivateResult();
          self::activateList();
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
