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

/** Handles query tab editing actions from the query menu, including new, clone, rename, pin, format, and revert. */
trait ScreenQueryEditTrait {

  /** Coordinates new query work in the query workspace. */
  public static function newQuery() {
    $connectionName = self::getCurrentConnectionName();
    if ($connectionName === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before creating a query.');
      return;
    }
    self::saveCurrentEditor();
    if (self::$connectionName !== $connectionName) {
      self::loadConnection($connectionName);
    }
    $query = self::$queryList->createBlank($connectionName, [
      'schema' => self::currentSchema(),
      'table' => self::currentTable()
    ]);
    self::renderList();
    self::showQuery($query['id']);
    self::activateFocus('editor');
    self::recalculateWorkArea();
    Element::refresh();
  }

  /** Coordinates clone query work in the query workspace. */
  public static function cloneQuery() {
    if (self::$connectionName === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before cloning a query.');
      return;
    }
    self::saveCurrentEditor();
    $query = self::$queryList->cloneActive(self::$connectionName);
    if ($query !== false) {
      self::renderList();
      self::showQuery($query['id']);
      self::activateFocus('editor');
      self::recalculateWorkArea();
    }
    Element::refresh();
  }

  /** Coordinates rename query work in the query workspace. */
  public static function renameQuery() {
    $query = self::$connectionName === false ? false : self::$queryList->getActive(self::$connectionName);
    if ($query === false) {
      \SPTK\Elements\WarningPanel::forge('No query selected!', 'Please select a query before renaming it.');
      return;
    }
    if (self::isLocked($query)) {
      \SPTK\Elements\WarningPanel::forge('Query is locked', 'This query has already started running and cannot be modified.');
      return;
    }
    self::$renamePanel->setValue(['name' => $query['name'] ?? 'NEW']);
    self::$renamePanel->show();
    Element::refresh();
  }

  /** Coordinates toggle pin query work in the query workspace. */
  public static function togglePinQuery() {
    if (self::$connectionName === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before pinning a query.');
      return;
    }
    $query = self::$queryList->togglePinned(self::$connectionName);
    if ($query !== false) {
      self::renderList();
      self::showQuery($query['id']);
    }
    Element::refresh();
  }

  /** Coordinates insert template work in the query workspace. */
  public static function insertTemplate($item = null) {
    if (self::$connectionName === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before inserting a query template.');
      return;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query !== false && self::isLocked($query)) {
      \SPTK\Elements\WarningPanel::forge('Query is locked', 'This query has already started running and cannot be modified.');
      return;
    }
    $name = is_object($item) ? $item->getValue() : $item;
    if (!isset(self::$templates[$name])) {
      return;
    }
    $text = self::fillTemplate(self::$templates[$name]);
    self::$editor->insertText($text);
    self::saveCurrentEditor();
    self::deactivateList();
    self::deactivateResult();
    self::activateEditor();
    Element::refresh();
  }

  /** Formats query text for the query workspace. */
  public static function formatQuery() {
    if (self::$connectionName === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before formatting a query.');
      return;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query !== false && self::isLocked($query)) {
      \SPTK\Elements\WarningPanel::forge('Query is locked', 'This query has already started running and cannot be modified.');
      return;
    }
    self::$editor->setValue(\MADB\Query\SqlFormatter::format(self::editorText()));
    self::saveCurrentEditor();
    self::activateFocus(self::normalizeFocus('editor', $query));
    Element::refresh();
  }

  /** Coordinates revert query work in the query workspace. */
  public static function revertQuery() {
    if (self::$connectionName === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before reverting a query.');
      return;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false) {
      \SPTK\Elements\WarningPanel::forge('No query selected!', 'Please select a query before reverting it.');
      return;
    }
    if (self::isLocked($query)) {
      \SPTK\Elements\WarningPanel::forge('Query is locked', 'This query has already started running and cannot be modified.');
      return;
    }
    $activeId = $query['id'];
    if (!isset(self::$loadedEditorStates[self::$connectionName][$activeId])) {
      \SPTK\Elements\WarningPanel::forge('No loaded state', 'This query has no loaded editor state to restore.');
      return;
    }
    \SPTK\Elements\WarningPanel::forge(
      'Revert query',
      "Restore query '" . ($query['name'] ?? 'NEW') . "' to the state it had when it was first loaded in this session?",
      [
        ['text' => 'Revert', 'hotKey' => 'RETURN', 'onPress' => '\MADB\Main\QueryEditorController::doRevertQuery'],
        ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close']
      ]
    );
    Element::refresh();
  }

  /** Coordinates do revert query work in the query workspace. */
  public static function doRevertQuery($confirmationPanel) {
    if (self::$connectionName === false) {
      return;
    }
    $activeId = self::$queryList->getActiveId(self::$connectionName);
    if ($activeId === false || !isset(self::$loadedEditorStates[self::$connectionName][$activeId])) {
      return;
    }
    $loaded = self::$loadedEditorStates[self::$connectionName][$activeId];
    $query = self::$queryList->update(self::$connectionName, $activeId, [
      'sql' => $loaded['sql'] ?? ''
    ]);
    self::$editorStates[self::$connectionName][$activeId] = $loaded['state'] ?? false;
    $confirmationPanel->remove();
    if ($query !== false) {
      self::showQuery($activeId);
    }
    self::deactivateList();
    self::deactivateResult();
    self::activateEditor();
    Element::refresh();
  }

}
