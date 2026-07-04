<?php

namespace MADB\Main;

use \SPTK\SDLWrapper\KeyCombo;
use \SPTK\SDLWrapper\Action;
use \SPTK\SDLWrapper\KeyCode;
use \SPTK\SDLWrapper\ScanCode;
use \SPTK\Element;
use \MADB\Query\QueryList;
use \MADB\Query\ResultStore;

class ScreenController {

  const EDITOR = 0;
  const RESULT = 1;
  const LIST = 2;

  private static $activeBox = self::EDITOR;
  private static $editorContainer;
  private static $resultContainer;
  private static $listContainer;
  private static $editor;
  private static $title;
  private static $result;
  private static $resultMessage;
  private static $resultTable;
  private static $list;
  private static $connectionInfo;
  private static $queryName;
  private static $renamePanel;
  private static $queryList;
  private static $connectionName = false;
  private static $updatingList = false;
  private static $suppressFocusChange = false;
  private static $editorStates = [];
  private static $loadedEditorStates = [];
  private static $templates = [
    'SELECT current' => "SELECT [FIELDS]\nFROM [DB].[TABLE]\nWHERE 1\nLIMIT 1000;\n",
    'SELECT all' => "SELECT *\nFROM [DB].[TABLE]\nWHERE 1\nLIMIT 1000;\n",
    'INSERT' => "INSERT INTO [DB].[TABLE]\n([FIELDS])\nVALUES();\n",
    'UPDATE' => "UPDATE [DB].[TABLE]\nSET `field` = ''\nWHERE [PKEY] = -1;\n",
    'ON DUPLICATE' => "ON DUPLICATE KEY UPDATE `field` = ''\n",
    'JOIN' => "INNER JOIN [DB].[TABLE] AS `T` ON [PKEY] = `T`.`Id`\n",
    'DELETE' => "DELETE FROM [DB].[TABLE] WHERE [PKEY] = -1;\n",
    'GROUP CONCAT MAX LENGTH' => "SET SESSION group_concat_max_len = 1000000;\n"
  ];

  public static function init() {
    self::$editorContainer = Element::byName('query-editor-container');
    self::$resultContainer = Element::byName('query-result-container');
    self::$listContainer = Element::byName('query-list-container');
    self::$editor = Element::byName('query-editor');
    self::$title = Element::byName('query-title');
    self::$result = Element::byName('query-result');
    self::$resultMessage = Element::byName('query-result-message');
    self::$resultTable = Element::byName('query-result-table');
    self::$list = Element::byName('query-list');
    self::$connectionInfo = Element::byName('connection-info');
    self::$queryName = Element::byName('query-name');
    self::$renamePanel = Element::byName('query-rename');
    self::$queryList = QueryList::getInstance();
    self::$list->clear();
    self::$list->setOnChange('\MADB\Main\ScreenController::selectQueryFromList');
    self::loadConnection(false);
  }

  private static function getCurrentConnection() {
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    return $connectionList->current;
  }

  private static function getCurrentConnectionName() {
    $connection = self::getCurrentConnection();
    if ($connection === false) {
      return false;
    }
    return $connection['name'];
  }

  private static function editorText() {
    $value = self::$editor->getValue();
    if (is_array($value)) {
      return implode("\n", $value);
    }
    return (string) $value;
  }

  private static function captureEditorState() {
    if (method_exists(self::$editor, 'saveState')) {
      return self::$editor->saveState();
    }
    return false;
  }

  private static function restoreEditorState($state): void {
    if ($state !== false && method_exists(self::$editor, 'restoreState')) {
      self::$editor->restoreState($state);
    }
  }

  private static function rememberCurrentEditorState(): void {
    if (self::$queryList === null || self::$connectionName === false) {
      return;
    }
    $activeId = self::$queryList->getActiveId(self::$connectionName);
    if ($activeId === false) {
      return;
    }
    self::$editorStates[self::$connectionName][$activeId] = self::captureEditorState();
  }

  private static function ensureActiveQuery() {
    if (self::$connectionName === false) {
      return false;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query !== false) {
      return $query;
    }
    return self::$queryList->createBlank(self::$connectionName);
  }

  private static function currentSchema($query = []) {
    $schema = \MADB\Table\MenuController::getCurrentSchema();
    if ($schema !== false && $schema !== '') {
      return $schema;
    }
    return $query['schema'] ?? '';
  }

  private static function currentTable($query = []) {
    $table = \MADB\Table\MenuController::getCurrentTable();
    if ($table !== false && $table !== '') {
      return $table;
    }
    return $query['table'] ?? '';
  }

  private static function hasResult($query) {
    $status = $query['status'] ?? 'new';
    return $status === 'ok' || $status === 'error';
  }

  private static function isLocked($query) {
    $status = $query['status'] ?? 'new';
    return $status === 'running' || self::hasResult($query);
  }

  private static function activeQueryHasResult() {
    if (self::$connectionName === false) {
      return false;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    return $query !== false && self::hasResult($query);
  }

  private static function normalizeFocus($focus, $query) {
    if ($focus === 'list') {
      return 'list';
    }
    if ($query !== false && self::hasResult($query)) {
      return 'result';
    }
    return 'editor';
  }

  private static function activateFocus($focus) {
    self::deactivateEditor();
    self::deactivateResult();
    self::deactivateList();
    switch ($focus) {
      case 'list':
        self::activateList();
        break;
      case 'result':
        self::activateResult();
        break;
      default:
        self::activateEditor();
        break;
    }
  }

  private static function saveFocus($focus) {
    if (self::$connectionName === false || self::$suppressFocusChange) {
      return;
    }
    self::$queryList->setFocus(self::$connectionName, $focus);
  }

  private static function updateWorkArea($query = false) {
    if (self::$connectionName === false) {
      self::deactivateEditor();
      self::deactivateResult();
      self::deactivateList();
      self::$editorContainer->hide();
      self::$resultContainer->hide();
      self::$listContainer->hide();
      return;
    }
    self::$editorContainer->show();
    self::$listContainer->show();
    if ($query !== false && self::hasResult($query)) {
      self::$editorContainer->removeClass('query-editor-full');
      self::$resultContainer->show();
      if (!self::$suppressFocusChange && self::$activeBox === self::EDITOR) {
        self::deactivateEditor();
        self::activateResult();
      }
    } else {
      self::$resultContainer->hide();
      self::$editorContainer->addClass('query-editor-full');
      if (!self::$suppressFocusChange && self::$activeBox === self::RESULT) {
        self::deactivateResult();
        self::activateEditor();
      }
    }
  }

  public static function addQuery($name, $sql, $connection, $schema, $table) {
    self::saveCurrentEditor();
    if (self::$connectionName !== $connection) {
      self::loadConnection($connection);
    }
    $query = self::$queryList->add($connection, [
      'name' => $name,
      'sql' => $sql,
      'schema' => $schema,
      'table' => $table,
      'status' => 'new'
    ]);
    self::renderList();
    self::showQuery($query['id']);
    self::deactivateList();
    self::activateEditor();
    Element::refresh();
    return $query;
  }

  public static function addTemplateQuery($templateName, $name, $connection, $schema, $table, $fields = null) {
    if (!isset(self::$templates[$templateName])) {
      return false;
    }
    return self::addQuery($name, self::fillTemplate(self::$templates[$templateName], $schema, $table, $fields), $connection, $schema, $table);
  }

  public static function selectQueryFromList($list) {
    if (self::$updatingList) {
      return;
    }
    $active = $list->getActive();
    if ($active === false) {
      return;
    }
    $id = $active->getValue();
    self::$queryList->sort(self::$connectionName, $list->getOrderValue());
    self::renderList();
    self::saveCurrentEditor();
    self::showQuery($id);
    Element::refresh();
  }

  public static function loadConnection($connectionName, $activateEditor = true) {
    self::saveCurrentEditor();
    self::$connectionName = $connectionName;
    self::renderList();
    if ($connectionName === false) {
      self::restoreSelectedSchemaAndTable();
      self::$connectionInfo->setText('No connection selected');
      self::$queryName->setText('');
      self::$editor->setValue('');
      self::clearResult();
      self::updateWorkArea(false);
      return;
    }
    self::restoreSelectedSchemaAndTable();
    $query = self::ensureActiveQuery();
    self::renderList();
    $focus = self::$queryList->getFocus(self::$connectionName);
    self::$suppressFocusChange = true;
    self::showQuery($query['id']);
    self::$suppressFocusChange = false;
    if ($activateEditor) {
      self::activateFocus(self::normalizeFocus($focus, $query));
    }
  }

  private static function showQuery($id) {
    if (self::$connectionName === false) {
      return;
    }
    $query = self::$queryList->get(self::$connectionName, $id);
    if ($query === false) {
      return;
    }
    self::$queryList->setActive(self::$connectionName, $id);
    self::setTitleContext($query);
    self::$queryName->setText(self::formatQueryTitle($query));
    self::$editor->setValue($query['sql'] ?? '');
    if (!isset(self::$loadedEditorStates[self::$connectionName][$id])) {
      self::$loadedEditorStates[self::$connectionName][$id] = [
        'sql' => $query['sql'] ?? '',
        'state' => self::captureEditorState()
      ];
    }
    if (isset(self::$editorStates[self::$connectionName][$id])) {
      self::restoreEditorState(self::$editorStates[self::$connectionName][$id]);
    }
    self::showResult($query);
    self::updateWorkArea($query);
    self::$updatingList = true;
    $index = self::$queryList->findIndex(self::$connectionName, $id);
    self::$list->moveCursor($index);
    self::$updatingList = false;
  }

  private static function formatQueryTitle($query) {
    $time = $query['createdAt'] ?? false;
    if (($query['status'] ?? 'new') !== 'new') {
      $time = $query['updatedAt'] ?? $time;
    }
    $title = ($query['name'] ?? 'NEW') . ' [' . self::statusLabel($query['status'] ?? 'new') . ']';
    if ($time !== false) {
      $title .= ' ' . date('Y-m-d H:i:s', $time);
    }
    return $title;
  }

  public static function refreshTitle() {
    if (self::$connectionName === false) {
      return;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false) {
      $query = [];
    }
    self::setTitleContext($query);
    Element::refresh();
  }

  public static function restoreFocus() {
    if (self::$connectionName === false) {
      return;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    self::activateFocus(self::normalizeFocus(self::$queryList->getFocus(self::$connectionName), $query));
    Element::refresh();
  }

  public static function setSelectedSchemaAndTable($schema, $table = false) {
    if (self::$connectionName === false) {
      return;
    }
    self::$queryList->setSchemaAndTable(self::$connectionName, $schema, $table);
  }

  public static function restoreSelectedSchemaAndTable() {
    if (self::$connectionName === false) {
      \MADB\Table\MenuController::restoreSelection(false, false);
      return;
    }
    \MADB\Table\MenuController::restoreSelection(
      self::$queryList->getSchema(self::$connectionName),
      self::$queryList->getTable(self::$connectionName)
    );
  }

  private static function setTitleContext($query = []) {
    if (self::$connectionName === false) {
      self::$connectionInfo->setText('No connection selected');
      return;
    }
    $title = self::$connectionName;
    $schema = self::currentSchema($query);
    $table = self::currentTable($query);
    if ($schema !== '') {
      $title .= ' : ' . $schema;
    }
    if ($table !== '') {
      $title .= ' . ' . $table;
    }
    self::$connectionInfo->setText($title);
  }

  private static function renderList() {
    self::$updatingList = true;
    self::$list->clear();
    if (self::$connectionName === false) {
      self::$updatingList = false;
      return;
    }
    $activeId = self::$queryList->getActiveId(self::$connectionName);
    foreach (self::$queryList->getAll(self::$connectionName) as $index => $query) {
      $item = new \SPTK\Elements\ListItem(self::$list);
      $item->setValue($query['id']);
      $item->setText($query['name'] ?? 'NEW');
      $item->setRight(self::statusLabel($query['status'] ?? 'new'));
      if (!empty($query['pinned'])) {
        $item->setLeft('*');
        $item->addClass('query-pinned');
      }
      if ($query['id'] === $activeId) {
        self::$list->moveCursor($index);
      }
    }
    self::$updatingList = false;
  }

  private static function statusLabel($status) {
    switch ($status) {
      case 'running': return 'run';
      case 'ok': return 'ok';
      case 'error': return 'err';
      default: return 'new';
    }
  }

  public static function saveCurrentEditor() {
    if (self::$queryList === null || self::$connectionName === false) {
      return;
    }
    $activeId = self::$queryList->getActiveId(self::$connectionName);
    if ($activeId === false) {
      return;
    }
    self::rememberCurrentEditorState();
    $query = self::$queryList->get(self::$connectionName, $activeId);
    if ($query !== false && self::isLocked($query)) {
      return;
    }
    self::$queryList->update(self::$connectionName, $activeId, [
      'sql' => self::editorText()
    ]);
  }

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
    Element::refresh();
  }

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
    }
    Element::refresh();
  }

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
    self::deactivateList();
    self::deactivateResult();
    self::activateEditor();
    Element::refresh();
  }

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
        ['text' => 'Revert', 'hotKey' => 'RETURN', 'onPress' => '\MADB\Main\ScreenController::doRevertQuery'],
        ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close']
      ]
    );
    Element::refresh();
  }

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

  public static function clearQuery() {
    if (self::$connectionName === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before clearing a query.');
      return;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false) {
      \SPTK\Elements\WarningPanel::forge('No query selected!', 'Please select a query before clearing it.');
      return;
    }
    if (self::isLocked($query)) {
      \SPTK\Elements\WarningPanel::forge('Query is locked', 'This query has already started running and cannot be modified.');
      return;
    }
    \SPTK\Elements\WarningPanel::forge(
      'Clear query',
      "Clear query '" . ($query['name'] ?? 'NEW') . "'? This cannot be undone, but Revert can restore the loaded state.",
      [
        ['text' => 'Clear', 'hotKey' => 'RETURN', 'onPress' => '\MADB\Main\ScreenController::doClearQuery'],
        ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close']
      ]
    );
    Element::refresh();
  }

  public static function doClearQuery($confirmationPanel) {
    if (self::$connectionName === false) {
      return;
    }
    $activeId = self::$queryList->getActiveId(self::$connectionName);
    if ($activeId === false) {
      return;
    }
    $query = self::$queryList->get(self::$connectionName, $activeId);
    if ($query !== false && self::isLocked($query)) {
      return;
    }
    $query = self::$queryList->update(self::$connectionName, $activeId, [
      'sql' => ''
    ]);
    unset(self::$editorStates[self::$connectionName][$activeId]);
    $confirmationPanel->remove();
    if ($query !== false) {
      self::showQuery($activeId);
    }
    self::deactivateList();
    self::deactivateResult();
    self::activateEditor();
    Element::refresh();
  }

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

  private static function quoteIdentifier($identifier) {
    return '`' . str_replace('`', '``', $identifier) . '`';
  }

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

  public static function closePanel($panel) {
    $panel->hide();
    Element::refresh();
  }

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
        ['text' => 'Delete', 'hotKey' => 'RETURN', 'onPress' => '\MADB\Main\ScreenController::doDeleteQuery'],
        ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close']
      ]
    );
    Element::refresh();
  }

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

  public static function executeQuery() {
    $connection = self::getCurrentConnection();
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before executing a query.');
      return;
    }
    if (self::$connectionName !== $connection['name']) {
      self::loadConnection($connection['name']);
    }
    $query = self::ensureActiveQuery();
    if ($query === false) {
      return;
    }
    if (self::isLocked($query)) {
      \SPTK\Elements\WarningPanel::forge('Query is locked', 'This query has already started running and cannot be executed again.');
      return;
    }
    self::saveCurrentEditor();
    $query = self::$queryList->getActive(self::$connectionName);
    ResultStore::delete($query['resultFile'] ?? false);
    $resultFile = ResultStore::relativePath(self::$connectionName, $query['id']);
    $query = self::$queryList->update(self::$connectionName, $query['id'], [
      'status' => 'running',
      'result' => false,
      'resultFile' => $resultFile,
      'error' => false,
      'info' => []
    ]);
    self::renderList();
    self::showQuery($query['id']);
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'query',
      'arguments' => [$query['sql'], ResultStore::absolutePath($resultFile)],
      'queryId' => $query['id'],
      'callback' => ['\MADB\Main\ScreenController', 'queryResult']
    ]);
    Element::refresh();
  }

  public static function queryResult($response) {
    $connectionName = $response['connection']['name'] ?? self::$connectionName;
    $queryId = $response['queryId'] ?? false;
    if ($connectionName === false || $queryId === false) {
      return;
    }
    $result = $response['status'] === 'OK' ? $response['result'] : false;
    $resultFile = false;
    if (is_array($result) && isset($result['columns'], $result['rowCount'])) {
      $query = self::$queryList->get($connectionName, $queryId);
      $resultFile = $query['resultFile'] ?? ResultStore::relativePath($connectionName, $queryId);
      $result['file'] = $resultFile;
    } else {
      $query = self::$queryList->get($connectionName, $queryId);
      ResultStore::delete($query['resultFile'] ?? false);
    }
    $updates = [
      'status' => $response['status'] === 'OK' ? 'ok' : 'error',
      'result' => $result,
      'resultFile' => $resultFile,
      'error' => $response['status'] === 'OK' ? false : $response['result'],
      'info' => [
        'pid' => $response['pid'] ?? false,
        'times' => $response['times'] ?? []
      ]
    ];
    self::$queryList->update($connectionName, $queryId, $updates);
    if (self::$connectionName === $connectionName) {
      self::renderList();
      if (self::$queryList->getActiveId($connectionName) === $queryId) {
        self::showQuery($queryId);
      }
      Element::refresh();
    }
  }

  private static function clearResult() {
    self::$resultMessage->setText('');
    self::$resultMessage->hide();
    self::$resultTable->hide();
  }

  private static function showResult($query) {
    self::clearResult();
    $result = $query['result'] ?? false;
    if (is_array($result) && isset($result['columns'], $result['rowCount'], $result['file'])) {
      $file = ResultStore::absolutePath($result['file']);
      if ($file !== false && file_exists($file)) {
        self::$resultTable->setFile($file);
        self::$resultTable->show();
        self::syncResultTableHeader();
        return;
      }
    }
    $text = self::formatResult($query);
    if ($text !== '') {
      self::$resultMessage->setText($text);
      self::$resultMessage->show();
    }
  }

  private static function resultTableHeader() {
    return Element::firstByType('TableHeaderRow', self::$resultTable);
  }

  private static function syncResultTableHeader() {
    self::setResultTableHeaderActive(self::$activeBox === self::RESULT);
  }

  private static function setResultTableHeaderActive($active) {
    $header = self::resultTableHeader();
    if ($header === false) {
      return;
    }
    if ($active) {
      $header->addClass('active-title');
    } else {
      $header->removeClass('active-title');
    }
  }

  private static function formatResult($query) {
    $status = $query['status'] ?? 'new';
    if ($status === 'running') {
      return 'Running...';
    }
    if ($status === 'error') {
      return trim('ERROR: ' . ($query['error'] ?? 'Unknown error') . "\n" . self::formatInfo($query));
    }
    $result = $query['result'] ?? false;
    if ($result === false) {
      return '';
    }
    if (isset($result['affectedRows'])) {
      return trim('Affected rows: ' . $result['affectedRows'] . "\n" . self::formatInfo($query));
    }
    if (isset($result['columns'], $result['rows'])) {
      $lines = [];
      $lines[] = implode("\t", $result['columns']);
      foreach ($result['rows'] as $row) {
        $line = [];
        foreach ($result['columns'] as $column) {
          $line[] = (string) ($row[$column] ?? '');
        }
        $lines[] = implode("\t", $line);
      }
      $lines[] = count($result['rows']) . ' row(s)';
      $info = self::formatInfo($query);
      if ($info !== '') {
        $lines[] = $info;
      }
      return implode("\n", $lines);
    }
    if (isset($result['columns'], $result['rowCount'])) {
      $text = $result['rowCount'] . ' row(s)';
      $info = self::formatInfo($query);
      if ($info !== '') {
        $text .= "\n" . $info;
      }
      return $text;
    }
    $text = is_scalar($result) ? (string) $result : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return trim($text . "\n" . self::formatInfo($query));
  }

  private static function formatInfo($query) {
    $info = $query['info'] ?? [];
    $times = $info['times'] ?? [];
    if (empty($times['s']) || empty($times['f'])) {
      return '';
    }
    $duration = round($times['f'] - $times['s'], 4);
    $pid = $info['pid'] ?? false;
    $text = "Time: {$duration}s";
    if ($pid !== false) {
      $text .= " PID: {$pid}";
    }
    return $text;
  }

  public static function activateEditor() {
    self::$activeBox = self::EDITOR;
    self::saveFocus('editor');
    self::$editor->addClass('active-box');
    self::$editor->addVariant('active');
    self::$title->addClass('active-title');
    self::$editor->raise();
  }

  public static function deactivateEditor() {
    self::$editor->removeClass('active-box');
    self::$editor->removeVariant('active');
    self::$title->removeClass('active-title');
  }

  public static function activateResult() {
    self::$activeBox = self::RESULT;
    self::saveFocus('result');
    self::$result->addClass('active-box');
    self::$result->addVariant('active');
    self::setResultTableHeaderActive(true);
    self::$result->raise();
  }

  public static function deactivateResult() {
    self::$result->removeClass('active-box');
    self::$result->removeVariant('active');
    self::setResultTableHeaderActive(false);
  }

  public static function activateList() {
    self::$activeBox = self::LIST;
    self::saveFocus('list');
    self::$list->addClass('active-box');
    self::$list->addVariant('active');
    self::$list->raise();
  }

  public static function deactivateList() {
    self::$list->removeClass('active-box');
    self::$list->removeVariant('active');
  }

  public static function keyPressHandler($element, $event) {
    if (self::$activeBox === self::LIST && self::$connectionName !== false) {
      if (($event['scancode'] ?? false) === ScanCode::INSERT || ($event['key'] ?? false) === KeyCode::INSERT) {
        self::newQuery();
        return true;
      }
      if (KeyCombo::resolve($event['mod'], $event['scancode'], $event['key']) === Action::DELETE_FORWARD) {
        self::deleteQuery();
        return true;
      }
      if (KeyCombo::resolve($event['mod'], $event['scancode'], $event['key']) === Action::DO_IT) {
        self::renameQuery();
        return true;
      }
      if (KeyCombo::resolve($event['mod'], $event['scancode'], $event['key']) === Action::SELECT_ITEM) {
        self::togglePinQuery();
        return true;
      }
    }
    switch (KeyCombo::resolve($event['mod'], $event['scancode'], $event['key'])) {
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
