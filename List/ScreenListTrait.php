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
    $template = self::queryTemplate($templateName, $connection);
    if ($template === false) {
      return false;
    }
    $text = self::fillTemplate($template, $schema, $table, $fields, self::connectionEngineType($connection));
    $text = str_replace('[LIMIT]', (string)\MADB\App\Settings::defaultSelectLimit(), $text);
    return self::addQuery($name, self::language($connection)->format($text), $connection, $schema, $table);
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
      self::renderQueryMenu(false);
      self::renderTemplateMenu(false);
      self::applyQueryEditorTokenizer(false);
      self::clearPendingQueryEditorTokenizer();
      self::$editor->setValue('');
      self::$editorConnectionName = false;
      self::$editorQueryId = false;
      self::clearResult();
      self::updateWorkArea(false);
      return;
    }
    self::restoreSelectedSchemaAndTable();
    self::renderQueryMenu($connectionName);
    self::renderTemplateMenu($connectionName);
    $query = self::ensureActiveQuery();
    self::renderList();
    $focus = self::$queryList->getFocus(self::$connectionName);
    self::$suppressFocusChange = true;
    self::showQuery($query['id']);
    self::$suppressFocusChange = false;
    self::activateFocus(self::normalizeFocus($focus, $query));
  }

  /** Loads a query tab into the editor, result panel, title bar, and query list selection. */
  private static function showQuery($id, $reloadEditor = true, bool $preserveResultStatusState = false) {
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
      $deferTokenizer = self::shouldDeferQueryEditorTokenizer($query);
      self::applyQueryEditorTokenizer($deferTokenizer ? false : self::$connectionName);
      self::$resultHighlightKey = false;
      $editorState = self::$editorStates[self::$connectionName][$id] ?? false;
      if ($editorState !== false && method_exists(self::$editor, 'setValueAndState')) {
        self::$editor->setValueAndState($query['text'] ?? '', $editorState);
      } else {
        self::$editor->setValue($query['text'] ?? '');
      }
      if (!isset(self::$loadedEditorStates[self::$connectionName][$id])) {
        self::$loadedEditorStates[self::$connectionName][$id] = [
          'text' => $query['text'] ?? '',
          'state' => self::captureEditorState()
        ];
      }
      if ($editorState !== false && !method_exists(self::$editor, 'setValueAndState')) {
        self::restoreEditorState($editorState);
      }
      if ($deferTokenizer) {
        self::scheduleQueryEditorTokenizer(self::$connectionName, $id, self::queryEditorTokenizer(self::$connectionName));
      } else {
        self::clearPendingQueryEditorTokenizer();
      }
      self::$editorConnectionName = self::$connectionName;
      self::$editorQueryId = $id;
    }
    self::applyQueryEditorReadOnly($query);
    self::updateWorkArea($query);
    self::showResult($query, $preserveResultStatusState);
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

  /** Applies the query editor tokenizer for the active connection engine. */
  private static function applyQueryEditorTokenizer($connectionName): void {
    if (self::$editor === false || !method_exists(self::$editor, 'setTokenizer')) {
      return;
    }
    self::$editor->setTokenizer(self::queryEditorTokenizer($connectionName));
  }

  /** Returns the editor tokenizer class for a connection, or the plain tokenizer when no connection is supplied. */
  private static function queryEditorTokenizer($connectionName) {
    if ($connectionName === false) {
      return false;
    }
    $tokenizer = '\MADB\Tokenizer\Sql';
    if ($connectionName !== false && self::connectionEngineType($connectionName) === 'MongoDB') {
      $tokenizer = '\MADB\Tokenizer\MongoShell';
    }
    return $tokenizer;
  }

  /** Checks whether query activation should defer expensive syntax highlighting. */
  private static function shouldDeferQueryEditorTokenizer($query): bool {
    if (self::$editor === false || !method_exists(self::$editor, 'setValueAndState')) {
      return false;
    }
    return strlen((string)($query['text'] ?? '')) >= self::DEFERRED_EDITOR_TOKENIZER_BYTES;
  }

  /** Rebuilds the Query menu for the active connection engine. */
  private static function renderQueryMenu($connectionName): void {
    $menuBox = Element::byName('menu-query-list');
    if ($menuBox === false || !method_exists($menuBox, 'clear')) {
      return;
    }
    $isMongo = $connectionName !== false && self::connectionEngineType($connectionName) === 'MongoDB';
    $items = [
      ['text' => 'Execute', 'onOpen' => 'MADB\Query\QueryExecutionController::executeQuery'],
      ['text' => 'Execute one', 'onOpen' => 'MADB\Query\QueryExecutionController::executeCurrentQuery'],
      ['name' => 'menu-query-templates', 'text' => 'Templates', 'submenu' => true]
    ];
    if ($isMongo) {
      $items[] = ['name' => 'menu-query-mongodb', 'text' => 'MongoDB', 'submenu' => true];
    }
    $items = array_merge($items, [
      ['text' => 'Edit', 'onOpen' => 'MADB\Query\QueryEditorController::editQuery'],
      ['text' => 'Format', 'onOpen' => 'MADB\Query\QueryEditorController::formatQuery'],
      ['text' => 'Revert', 'onOpen' => 'MADB\Query\QueryEditorController::revertQuery'],
      ['text' => 'Clear', 'onOpen' => 'MADB\Query\QueryEditorController::clearQuery'],
      ['text' => 'Import', 'onOpen' => 'MADB\Query\QueryEditorController::importQuery'],
      ['text' => 'Export', 'onOpen' => 'MADB\Query\QueryEditorController::exportQuery'],
      ['text' => 'Search', 'onOpen' => 'MADB\Query\QuerySearchController::searchQuery']
    ]);
    $menuBox->clear();
    foreach ($items as $item) {
      $menuBox->addItem($item);
    }
  }

  /** Rebuilds the query template submenu for the active connection engine. */
  private static function renderTemplateMenu($connectionName): void {
    $menuBox = Element::byName('menu-query-templates-list');
    if ($menuBox === false || !method_exists($menuBox, 'clear')) {
      return;
    }
    $menuBox->clear();
    if ($connectionName === false) {
      $menuBox->addItem('Select a connection!');
      return;
    }
    $templates = self::language($connectionName)->templates();
    if (empty($templates)) {
      $menuBox->addItem('No templates');
      return;
    }
    foreach ($templates as $template) {
      $menuBox->addItem([
        'text' => $template,
        'value' => $template,
        'onOpen' => 'MADB\Query\QueryEditorController::insertTemplate'
      ]);
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
    self::$queryList->setPrimaryAndSecondary(self::$connectionName, $schema, $table);
  }

  /** Restores table-menu schema and table selection from active query context. */
  public static function restoreSelectedSchemaAndTable() {
    if (self::$connectionName === false) {
      \MADB\Table\MenuController::restoreSelection(false, false);
      return;
    }
    \MADB\Table\MenuController::restoreSelection(
      self::$queryList->getPrimary(self::$connectionName),
      self::$queryList->getSecondary(self::$connectionName)
    );
  }

  /** Applies title context values to query workspace state or controls. */
  private static function setTitleContext($query = []) {
    if (self::$connectionName === false) {
      self::$connectionInfo->setText('No connection selected');
      return;
    }
    $connection = \MADB\Connection\ConnectionList::getInstance()->get(self::$connectionName);
    $engine = \MADB\Engine\EngineRegistry::connectionEngine($connection);
    $title = '[' . \MADB\Engine\EngineRegistry::label($engine) . '] ' . self::$connectionName;
    $primary = self::currentPrimary($query);
    if ($primary !== '') {
      $title .= ' : ' . $primary;
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
    $text = self::editorText();
    if ($query !== false && ($query['text'] ?? '') === $text) {
      return;
    }
    self::$queryList->update($connectionName, $queryId, [
      'text' => $text
    ]);
  }

}
