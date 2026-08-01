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
      'primary' => self::currentPrimary(),
      'secondary' => self::currentSecondary()
    ]);
    self::renderList();
    self::showQuery($query['id']);
    self::activateFocus('list');
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
    if (method_exists(self::$renamePanel, 'activateInput')) {
      self::$renamePanel->activateInput('name');
    }
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
    if ($query !== false && !self::canEditQueryText($query)) {
      \SPTK\Elements\WarningPanel::forge('Query is locked', 'This query has already started running and cannot be modified.');
      return;
    }
    $name = is_object($item) ? $item->getValue() : $item;
    $template = self::queryTemplate($name);
    if ($template === false) {
      \SPTK\Elements\WarningPanel::forge('Unsupported template', "Template '{$name}' is not available for the current connection.");
      return;
    }
    $schema = self::currentPrimary($query ?? []);
    $table = self::currentSecondary($query ?? []);
    if ($schema !== '' && $table !== '' && (str_contains($template, '[FIELDS]') || str_contains($template, '[PKEY]'))) {
      $connection = \MADB\Connection\ConnectionList::getInstance()->get(self::$connectionName);
      if ($connection !== false) {
        $needsPrimaryKey = str_contains($template, '[PKEY]');
        \MADB\Job\JobHandler::startJob([
          'connection' => $connection,
          'command' => $needsPrimaryKey ? 'rowEditorDefinition' : 'tableFields',
          'arguments' => [$schema, $table],
          'callback' => ['\MADB\Query\QueryEditorController', 'insertTemplateWithFields'],
          'templateName' => $name,
          'schema' => $schema,
          'table' => $table,
          'cache' => ($needsPrimaryKey ? 'RowEditorDefinition:' : 'TableFields:') . $schema . ':' . $table
        ]);
        return;
      }
    }
    self::insertTemplateText($template);
  }

  /** Inserts a template after selected-table fields are loaded. */
  public static function insertTemplateWithFields($response): void {
    if (($response['status'] ?? '') !== 'OK') {
      \SPTK\Elements\WarningPanel::forge('Could not inspect table', $response['result'] ?? 'Could not load table fields.');
      return;
    }
    $template = self::queryTemplate($response['templateName'] ?? '');
    if ($template === false) {
      \SPTK\Elements\WarningPanel::forge('Unsupported template', 'The selected template is not available for the current connection.');
      return;
    }
    $result = $response['result'] ?? null;
    $fields = is_array($result) && isset($result['columns']) ? $result['columns'] : $result;
    self::insertTemplateText($template, $response['schema'] ?? null, $response['table'] ?? null, $fields);
  }

  /** Inserts a filled query template into the editor. */
  private static function insertTemplateText(string $template, $schema = null, $table = null, $fields = null): void {
    $text = self::fillTemplate($template, $schema, $table, $fields);
    $text = str_replace('[LIMIT]', (string)\MADB\App\Settings::defaultSelectLimit(), $text);
    $text = self::language()->format($text);
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
    if ($query !== false && !self::canEditQueryText($query)) {
      \SPTK\Elements\WarningPanel::forge('Query is locked', 'This query has already started running and cannot be modified.');
      return;
    }
    $language = \MADB\Engine\EngineRegistry::language(\MADB\Engine\EngineRegistry::active());
    $text = self::editorText();
    $formatted = $language->format($text);
    if ($formatted === $text && $language instanceof \MADB\Engine\MongoDB\MongoLanguage && $language->lastFormatError() !== false) {
      \SPTK\Elements\WarningPanel::forge(
        'Could not format MongoDB command',
        'MongoDB command JSON is invalid: ' . $language->lastFormatError(),
        [
          ['text' => 'OK', 'hotKey' => 'RETURN', 'onPress' => '\MADB\Query\QueryEditorController::closePanel']
        ]
      );
      return;
    }
    self::$editor->setValue($formatted);
    self::saveCurrentEditor();
    self::activateFocus(self::normalizeFocus('editor', $query));
    Element::refresh();
  }

  /** Keeps the obsolete MongoDB conversion callback harmless. */
  public static function convertMongoToJsonCommand(): void {
    self::formatQuery();
  }

  /** Keeps the obsolete MongoDB conversion callback harmless. */
  public static function convertMongoToPhpDriver(): void {
    self::formatQuery();
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
    if (!self::canEditQueryText($query)) {
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
        ['text' => 'Revert', 'hotKey' => 'RETURN', 'onPress' => '\MADB\Query\QueryEditorController::doRevertQuery'],
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
    $query = self::$queryList->get(self::$connectionName, $activeId);
    if ($query !== false && !self::canEditQueryText($query)) {
      return;
    }
    $loaded = self::$loadedEditorStates[self::$connectionName][$activeId];
    $query = self::$queryList->update(self::$connectionName, $activeId, [
      'text' => $loaded['text'] ?? ''
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
