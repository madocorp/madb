<?php

namespace MADB\Main;

use \SPTK\SDLWrapper\KeyCombo;
use \SPTK\SDLWrapper\Action;
use \SPTK\Element;
use \MADB\Query\QueryList;

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
  private static $list;
  private static $connectionInfo;
  private static $queryName;
  private static $renamePanel;
  private static $queryList;
  private static $connectionName = false;
  private static $updatingList = false;
  private static $suppressFocusChange = false;

  public static function init() {
    self::$editorContainer = Element::byName('query-editor-container');
    self::$resultContainer = Element::byName('query-result-container');
    self::$listContainer = Element::byName('query-list-container');
    self::$editor = Element::byName('query-editor');
    self::$title = Element::byName('query-title');
    self::$result = Element::byName('query-result');
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
  }

  public static function selectQueryFromList($list) {
    if (self::$updatingList) {
      return;
    }
    $id = $list->getValue();
    if ($id === false) {
      return;
    }
    self::saveCurrentEditor();
    self::showQuery($id);
    Element::refresh();
  }

  public static function loadConnection($connectionName, $activateEditor = true) {
    self::saveCurrentEditor();
    self::$connectionName = $connectionName;
    self::renderList();
    if ($connectionName === false) {
      self::$connectionInfo->setText('No connection selected');
      self::$queryName->setText('');
      self::$editor->setValue('');
      self::$result->setText('');
      self::updateWorkArea(false);
      return;
    }
    $query = self::ensureActiveQuery();
    self::renderList();
    self::$suppressFocusChange = !$activateEditor;
    self::showQuery($query['id']);
    self::$suppressFocusChange = false;
    if ($activateEditor) {
      self::deactivateList();
      if (self::hasResult($query)) {
        self::deactivateEditor();
        self::activateResult();
      } else {
        self::deactivateResult();
        self::activateEditor();
      }
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
    $index = self::$queryList->findIndex(self::$connectionName, $id);
    self::setTitleContext($query);
    self::$queryName->setText('#' . ($index + 1) . ' - ' . ($query['name'] ?? 'NEW') . ' [' . self::statusLabel($query['status'] ?? 'new') . ']');
    self::$editor->setValue($query['sql'] ?? '');
    self::$result->setText(self::formatResult($query));
    self::updateWorkArea($query);
    self::$updatingList = true;
    self::$list->moveCursor($index);
    self::$updatingList = false;
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
      $item->setSelectable('queries');
      $item->setText('#' . ($index + 1) . ' - ' . ($query['name'] ?? 'NEW'));
      $item->setRight(self::statusLabel($query['status'] ?? 'new'));
      if ($query['id'] === $activeId) {
        $item->setSelected('true');
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
    self::$queryList->deleteActive(self::$connectionName);
    if (empty(self::$queryList->getAll(self::$connectionName))) {
      self::$queryList->createBlank(self::$connectionName);
    }
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
    $query = self::$queryList->update(self::$connectionName, $query['id'], [
      'status' => 'running',
      'result' => false,
      'error' => false,
      'info' => []
    ]);
    self::renderList();
    self::showQuery($query['id']);
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'query',
      'arguments' => [$query['sql']],
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
    $updates = [
      'status' => $response['status'] === 'OK' ? 'ok' : 'error',
      'result' => $response['status'] === 'OK' ? $response['result'] : false,
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
    self::$result->addClass('active-box');
    self::$result->addVariant('active');
    self::$result->raise();
  }

  public static function deactivateResult() {
    self::$result->removeClass('active-box');
    self::$result->removeVariant('active');
  }

  public static function activateList() {
    self::$activeBox = self::LIST;
    self::$list->addClass('active-box');
    self::$list->addVariant('active');
    self::$list->raise();
  }

  public static function deactivateList() {
    self::$list->removeClass('active-box');
    self::$list->removeVariant('active');
  }

  public static function keyPressHandler($element, $event) {
    switch (KeyCombo::resolve($event['mod'], $event['scancode'], $event['key'])) {
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
