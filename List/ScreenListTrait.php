<?php

namespace MADB\List;

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

/**
 * Manages the query list beside the editor. It keeps the selected query, editor text, result panel, and schema/table title context in sync.
 */
trait ScreenListTrait {

  /** Creates a query tab from a named SQL template and current schema/table context. */
  public static function addTemplateQuery($templateName, $name, $connection, $schema, $table, $fields = null) {
    if (!isset(self::$templates[$templateName])) {
      return false;
    }
    $sql = self::fillTemplate(self::$templates[$templateName], $schema, $table, $fields);
    return self::addQuery($name, \MADB\Query\SqlFormatter\SqlFormatter::format($sql), $connection, $schema, $table);
  }

  /** Selects query from list and refreshes related query workspace state. */
  public static function selectQueryFromList($list) {
    if (self::$updatingList) {
      return;
    }
    if (self::$connectionName === false) {
      return;
    }
    $active = $list->getActive();
    if ($active === false) {
      return;
    }
    $id = $active->getValue();
    $activeId = self::$queryList->getActiveId(self::$connectionName);
    $order = $list->getOrderValue();
    $orderChanged = $order !== self::queryListOrder();
    if ($orderChanged) {
      self::$queryList->sort(self::$connectionName, $order);
      self::renderList();
    }
    self::saveCurrentEditor();
    if (!$orderChanged && $id === $activeId && self::$editorQueryId === $id && self::$editorConnectionName === self::$connectionName) {
      return;
    }
    self::showQuery($id);
    Element::refresh();
  }

  /** Loads connection data for the query workspace. */
  public static function loadConnection($connectionName, $activateEditor = true) {
    self::saveCurrentEditor();
    self::$connectionName = $connectionName;
    self::renderList();
    if ($connectionName === false) {
      self::restoreSelectedSchemaAndTable();
      self::$connectionInfo->setText('No connection selected');
      self::$queryName->setText('');
      self::$editor->setValue('');
      self::$editorConnectionName = false;
      self::$editorQueryId = false;
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

  /** Loads a query tab into the editor, result panel, title bar, and query list selection. */
  private static function showQuery($id, $reloadEditor = true) {
    if (self::$connectionName === false) {
      return;
    }
    $query = self::$queryList->get(self::$connectionName, $id);
    if ($query === false) {
      return;
    }
    if (!empty($query['unseenResult'])) {
      $query = self::$queryList->update(self::$connectionName, $id, ['unseenResult' => false]);
    }
    self::$queryList->setActive(self::$connectionName, $id);
    self::setTitleContext($query);
    self::$queryName->setText(self::formatQueryTitle($query));
    self::$searchSession = false;
    if ($reloadEditor) {
      self::prepareEditorForStateRestore($query);
      self::$resultHighlightKey = false;
      $editorState = self::$editorStates[self::$connectionName][$id] ?? false;
      if ($editorState !== false && method_exists(self::$editor, 'setValueAndState')) {
        self::$editor->setValueAndState($query['sql'] ?? '', $editorState);
      } else {
        self::$editor->setValue($query['sql'] ?? '');
      }
      if (!isset(self::$loadedEditorStates[self::$connectionName][$id])) {
        self::$loadedEditorStates[self::$connectionName][$id] = [
          'sql' => $query['sql'] ?? '',
          'state' => self::captureEditorState()
        ];
      }
      if ($editorState !== false && !method_exists(self::$editor, 'setValueAndState')) {
        self::restoreEditorState($editorState);
      }
      self::$editorConnectionName = self::$connectionName;
      self::$editorQueryId = $id;
    }
    self::applyQueryEditorReadOnly($query);
    self::updateWorkArea($query);
    self::showResult($query);
    self::$updatingList = true;
    $index = self::$queryList->findIndex(self::$connectionName, $id);
    self::$list->moveCursor($index);
    self::$updatingList = false;
    self::recalculateWorkArea();
  }

  /** Recalculates screen geometry after editor/result visibility changes. */
  private static function recalculateWorkArea(): void {
    $screen = self::$editorContainer->findAncestorByType('Screen');
    if ($screen !== false) {
      $screen->recalculateGeometry();
    }
  }

  /** Formats query title text for the query workspace. */
  private static function formatQueryTitle($query) {
    $time = $query['createdAt'] ?? false;
    if (($query['status'] ?? 'new') !== 'new') {
      $time = $query['updatedAt'] ?? $time;
    }
    $title = ($query['name'] ?? 'NEW') . ' [' . self::statusLabel($query['status'] ?? 'new') . ']';
    $resultIndicator = self::resultTitleIndicator($query);
    if ($resultIndicator !== '') {
      $title = ($query['name'] ?? 'NEW') . ' [' . self::statusLabel($query['status'] ?? 'new') . ' ' . $resultIndicator . ']';
    }
    if ($time !== false) {
      $title .= ' ' . date('Y-m-d H:i:s', $time);
    }
    return $title;
  }

  /** Builds the active-result counter shown beside a query tab title. */
  private static function resultTitleIndicator($query): string {
    $statements = $query['statements'] ?? [];
    if (is_array($statements) && count($statements) >= 2) {
      $activeStatement = max(0, (int) ($query['activeStatement'] ?? 0));
      $indexes = array_map(fn($statement) => (int) ($statement['index'] ?? 0), $statements);
      $maxIndex = empty($indexes) ? count($statements) - 1 : max($indexes);
      $total = max(count($statements), $maxIndex + 1);
      $active = min($activeStatement, $total - 1);
      return ($active + 1) . '/' . $total;
    }
    $results = $query['results'] ?? [];
    if (!is_array($results) || count($results) < 2) {
      return '';
    }
    $active = max(0, min((int) ($query['activeResult'] ?? count($results) - 1), count($results) - 1));
    return ($active + 1) . '/' . count($results);
  }

  /** Refreshes the workspace title from the active query connection, schema, and table. */
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

  /** Restores the saved editor/list/result focus for the active query tab. */
  public static function restoreFocus() {
    if (self::$connectionName === false) {
      return;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    self::activateFocus(self::normalizeFocus(self::$queryList->getFocus(self::$connectionName), $query));
    Element::refresh();
  }

  /** Applies selected schema and table values to query workspace state or controls. */
  public static function setSelectedSchemaAndTable($schema, $table = false) {
    if (self::$connectionName === false) {
      return;
    }
    self::$queryList->setSchemaAndTable(self::$connectionName, $schema, $table);
  }

  /** Restores table-menu schema and table selection from active query context. */
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

  /** Applies title context values to query workspace state or controls. */
  private static function setTitleContext($query = []) {
    if (self::$connectionName === false) {
      self::$connectionInfo->setText('No connection selected');
      return;
    }
    $title = self::$connectionName;
    $schema = self::currentSchema($query);
    if ($schema !== '') {
      $title .= ' : ' . $schema;
    }
    self::$connectionInfo->setText($title);
  }

  /** Rebuilds the query list widget for the active connection. */
  private static function renderList() {
    self::$updatingList = true;
    self::$list->clear();
    if (self::$connectionName === false) {
      self::$updatingList = false;
      return;
    }
    $activeId = self::$queryList->getActiveId(self::$connectionName);
    $items = [];
    $cursor = 0;
    foreach (self::$queryList->getAll(self::$connectionName) as $index => $query) {
      $item = [
        'value' => $query['id'],
        'text' => $query['name'] ?? 'NEW',
        'leftReserve' => 2,
        'right' => self::queryListMarker($query),
        'rightReserve' => 6,
        'truncateMarker' => '~'
      ];
      if (!empty($query['pinned'])) {
        $item['left'] = '*';
        $item['classes'] = ['query-pinned'];
      }
      if ($query['id'] === $activeId) {
        $cursor = $index;
      }
      $items[] = $item;
    }
    self::$list->setItems($items);
    self::$list->moveCursor($cursor);
    self::$updatingList = false;
  }

  /** Returns the stored query id order for the active connection. */
  private static function queryListOrder(): array {
    if (self::$connectionName === false) {
      return [];
    }
    return array_map(
      fn($query) => $query['id'],
      self::$queryList->getAll(self::$connectionName)
    );
  }

  /** Formats compact query status text for the query list title. */
  private static function statusLabel($status) {
    switch ($status) {
      case 'running': return 'run';
      case 'ok': return 'ok';
      case 'error': return 'err';
      default: return 'new';
    }
  }

  /** Builds the right-side query list marker for running or unseen results. */
  private static function queryListMarker($query): string {
    if (($query['status'] ?? 'new') === 'running') {
      return 'run';
    }
    return !empty($query['unseenResult']) ? 'done' : '';
  }

  /** Persists current editor text and cursor state into the loaded query tab. */
  public static function saveCurrentEditor() {
    if (self::$queryList === null || self::$connectionName === false) {
      return;
    }
    $connectionName = self::$editorConnectionName !== false ? self::$editorConnectionName : self::$connectionName;
    $queryId = self::$editorQueryId !== false ? self::$editorQueryId : self::$queryList->getActiveId($connectionName);
    if ($connectionName === false || $queryId === false) {
      return;
    }
    self::rememberCurrentEditorState();
    $query = self::$queryList->get($connectionName, $queryId);
    if ($query !== false && !self::canEditQueryText($query)) {
      return;
    }
    $sql = self::editorText();
    if ($query !== false && ($query['sql'] ?? '') === $sql) {
      return;
    }
    self::$queryList->update($connectionName, $queryId, [
      'sql' => $sql
    ]);
  }

}
