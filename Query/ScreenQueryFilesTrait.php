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
      'text' => $sql,
      'primary' => self::currentPrimary(),
      'secondary' => self::currentSecondary(),
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
  private static function fillTemplate($text, $schema = null, $table = null, $fields = null, $engineType = null) {
    if ($schema === null) {
      $schema = self::currentPrimary();
    }
    if ($table === null) {
      $table = self::currentSecondary();
    }
    if ($engineType === null) {
      $engineType = self::currentEngineType();
    }
    return \MADB\Engine\EngineRegistry::language($engineType)->fillTemplate($text, $schema, $table, $fields);
  }

  /** Returns a named query template for the current or supplied connection. */
  private static function queryTemplate($name, $connectionName = null) {
    $engineType = $connectionName === null ? self::currentEngineType() : self::connectionEngineType($connectionName);
    return \MADB\Engine\EngineRegistry::language($engineType)->template((string)$name);
  }

  /** Returns the active connection engine type for template selection. */
  private static function currentEngineType(): string {
    if (self::$connectionName !== false) {
      return self::connectionEngineType(self::$connectionName);
    }
    $connection = \MADB\Connection\ConnectionList::getInstance()->current;
    return (string)($connection['engine'] ?? \MADB\Engine\EngineRegistry::active());
  }

  /** Returns the engine type for a connection name. */
  private static function connectionEngineType($connectionName): string {
    $connection = \MADB\Connection\ConnectionList::getInstance()->get($connectionName);
    return (string)($connection['engine'] ?? \MADB\Engine\EngineRegistry::active());
  }

  /** Escapes identifier for SQL built by the query workspace. */
  private static function quoteIdentifier($identifier, $engineType = null) {
    if ($engineType === null) {
      $engineType = self::currentEngineType();
    }
    if ($engineType === 'SQLite') {
      return '"' . str_replace('"', '""', $identifier) . '"';
    }
    return '`' . str_replace('`', '``', $identifier) . '`';
  }

  /** Formats field list text for the query workspace. */
  private static function formatFieldList($fields, $engineType = null) {
    if (!is_array($fields) || empty($fields)) {
      return '*';
    }
    $quoted = [];
    foreach ($fields as $field) {
      if (is_array($field)) {
        $field = $field['COLUMN_NAME'] ?? '';
      }
      if ($field !== '') {
        $quoted[] = self::quoteIdentifier((string)$field, $engineType);
      }
    }
    return empty($quoted) ? '*' : implode(",\n       ", $quoted);
  }

  /** Formats a primary-key condition placeholder for query templates. */
  private static function primaryKeyTemplateCondition($fields, $engineType = null): string {
    if (!is_array($fields) || empty($fields)) {
      return '[PKEY]';
    }
    $conditions = [];
    foreach ($fields as $field) {
      if (!is_array($field) || ($field['COLUMN_KEY'] ?? '') !== 'PRI') {
        continue;
      }
      $name = (string)($field['COLUMN_NAME'] ?? '');
      if ($name !== '') {
        $conditions[] = self::quoteIdentifier($name, $engineType) . ' = -1';
      }
    }
    return empty($conditions) ? '[PKEY]' : implode(' AND ', $conditions);
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
    $query = self::$queryList->update(self::$connectionName, $activeId, ['name' => $name]);
    $panel->hide();
    self::renderList();
    self::showQuery($activeId);
    if ($query !== false) {
      self::activateFocus(self::normalizeFocus(self::$queryList->getFocus(self::$connectionName), $query));
    }
    Element::refresh();
  }

  /** Closes the panel panel in the query workspace. */
  public static function closePanel($panel) {
    $panel->hide();
    self::restoreFocusAfterPanelClose();
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
