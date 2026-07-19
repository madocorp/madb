<?php

namespace MADB\Query;

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

/** Handles query import/export, query rename, search panel closing, and query deletion panels in the workspace. */
trait ScreenQueryFilesTrait {

  /** Coordinates import query work in the query workspace. */
  public static function importQuery() {
    $connectionName = self::getCurrentConnectionName();
    if ($connectionName === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before importing a query.');
      return;
    }
    self::saveCurrentEditor();
    if (self::$connectionName !== $connectionName) {
      self::loadConnection($connectionName);
    }
    self::openQueryFilePanel(self::homePath(), false, ['\MADB\Main\ScreenController', 'doImportQuery']);
  }

  /** Coordinates do import query work in the query workspace. */
  public static function doImportQuery($path) {
    if (!is_file($path) || !is_readable($path)) {
      \SPTK\Elements\ErrorPanel::forge('Could not import query', "The selected file cannot be read:\n{$path}");
      Element::refresh();
      return;
    }
    $sql = file_get_contents($path);
    if ($sql === false) {
      \SPTK\Elements\ErrorPanel::forge('Could not import query', "The selected file could not be loaded:\n{$path}");
      Element::refresh();
      return;
    }
    $connectionName = self::getCurrentConnectionName();
    if ($connectionName === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before importing a query.');
      return;
    }
    if (self::$connectionName !== $connectionName) {
      self::loadConnection($connectionName);
    }
    $query = self::$queryList->add($connectionName, [
      'name' => basename($path),
      'sql' => $sql,
      'schema' => self::currentSchema(),
      'table' => self::currentTable(),
      'status' => 'new',
      'exportFile' => $path
    ]);
    self::renderList();
    self::showQuery($query['id']);
    self::deactivateList();
    self::deactivateResult();
    self::activateEditor();
    Element::refresh();
  }

  /** Coordinates export query work in the query workspace. */
  public static function exportQuery() {
    if (self::$connectionName === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before exporting a query.');
      return;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false) {
      \SPTK\Elements\WarningPanel::forge('No query selected!', 'Please select a query before exporting it.');
      return;
    }
    self::saveCurrentEditor();
    $query = self::$queryList->getActive(self::$connectionName);
    $path = $query['exportFile'] ?? self::homePath();
    self::openQueryFilePanel($path, true, ['\MADB\Main\ScreenController', 'doExportQuery']);
  }

  /** Coordinates do export query work in the query workspace. */
  public static function doExportQuery($path) {
    if (self::$connectionName === false) {
      return;
    }
    $activeId = self::$queryList->getActiveId(self::$connectionName);
    if ($activeId === false) {
      return;
    }
    if (is_dir($path)) {
      \SPTK\Elements\WarningPanel::forge('Missing file name', 'Please enter a file name before exporting the query.');
      Element::refresh();
      return;
    }
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
      \SPTK\Elements\ErrorPanel::forge('Could not export query', "The target directory is not writable:\n{$dir}");
      Element::refresh();
      return;
    }
    if (file_put_contents($path, self::editorText()) === false) {
      \SPTK\Elements\ErrorPanel::forge('Could not export query', "The selected file could not be saved:\n{$path}");
      Element::refresh();
      return;
    }
    self::$queryList->update(self::$connectionName, $activeId, [
      'exportFile' => $path
    ]);
    \SPTK\Elements\Panel::forge('Query exported', "Query saved to:\n{$path}");
    Element::refresh();
  }

  /** Coordinates fill template work in the query workspace. */
  private static function fillTemplate($text, $schema = null, $table = null, $fields = null) {
    if ($schema === null) {
      $schema = self::currentSchema();
    }
    if ($table === null) {
      $table = self::currentTable();
    }
    if ($fields === null) {
      $fields = '[FIELDS]';
    } else {
      $fields = self::formatFieldList($fields);
    }
    $pkey = '[PKEY]';
    return str_replace(
      ['[DB]', '[TABLE]', '[FIELDS]', '[PKEY]'],
      [$schema === '' ? '[DB]' : self::quoteIdentifier($schema), $table === '' ? '[TABLE]' : self::quoteIdentifier($table), $fields, $pkey],
      $text
    );
  }

  /** Escapes identifier for SQL built by the query workspace. */
  private static function quoteIdentifier($identifier) {
    return '`' . str_replace('`', '``', $identifier) . '`';
  }

  /** Formats field list text for the query workspace. */
  private static function formatFieldList($fields) {
    if (!is_array($fields) || empty($fields)) {
      return '*';
    }
    $quoted = [];
    foreach ($fields as $field) {
      $quoted[] = self::quoteIdentifier($field);
    }
    return implode(",\n       ", $quoted);
  }

  /** Saves rename values from the query workspace panel or state. */
  public static function saveRename($panel) {
    $values = $panel->getValue();
    $name = trim($values['name'] ?? '');
    if ($name === '') {
      \SPTK\Elements\WarningPanel::forge('Missing name', 'Please enter a query name.');
      return;
    }
    $activeId = self::$queryList->getActiveId(self::$connectionName);
    $query = self::$queryList->get(self::$connectionName, $activeId);
    if ($query !== false && self::isLocked($query)) {
      \SPTK\Elements\WarningPanel::forge('Query is locked', 'This query has already started running and cannot be modified.');
      return;
    }
    self::$queryList->update(self::$connectionName, $activeId, ['name' => $name]);
    $panel->hide();
    self::renderList();
    self::showQuery($activeId);
    Element::refresh();
  }

  /** Closes the panel panel in the query workspace. */
  public static function closePanel($panel) {
    $panel->hide();
    Element::refresh();
  }

  /** Closes the search panel panel in the query workspace. */
  public static function closeSearchPanel($panel = null) {
    if ($panel !== null) {
      self::$searchPanelState = self::normalizeSearchPanelState($panel->getValue());
    } else if (self::$searchPanel !== null && self::$searchPanel->isDisplayed()) {
      self::$searchPanelState = self::normalizeSearchPanelState(self::$searchPanel->getValue());
    }
    if ($panel !== null) {
      $panel->hide();
    } else if (self::$searchPanel !== null) {
      self::$searchPanel->hide();
    }
    self::clearSearchSession();
    Element::refresh();
  }

  /** Removes query from the query workspace. */
  public static function deleteQuery() {
    if (self::$connectionName === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before deleting a query.');
      return;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false) {
      \SPTK\Elements\WarningPanel::forge('No query selected!', 'Please select a query before deleting it.');
      return;
    }
    \SPTK\Elements\WarningPanel::forge(
      'Delete query',
      "Delete query '" . ($query['name'] ?? 'NEW') . "' and its saved result?",
      [
        ['text' => 'Delete', 'hotKey' => 'RETURN', 'onPress' => '\MADB\List\QueryListController::doDeleteQuery'],
        ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close']
      ]
    );
    Element::refresh();
  }

  /** Coordinates do delete query work in the query workspace. */
  public static function doDeleteQuery($confirmationPanel) {
    if (self::$connectionName === false) {
      return;
    }
    $activeId = self::$queryList->getActiveId(self::$connectionName);
    self::$queryList->deleteActive(self::$connectionName);
    if ($activeId !== false) {
      unset(self::$editorStates[self::$connectionName][$activeId]);
      unset(self::$loadedEditorStates[self::$connectionName][$activeId]);
    }
    if (empty(self::$queryList->getAll(self::$connectionName))) {
      self::$queryList->createBlank(self::$connectionName);
    }
    $confirmationPanel->remove();
    self::renderList();
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query !== false) {
      self::showQuery($query['id']);
    }
    Element::refresh();
  }

}
