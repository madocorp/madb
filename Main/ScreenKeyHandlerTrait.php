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

/** Handles global key events for query editor shortcuts, focus movement, and result navigation. */
trait ScreenKeyHandlerTrait {

  /** Coordinates key press handler work in the query workspace. */
  public static function keyPressHandler($element, $event) {
    $action = KeyCombo::resolve($event['mod'], $event['scancode'], $event['key']);
    $mod = $event['mod'] ?? 0;
    $key = $event['key'] ?? false;
    $scancode = $event['scancode'] ?? false;
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
      if ($key >= KeyCode::NUM_1 && $key <= KeyCode::NUM_9) {
        return self::switchResult($key - KeyCode::NUM_1);
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
        case KeyCode::E:
          self::supressShortcutTextInput();
          self::editQuery();
          return true;
        case KeyCode::C:
          self::supressShortcutTextInput();
          self::clearQuery();
          return true;
        case KeyCode::S:
          self::supressShortcutTextInput();
          return self::toggleResultStatus();
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

}
