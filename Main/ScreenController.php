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

class ScreenController {

  const EDITOR = 0;
  const RESULT = 1;
  const LIST = 2;
  const CLEAR_WARNING_RESULT_BYTES = 10485760;
  const CLEAR_WARNING_SECONDS = 10;

  private static $activeBox = self::EDITOR;
  private static $editorContainer;
  private static $resultContainer;
  private static $listContainer;
  private static $editor;
  private static $title;
  private static $result;
  private static $resultMessage;
  private static $resultStatus;
  private static $resultTable;
  private static $list;
  private static $connectionInfo;
  private static $queryName;
  private static $renamePanel;
  private static $searchPanel;
  private static $queryList;
  private static $connectionName = false;
  private static $updatingList = false;
  private static $suppressFocusChange = false;
  private static $editorStates = [];
  private static $loadedEditorStates = [];
  private static $searchSession = false;
  private static $searchPanelState = [
    'search' => '',
    'replaceEnabled' => false,
    'replace' => '',
    'regexp' => false,
    'caseSensitive' => false,
    'scopeAll' => false,
    'scopeNext' => true,
    'scopePrevious' => false,
    'scopeAfter' => false,
    'scopeBefore' => false
  ];
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
    self::$resultStatus = Element::byName('query-result-status');
    self::$resultTable = Element::byName('query-result-table');
    self::$list = Element::byName('query-list');
    self::$connectionInfo = Element::byName('connection-info');
    self::$queryName = Element::byName('query-name');
    self::$renamePanel = Element::byName('query-rename');
    self::$searchPanel = Element::byName('query-search');
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

  private static function boolValue($value): bool {
    return $value === true || $value === 'true' || $value === 1 || $value === '1';
  }

  private static function searchPattern($search, $regexp, $caseSensitive) {
    if ($search === '') {
      return false;
    }
    $body = $regexp ? str_replace('~', '\~', $search) : preg_quote($search, '~');
    return '~' . $body . '~u' . ($caseSensitive ? '' : 'i');
  }

  private static function validPattern($pattern): bool {
    set_error_handler(function() {
    });
    $valid = preg_match($pattern, '') !== false;
    restore_error_handler();
    return $valid;
  }

  private static function byteOffsetFromPosition($text, $row, $col): int {
    $lines = explode("\n", $text);
    $offset = 0;
    $maxRow = min(max(0, (int) $row), count($lines) - 1);
    for ($i = 0; $i < $maxRow; $i++) {
      $offset += strlen($lines[$i]) + 1;
    }
    return $offset + strlen(mb_substr($lines[$maxRow] ?? '', 0, max(0, (int) $col)));
  }

  private static function selectionEndOffsetFromCursorState($text, $state): int {
    $caret = $state['cursor']['caret'] ?? [0, 0];
    $anchor = $state['cursor']['anchor'] ?? $caret;
    $caretOffset = self::byteOffsetFromPosition($text, $caret[0] ?? 0, $caret[1] ?? 0);
    $anchorOffset = self::byteOffsetFromPosition($text, $anchor[0] ?? 0, $anchor[1] ?? 0);
    return max($caretOffset, $anchorOffset);
  }

  private static function selectionStartOffsetFromCursorState($text, $state): int {
    $caret = $state['cursor']['caret'] ?? [0, 0];
    $anchor = $state['cursor']['anchor'] ?? $caret;
    $caretOffset = self::byteOffsetFromPosition($text, $caret[0] ?? 0, $caret[1] ?? 0);
    $anchorOffset = self::byteOffsetFromPosition($text, $anchor[0] ?? 0, $anchor[1] ?? 0);
    return min($caretOffset, $anchorOffset);
  }

  private static function searchStartOffset($text, $state): int {
    $caret = $state['cursor']['caret'] ?? [0, 0];
    $anchor = $state['cursor']['anchor'] ?? $caret;
    if ($caret === $anchor) {
      return self::byteOffsetFromPosition($text, $caret[0] ?? 0, $caret[1] ?? 0);
    }
    return self::selectionEndOffsetFromCursorState($text, $state) + 1;
  }

  private static function positionFromByteOffset($text, $offset): array {
    $before = substr($text, 0, max(0, $offset));
    $lines = explode("\n", $before);
    return [count($lines) - 1, mb_strlen(end($lines))];
  }

  private static function cursorStateForMatch($text, $offset, $length, $state): array {
    $start = self::positionFromByteOffset($text, $offset);
    $end = self::positionFromByteOffset($text, max($offset, $offset + $length - 1));
    $state['cursor']['caret'] = $start;
    $state['cursor']['anchor'] = $end;
    $state['cursor']['caretBefore'] = $start;
    $state['cursor']['anchorBefore'] = $end;
    return $state;
  }

  private static function findQueryMatch($text, $search, $regexp, $caseSensitive, $offset = 0) {
    if ($search === '') {
      return false;
    }
    if ($regexp) {
      $pattern = self::searchPattern($search, true, $caseSensitive);
      if ($pattern === false || !self::validPattern($pattern)) {
        return false;
      }
      if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE, $offset)) {
        return [$matches[0][1], strlen($matches[0][0]), $matches[0][0]];
      }
      return false;
    }
    $pos = $caseSensitive ? strpos($text, $search, $offset) : stripos($text, $search, $offset);
    if ($pos === false) {
      return false;
    }
    return [$pos, strlen($search), substr($text, $pos, strlen($search))];
  }

  private static function allQueryMatches($text, $search, $regexp, $caseSensitive): array {
    if ($search === '') {
      return [];
    }
    $matches = [];
    if ($regexp) {
      $pattern = self::searchPattern($search, true, $caseSensitive);
      if ($pattern === false || !self::validPattern($pattern)) {
        return [];
      }
      if (!preg_match_all($pattern, $text, $matchData, PREG_OFFSET_CAPTURE)) {
        return [];
      }
      foreach ($matchData[0] as $match) {
        $length = strlen($match[0]);
        if ($length > 0) {
          $matches[] = [$match[1], $length, $match[0]];
        }
      }
      return $matches;
    }
    $offset = 0;
    $length = strlen($search);
    while (($pos = ($caseSensitive ? strpos($text, $search, $offset) : stripos($text, $search, $offset))) !== false) {
      $matches[] = [$pos, $length, substr($text, $pos, $length)];
      $offset = $pos + $length;
    }
    return $matches;
  }

  private static function selectedSearchScope($values): string {
    foreach (['All' => 'all', 'Previous' => 'previous', 'After' => 'after', 'Before' => 'before'] as $name => $scope) {
      if (self::boolValue($values['scope' . $name] ?? false)) {
        return $scope;
      }
    }
    return 'next';
  }

  private static function normalizeSearchPanelState($values): array {
    $scope = self::selectedSearchScope($values);
    return [
      'search' => (string) ($values['search'] ?? ''),
      'replaceEnabled' => self::boolValue($values['replaceEnabled'] ?? false),
      'replace' => (string) ($values['replace'] ?? ''),
      'regexp' => self::boolValue($values['regexp'] ?? false),
      'caseSensitive' => self::boolValue($values['caseSensitive'] ?? false),
      'scopeAll' => $scope === 'all',
      'scopeNext' => $scope === 'next',
      'scopePrevious' => $scope === 'previous',
      'scopeAfter' => $scope === 'after',
      'scopeBefore' => $scope === 'before'
    ];
  }

  private static function scopedMatches($text, $matches, $scope, $state): array {
    if ($scope === 'all' || $scope === 'next' || $scope === 'previous') {
      return $matches;
    }
    $cursorOffset = self::byteOffsetFromCursorState($text, $state);
    return array_values(array_filter($matches, function($match) use ($scope, $cursorOffset) {
      if ($scope === 'after') {
        return $match[0] >= $cursorOffset;
      }
      if ($scope === 'before') {
        return $match[0] + $match[1] <= $cursorOffset;
      }
      return true;
    }));
  }

  private static function byteOffsetFromCursorState($text, $state): int {
    if (!is_array($state)) {
      return 0;
    }
    $cursor = $state['cursor']['caret'] ?? [0, 0];
    return self::byteOffsetFromPosition($text, $cursor[0] ?? 0, $cursor[1] ?? 0);
  }

  private static function pickMatch($text, $matches, $scope, $state) {
    if (empty($matches)) {
      return [false, false];
    }
    if ($scope === 'previous') {
      $offset = self::selectionStartOffsetFromCursorState($text, $state);
      for ($i = count($matches) - 1; $i >= 0; $i--) {
        if ($matches[$i][0] < $offset) {
          return [$matches[$i], $i];
        }
      }
      return [$matches[count($matches) - 1], count($matches) - 1];
    }
    $offset = self::searchStartOffset($text, $state);
    foreach ($matches as $i => $match) {
      if ($match[0] >= $offset) {
        return [$match, $i];
      }
    }
    return [$matches[0], 0];
  }

  private static function highlightRanges($text, $matches): array {
    $ranges = [];
    foreach ($matches as $match) {
      $start = self::positionFromByteOffset($text, $match[0]);
      $end = self::positionFromByteOffset($text, $match[0] + $match[1]);
      $ranges[] = [$start[0], $start[1], $end[0], $end[1]];
    }
    return $ranges;
  }

  private static function applySearchHighlights($text, $matches): void {
    if (method_exists(self::$editor, 'setHighlightRanges')) {
      self::$editor->setHighlightRanges(self::highlightRanges($text, $matches));
    }
  }

  private static function searchHighlightMatches($matches, $scope, $index): array {
    if ($scope === 'next' || $scope === 'previous') {
      return [];
    }
    return $matches;
  }

  private static function clearSearchSession(): void {
    self::$searchSession = false;
    if (self::$editor !== null && method_exists(self::$editor, 'clearHighlightRanges')) {
      self::$editor->clearHighlightRanges();
    }
  }

  private static function replaceEditorText($text): void {
    if (method_exists(self::$editor, 'replaceText')) {
      self::$editor->replaceText($text);
      return;
    }
    self::$editor->setValue($text);
  }

  private static function homePath() {
    $home = getenv('HOME');
    if ($home !== false && $home !== '') {
      return $home;
    }
    return getcwd();
  }

  private static function openQueryFilePanel($path, $create, $callback): void {
    $window = self::$editor->findAncestorByType('Window');
    if ($window === false) {
      \SPTK\Elements\ErrorPanel::forge('Could not open file selector', 'No application window was found.');
      return;
    }
    $panel = new \SPTK\Elements\FilePanel($window);
    $panel->setFileFilter(true);
    $panel->setPath($path);
    $panel->setCreate($create);
    $panel->setOnSelect($callback);
    $panel->show();
    Element::refresh();
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
    return $status === 'running';
  }

  private static function resultFileSize($path) {
    $file = ResultStore::absolutePath($path);
    if ($file === false || !file_exists($file)) {
      return 0;
    }
    return filesize($file) ?: 0;
  }

  private static function resultSetSize($query): int {
    $size = self::resultFileSize($query['resultFile'] ?? false);
    foreach (($query['results'] ?? []) as $result) {
      if (is_array($result)) {
        $size += self::resultFileSize($result['file'] ?? false);
      }
    }
    return $size;
  }

  private static function queryDuration($query): float {
    $times = $query['info']['times'] ?? [];
    if (!empty($times['s']) && !empty($times['f'])) {
      return (float) $times['f'] - (float) $times['s'];
    }
    $duration = 0.0;
    foreach (($query['statements'] ?? []) as $statement) {
      $duration += (float) ($statement['time'] ?? 0);
    }
    return $duration;
  }

  private static function shouldWarnBeforeClear($query): bool {
    return self::resultSetSize($query) > self::CLEAR_WARNING_RESULT_BYTES
      || self::queryDuration($query) > self::CLEAR_WARNING_SECONDS;
  }

  private static function clearQueryResults($query): void {
    ResultStore::delete($query['resultFile'] ?? false);
    ResultStore::deleteMany($query['results'] ?? []);
  }

  private static function supressShortcutTextInput(): void {
    if (SDL::$instance !== null) {
      SDL::$instance->supressTextInput();
    }
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
    $showResult = $query !== false && (self::hasResult($query) || (($query['status'] ?? 'new') === 'running' && !empty($query['statements'])));
    if ($showResult) {
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
    if (!empty($query['unseenResult'])) {
      $query = self::$queryList->update(self::$connectionName, $id, ['unseenResult' => false]);
    }
    self::$queryList->setActive(self::$connectionName, $id);
    self::setTitleContext($query);
    self::$queryName->setText(self::formatQueryTitle($query));
    self::$searchSession = false;
    self::updateWorkArea($query);
    self::recalculateWorkArea();
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
    self::showResult($query);
    self::$updatingList = true;
    $index = self::$queryList->findIndex(self::$connectionName, $id);
    self::$list->moveCursor($index);
    self::$updatingList = false;
  }

  private static function recalculateWorkArea(): void {
    $screen = self::$editorContainer->findAncestorByType('Screen');
    if ($screen !== false) {
      $screen->recalculateGeometry();
    }
  }

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

  private static function resultTitleIndicator($query): string {
    $results = $query['results'] ?? [];
    if (!is_array($results) || count($results) < 2) {
      return '';
    }
    $active = max(0, min((int) ($query['activeResult'] ?? count($results) - 1), count($results) - 1));
    return ($active + 1) . '/' . count($results);
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
      $item->setRight(self::queryListMarker($query));
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

  private static function queryListMarker($query): string {
    if (($query['status'] ?? 'new') === 'running') {
      return 'run';
    }
    return !empty($query['unseenResult']) ? 'done' : '';
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

  public static function searchQuery() {
    if (self::$connectionName === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before searching a query.');
      return;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false) {
      \SPTK\Elements\WarningPanel::forge('No query selected!', 'Please select a query before searching it.');
      return;
    }
    self::$searchPanel->setValue(self::$searchPanelState);
    self::syncSearchPanel();
    self::$searchPanel->show();
    if (method_exists(self::$searchPanel, 'activateInput')) {
      self::$searchPanel->activateInput('search');
    }
    Element::refresh();
  }

  public static function syncSearchPanel($element = null) {
    $panel = self::$searchPanel;
    if ($panel === null) {
      return;
    }
    $replaceEnabled = false;
    $replaceCheckbox = Element::byName('replaceEnabled', $panel);
    if ($replaceCheckbox !== false) {
      $replaceEnabled = self::boolValue($replaceCheckbox->getValue());
    }
    $replaceRow = Element::byName('query-search-replace-row', $panel);
    if ($replaceRow !== false) {
      if ($replaceEnabled) {
        $replaceRow->show();
      } else {
        $replaceRow->hide();
      }
    }
    if ($panel->isDisplayed() && method_exists($panel, 'refreshInputList')) {
      $panel->refreshInputList($element);
    }
    Element::refresh();
  }

  public static function doSearchQuery($panel) {
    $values = $panel->getValue();
    self::$searchPanelState = self::normalizeSearchPanelState($values);
    $search = (string) ($values['search'] ?? '');
    $replaceEnabled = self::boolValue($values['replaceEnabled'] ?? false);
    $replace = (string) ($values['replace'] ?? '');
    $regexp = self::boolValue($values['regexp'] ?? false);
    $caseSensitive = self::boolValue($values['caseSensitive'] ?? false);
    $scope = self::selectedSearchScope($values);
    if ($search === '') {
      \SPTK\Elements\WarningPanel::forge('Missing search text', 'Please enter text to search for.');
      return;
    }
    $pattern = self::searchPattern($search, $regexp, $caseSensitive);
    if ($regexp && ($pattern === false || !self::validPattern($pattern))) {
      \SPTK\Elements\WarningPanel::forge('Invalid regexp', 'Please enter a valid regular expression.');
      return;
    }
    if ($replaceEnabled) {
      self::replaceInQuery($panel, $search, $replace, $regexp, $caseSensitive, $scope);
    } else {
      self::findInQuery($panel, $search, $regexp, $caseSensitive, $scope);
    }
  }

  private static function findInQuery($panel, $search, $regexp, $caseSensitive, $scope): void {
    $text = self::editorText();
    $state = self::captureEditorState();
    $matches = self::scopedMatches($text, self::allQueryMatches($text, $search, $regexp, $caseSensitive), $scope, $state);
    [$match, $index] = self::pickMatch($text, $matches, $scope, $state);
    if ($match === false) {
      self::clearSearchSession();
      \SPTK\Elements\WarningPanel::forge('Not found', 'No match was found in the current query.');
      return;
    }
    [$matchOffset, $matchLength] = $match;
    self::restoreEditorState(self::cursorStateForMatch($text, $matchOffset, $matchLength, $state));
    if ($scope === 'next' || $scope === 'previous') {
      self::clearSearchSession();
      $panel->hide();
      self::deactivateList();
      self::deactivateResult();
      self::activateEditor();
      Element::refresh();
      return;
    }
    self::$searchSession = [
      'search' => $search,
      'regexp' => $regexp,
      'caseSensitive' => $caseSensitive,
      'scope' => $scope,
      'matches' => $matches,
      'index' => $index,
      'text' => $text
    ];
    self::applySearchHighlights($text, self::searchHighlightMatches($matches, $scope, $index));
    $panel->hide();
    self::deactivateList();
    self::deactivateResult();
    self::activateEditor();
    Element::refresh();
  }

  private static function replaceInQuery($panel, $search, $replace, $regexp, $caseSensitive, $scope): void {
    $query = self::$connectionName === false ? false : self::$queryList->getActive(self::$connectionName);
    if ($query !== false && self::isLocked($query)) {
      \SPTK\Elements\WarningPanel::forge('Query is locked', 'This query has already started running and cannot be modified.');
      return;
    }
    $text = self::editorText();
    $state = self::captureEditorState();
    $matches = self::scopedMatches($text, self::allQueryMatches($text, $search, $regexp, $caseSensitive), $scope, $state);
    if ($scope === 'next' || $scope === 'previous') {
      [$match, $index] = self::pickMatch($text, $matches, $scope, $state);
      if ($match === false) {
        self::clearSearchSession();
        \SPTK\Elements\WarningPanel::forge('Not found', 'No match was found in the current query.');
        return;
      }
      [$matchOffset, $matchLength, $matchText] = $match;
      $replacement = $regexp ? preg_replace(self::searchPattern($search, true, $caseSensitive), $replace, $matchText, 1) : $replace;
      self::restoreEditorState(self::cursorStateForMatch($text, $matchOffset, $matchLength, $state));
      self::$editor->insertText($replacement);
      self::saveCurrentEditor();
      self::clearSearchSession();
      $panel->hide();
      self::deactivateList();
      self::deactivateResult();
      self::activateEditor();
      Element::refresh();
      return;
    }
    if (empty($matches)) {
      self::clearSearchSession();
      \SPTK\Elements\WarningPanel::forge('Not found', 'No match was found in the current query.');
      return;
    }
    $newText = $text;
    for ($i = count($matches) - 1; $i >= 0; $i--) {
      [$matchOffset, $matchLength, $matchText] = $matches[$i];
      $replacement = $regexp ? preg_replace(self::searchPattern($search, true, $caseSensitive), $replace, $matchText, 1) : $replace;
      $newText = substr($newText, 0, $matchOffset) . $replacement . substr($newText, $matchOffset + $matchLength);
    }
    self::replaceEditorText($newText);
    self::saveCurrentEditor();
    self::startSearchSession($search, $regexp, $caseSensitive, $scope);
    $panel->hide();
    self::deactivateList();
    self::deactivateResult();
    self::activateEditor();
    Element::refresh();
  }

  private static function startSearchSession($search, $regexp, $caseSensitive, $scope): void {
    $text = self::editorText();
    $state = self::captureEditorState();
    $matches = self::scopedMatches($text, self::allQueryMatches($text, $search, $regexp, $caseSensitive), $scope, $state);
    [$match, $index] = self::pickMatch($text, $matches, $scope, $state);
    if ($match === false) {
      self::clearSearchSession();
      return;
    }
    self::$searchSession = [
      'search' => $search,
      'regexp' => $regexp,
      'caseSensitive' => $caseSensitive,
      'scope' => $scope,
      'matches' => $matches,
      'index' => $index,
      'text' => $text
    ];
    self::applySearchHighlights($text, self::searchHighlightMatches($matches, $scope, $index));
  }

  private static function navigateSearchSession($delta): bool {
    if (self::$searchSession === false) {
      return false;
    }
    $text = self::editorText();
    if ((self::$searchSession['text'] ?? null) !== $text) {
      self::clearSearchSession();
      Element::refresh();
      return true;
    }
    $matches = self::$searchSession['matches'];
    if (empty($matches)) {
      self::clearSearchSession();
      Element::refresh();
      return true;
    }
    self::$searchSession['matches'] = $matches;
    $index = (int) (self::$searchSession['index'] ?? 0);
    $index = ($index + $delta) % count($matches);
    if ($index < 0) {
      $index = count($matches) - 1;
    }
    self::$searchSession['index'] = $index;
    $match = $matches[$index];
    self::restoreEditorState(self::cursorStateForMatch($text, $match[0], $match[1], self::captureEditorState()));
    self::applySearchHighlights($text, self::searchHighlightMatches($matches, self::$searchSession['scope'], $index));
    self::deactivateList();
    self::deactivateResult();
    self::activateEditor();
    Element::refresh();
    return true;
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
      \SPTK\Elements\WarningPanel::forge('Query is running', 'This query is still running and cannot be cleared.');
      return;
    }
    if (!self::shouldWarnBeforeClear($query)) {
      self::doClearQuery();
      return;
    }
    \SPTK\Elements\WarningPanel::forge(
      'Clear query',
      "Clear query '" . ($query['name'] ?? 'NEW') . "' and its result set? This cannot be undone, but Revert can restore the loaded query text.",
      [
        ['text' => 'Clear', 'hotKey' => 'RETURN', 'onPress' => '\MADB\Main\ScreenController::doClearQuery'],
        ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close']
      ]
    );
    Element::refresh();
  }

  public static function editQuery() {
    if (self::$connectionName === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before editing a query.');
      return;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false) {
      \SPTK\Elements\WarningPanel::forge('No query selected!', 'Please select a query before editing it.');
      return;
    }
    if (self::isLocked($query)) {
      \SPTK\Elements\WarningPanel::forge('Query is running', 'This query is still running and cannot be edited.');
      return;
    }
    if (!self::shouldWarnBeforeClear($query)) {
      self::doEditQuery();
      return;
    }
    \SPTK\Elements\WarningPanel::forge(
      'Edit query',
      "Edit query '" . ($query['name'] ?? 'NEW') . "' and clear its result set?",
      [
        ['text' => 'Edit', 'hotKey' => 'RETURN', 'onPress' => '\MADB\Main\ScreenController::doEditQuery'],
        ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close']
      ]
    );
    Element::refresh();
  }

  public static function doEditQuery($confirmationPanel = null) {
    if (self::$connectionName === false) {
      return;
    }
    self::saveCurrentEditor();
    $activeId = self::$queryList->getActiveId(self::$connectionName);
    if ($activeId === false) {
      return;
    }
    $query = self::$queryList->get(self::$connectionName, $activeId);
    if ($query !== false && self::isLocked($query)) {
      return;
    }
    if ($query !== false) {
      self::clearQueryResults($query);
    }
    $query = self::$queryList->update(self::$connectionName, $activeId, [
      'status' => 'new',
      'result' => false,
      'resultFile' => false,
      'statements' => [],
      'results' => [],
      'activeResult' => 0,
      'statusVisible' => false,
      'error' => false,
      'info' => []
    ]);
    if ($confirmationPanel !== null) {
      $confirmationPanel->remove();
    }
    if ($query !== false) {
      self::showQuery($activeId);
    }
    self::deactivateList();
    self::deactivateResult();
    self::activateEditor();
    Element::refresh();
  }

  public static function doClearQuery($confirmationPanel = null) {
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
    if ($query !== false) {
      self::clearQueryResults($query);
    }
    $query = self::$queryList->update(self::$connectionName, $activeId, [
      'sql' => '',
      'status' => 'new',
      'result' => false,
      'resultFile' => false,
      'statements' => [],
      'results' => [],
      'activeResult' => 0,
      'statusVisible' => false,
      'error' => false,
      'info' => []
    ]);
    unset(self::$editorStates[self::$connectionName][$activeId]);
    if ($confirmationPanel !== null) {
      $confirmationPanel->remove();
    }
    if ($query !== false) {
      self::showQuery($activeId);
    }
    self::deactivateList();
    self::deactivateResult();
    self::activateEditor();
    Element::refresh();
  }

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
    self::confirmExecuteStatements(false);
  }

  public static function executeCurrentQuery() {
    self::confirmExecuteStatements(true);
  }

  public static function doExecuteQuery($confirmationPanel = null) {
    if ($confirmationPanel !== null) {
      $confirmationPanel->remove();
    }
    self::executeStatements(false);
  }

  public static function doExecuteCurrentQuery($confirmationPanel = null) {
    if ($confirmationPanel !== null) {
      $confirmationPanel->remove();
    }
    self::executeStatements(true);
  }

  private static function confirmExecuteStatements($currentOnly) {
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
    if (!self::hasResult($query) || !self::shouldWarnBeforeClear($query)) {
      self::executeStatements($currentOnly);
      return;
    }
    \SPTK\Elements\WarningPanel::forge(
      $currentOnly ? 'Execute query' : 'Execute queries',
      "Execute query '" . ($query['name'] ?? 'NEW') . "' and replace its result set?",
      [
        ['text' => 'Execute', 'hotKey' => 'RETURN', 'onPress' => $currentOnly ? '\MADB\Main\ScreenController::doExecuteCurrentQuery' : '\MADB\Main\ScreenController::doExecuteQuery'],
        ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close']
      ]
    );
    Element::refresh();
  }

  private static function executeStatements($currentOnly) {
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
    $sql = self::editorText();
    $allStatements = SqlSplitter::split($sql);
    foreach ($allStatements as $index => $statement) {
      $allStatements[$index]['index'] = $index;
    }
    if (empty($allStatements)) {
      \SPTK\Elements\WarningPanel::forge('Query is empty', 'Please enter a query before executing it.');
      return;
    }
    $activeStatement = 0;
    $statements = $allStatements;
    if ($currentOnly) {
      $statement = SqlSplitter::statementAt($sql, self::byteOffsetFromCursorState($sql, self::captureEditorState()));
      if ($statement === false) {
        \SPTK\Elements\WarningPanel::forge('Query is empty', 'Please enter a query before executing it.');
        return;
      }
      foreach ($allStatements as $index => $candidate) {
        if (($candidate['start'] ?? false) === ($statement['start'] ?? null) && ($candidate['end'] ?? false) === ($statement['end'] ?? null)) {
          $activeStatement = $index;
          break;
        }
      }
      $statements = [$allStatements[$activeStatement]];
    }
    $pendingStatements = [];
    $startedAt = microtime(true);
    foreach ($allStatements as $statement) {
      $index = $statement['index'] ?? count($pendingStatements);
      $willRun = !$currentOnly || $index === $activeStatement;
      $pendingStatements[] = [
        'index' => $index,
        'sql' => trim((string) ($statement['sql'] ?? '')),
        'status' => $willRun ? 'PENDING' : 'NOT RUN',
        'startedAt' => $willRun ? $startedAt : false,
        'range' => [
          'start' => $statement['start'] ?? 0,
          'end' => $statement['end'] ?? 0
        ]
      ];
    }
    self::saveCurrentEditor();
    $query = self::$queryList->getActive(self::$connectionName);
    $keptResults = [];
    if ($currentOnly) {
      foreach (($query['results'] ?? []) as $result) {
        if ((int) ($result['statementIndex'] ?? -1) === $activeStatement) {
          ResultStore::delete($result['file'] ?? false);
          continue;
        }
        $keptResults[] = $result;
      }
      $pendingStatements = self::preserveStatementResults($pendingStatements, $query['statements'] ?? [], $activeStatement);
    } else {
      ResultStore::delete($query['resultFile'] ?? false);
      ResultStore::deleteMany($query['results'] ?? []);
    }
    $resultFile = ResultStore::relativePath(self::$connectionName, $query['id']);
    $resultFiles = [];
    foreach ($statements as $index => $statement) {
      $resultFileIndex = $currentOnly ? ($statement['index'] ?? $index) : $index;
      $resultFiles[] = ResultStore::absolutePath(ResultStore::relativePathForResult(self::$connectionName, $query['id'], $resultFileIndex));
    }
    $query = self::$queryList->update(self::$connectionName, $query['id'], [
      'status' => 'running',
      'result' => false,
      'resultFile' => $resultFile,
      'statements' => $pendingStatements,
      'results' => $keptResults,
      'activeResult' => 0,
      'activeStatement' => $activeStatement,
      'unseenResult' => false,
      'statusVisible' => true,
      'error' => false,
      'info' => []
    ]);
    self::renderList();
    self::showQuery($query['id']);
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'queryBatch',
      'arguments' => [$statements, $resultFiles],
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
    if (!empty($response['progress']) && is_array($response['result'] ?? false) && isset($response['result']['statements'])) {
      self::queryBatchProgress($connectionName, $queryId, $response);
      return;
    }
    if ($response['status'] === 'OK' && is_array($response['result'] ?? false) && isset($response['result']['statements'])) {
      self::queryBatchResult($connectionName, $queryId, $response);
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
    $isActive = self::$connectionName === $connectionName && self::$queryList->getActiveId($connectionName) === $queryId;
    $updates = [
      'status' => $response['status'] === 'OK' ? 'ok' : 'error',
      'result' => $result,
      'resultFile' => $resultFile,
      'unseenResult' => !$isActive,
      'error' => $response['status'] === 'OK' ? false : $response['result'],
      'info' => [
        'pid' => $response['pid'] ?? false,
        'times' => $response['times'] ?? []
      ]
    ];
    self::$queryList->update($connectionName, $queryId, $updates);
    if (self::$connectionName === $connectionName) {
      self::renderList();
      if ($isActive) {
        self::showQuery($queryId);
      }
      Element::refresh();
    }
  }

  private static function queryBatchProgress($connectionName, $queryId, $response): void {
    $query = self::$queryList->get($connectionName, $queryId);
    if ($query === false) {
      return;
    }
    $statements = self::mergeStatements($query['statements'] ?? [], $response['result']['statements'] ?? []);
    $results = self::batchResults($connectionName, $queryId, $statements);
    $activeStatement = self::runningStatementIndex($statements, $query['activeStatement'] ?? 0);
    $activeResult = $query['activeResult'] ?? 0;
    $resultOffset = self::resultOffsetForStatement($results, $activeStatement);
    if ($resultOffset !== false) {
      $activeResult = $resultOffset;
    }
    self::$queryList->update($connectionName, $queryId, [
      'status' => 'running',
      'result' => [
        'statements' => $statements,
        'results' => $results
      ],
      'statements' => $statements,
      'results' => $results,
      'activeResult' => $activeResult,
      'activeStatement' => $activeStatement,
      'statusVisible' => true,
      'info' => [
        'pid' => $response['pid'] ?? false,
        'times' => $response['times'] ?? []
      ]
    ]);
    if (self::$connectionName === $connectionName && self::$queryList->getActiveId($connectionName) === $queryId) {
      self::renderList();
      self::showQuery($queryId);
      Element::refresh();
    }
  }

  private static function queryBatchResult($connectionName, $queryId, $response): void {
    $query = self::$queryList->get($connectionName, $queryId);
    if ($query === false) {
      return;
    }
    $statements = self::mergeStatements($query['statements'] ?? [], $response['result']['statements'] ?? []);
    $results = self::batchResults($connectionName, $queryId, $statements);
    $hasError = false;
    foreach ($statements as $statement) {
      if (($statement['status'] ?? '') === 'ERROR') {
        $hasError = true;
      }
    }
    $activeResult = empty($results) ? 0 : count($results) - 1;
    $activeStatement = self::lastReturnedStatementIndex($response['result']['statements'] ?? [], $query['activeStatement'] ?? 0);
    $isActive = self::$connectionName === $connectionName && self::$queryList->getActiveId($connectionName) === $queryId;
    $updates = [
      'status' => $hasError ? 'error' : 'ok',
      'result' => [
        'statements' => $statements,
        'results' => $results
      ],
      'resultFile' => false,
      'statements' => $statements,
      'results' => $results,
      'activeResult' => $activeResult,
      'activeStatement' => $activeStatement,
      'unseenResult' => !$isActive,
      'statusVisible' => empty($results),
      'error' => $hasError ? self::firstBatchError($statements) : false,
      'info' => [
        'pid' => $response['pid'] ?? false,
        'times' => $response['times'] ?? []
      ]
    ];
    self::$queryList->update($connectionName, $queryId, $updates);
    if (self::$connectionName === $connectionName) {
      self::renderList();
      if ($isActive) {
        self::showQuery($queryId);
      }
      Element::refresh();
    }
  }

  private static function mergeStatements($storedStatements, $returnedStatements): array {
    $merged = [];
    foreach (is_array($storedStatements) ? $storedStatements : [] as $statement) {
      $index = (int) ($statement['index'] ?? count($merged));
      $merged[$index] = $statement;
      $merged[$index]['index'] = $index;
    }
    foreach (is_array($returnedStatements) ? $returnedStatements : [] as $statement) {
      $index = (int) ($statement['index'] ?? count($merged));
      $merged[$index] = array_merge($merged[$index] ?? [], $statement, ['index' => $index]);
    }
    ksort($merged);
    return array_values($merged);
  }

  private static function preserveStatementResults($newStatements, $oldStatements, int $activeStatement): array {
    $oldByIndex = [];
    foreach (is_array($oldStatements) ? $oldStatements : [] as $statement) {
      $oldByIndex[(int) ($statement['index'] ?? count($oldByIndex))] = $statement;
    }
    foreach ($newStatements as $offset => $statement) {
      $index = (int) ($statement['index'] ?? $offset);
      if ($index === $activeStatement || !isset($oldByIndex[$index])) {
        continue;
      }
      foreach (['status', 'result', 'resultIndex', 'startedAt', 'time', 'finishedAt', 'error'] as $key) {
        if (array_key_exists($key, $oldByIndex[$index])) {
          $newStatements[$offset][$key] = $oldByIndex[$index][$key];
        }
      }
    }
    return $newStatements;
  }

  private static function lastReturnedStatementIndex($statements, $fallback = 0): int {
    $index = (int) $fallback;
    foreach (is_array($statements) ? $statements : [] as $statement) {
      $index = (int) ($statement['index'] ?? $index);
    }
    return $index;
  }

  private static function runningStatementIndex($statements, $fallback = 0): int {
    foreach (is_array($statements) ? $statements : [] as $statement) {
      if (($statement['status'] ?? '') === 'RUNNING') {
        return (int) ($statement['index'] ?? $fallback);
      }
    }
    return (int) $fallback;
  }

  private static function batchResults($connectionName, $queryId, $statements): array {
    $results = [];
    foreach ($statements as $statement) {
      if (!isset($statement['resultIndex'], $statement['result']) || !is_array($statement['result'])) {
        continue;
      }
      $resultIndex = (int) $statement['resultIndex'];
      $statementIndex = (int) ($statement['index'] ?? $resultIndex);
      $result = $statement['result'];
      if (isset($result['columns'], $result['rowCount']) && empty($result['file'])) {
        $result['file'] = ResultStore::relativePathForResult($connectionName, $queryId, $resultIndex);
      }
      $results[$statementIndex] = [
        'index' => $statementIndex,
        'resultIndex' => $resultIndex,
        'statementIndex' => $statementIndex,
        'range' => $statement['range'] ?? ['start' => 0, 'end' => 0],
        'result' => $result,
        'file' => $result['file'] ?? false,
        'columns' => $result['columns'] ?? [],
        'rowCount' => $result['rowCount'] ?? (isset($result['rows']) ? count($result['rows']) : 0)
      ];
    }
    ksort($results);
    return array_values($results);
  }

  private static function firstBatchError($statements) {
    foreach ($statements as $statement) {
      if (($statement['status'] ?? '') === 'ERROR') {
        return $statement['error'] ?? 'Unknown error';
      }
    }
    return false;
  }

  private static function clearResult($clearHighlight = true) {
    self::$resultMessage->setText('');
    self::$resultMessage->hide();
    self::$resultStatus->setText('');
    self::$resultStatus->hide();
    self::$resultTable->hide();
    if ($clearHighlight) {
      self::clearResultHighlight();
    }
  }

  private static function showResult($query) {
    self::clearResult(false);
    if (($query['status'] ?? 'new') === 'running' && !empty($query['statements']) && is_array($query['statements'])) {
      self::$resultStatus->setText(self::formatBatchStatus($query));
      self::$resultStatus->show();
      $activeStatement = $query['activeStatement'] ?? false;
      $statement = $activeStatement === false ? false : self::statementByIndex($query['statements'], (int) $activeStatement);
      if ($statement !== false && self::shouldHighlightStatementSource($query)) {
        self::highlightResultSource(['range' => $statement['range'] ?? false]);
      } else {
        self::clearResultHighlight();
      }
      return;
    }
    $results = $query['results'] ?? [];
    if (is_array($results) && !empty($results)) {
      $activeStatement = $query['activeStatement'] ?? false;
      $statement = false;
      if ($activeStatement !== false) {
        $statement = self::statementByIndex($query['statements'] ?? [], (int) $activeStatement);
        if ($statement !== false && in_array(($statement['status'] ?? ''), ['PENDING', 'RUNNING'])) {
          self::$resultStatus->setText(self::formatStatementStatus($statement));
          self::$resultStatus->show();
          if (self::shouldHighlightStatementSource($query)) {
            self::highlightResultSource(['range' => $statement['range'] ?? false]);
          } else {
            self::clearResultHighlight();
          }
          return;
        }
      }
      $statusVisible = !empty($query['statusVisible']);
      if ($statusVisible) {
        self::$resultStatus->setText(self::formatBatchStatus($query));
        self::$resultStatus->show();
        if ($statement !== false && self::shouldHighlightStatementSource($query)) {
          self::highlightResultSource(['range' => $statement['range'] ?? false]);
        } else {
          self::clearResultHighlight();
        }
        return;
      }
      $entry = false;
      if ($activeStatement !== false) {
        $statement = self::statementByIndex($query['statements'] ?? [], (int) $activeStatement);
        $entry = self::resultForStatement($results, (int) $activeStatement);
      }
      if ($entry === false && ($activeStatement === false || $statement === false)) {
        $active = max(0, min((int) ($query['activeResult'] ?? count($results) - 1), count($results) - 1));
        $entry = $results[$active] ?? false;
        if (is_array($entry)) {
          $statement = self::statementByIndex($query['statements'] ?? [], (int) ($entry['statementIndex'] ?? $active));
        }
      }
      $result = is_array($entry) ? ($entry['result'] ?? false) : false;
      if (is_array($result) && isset($result['columns'], $result['rowCount'], $result['file'])) {
        $file = ResultStore::absolutePath($result['file']);
        if ($file !== false && file_exists($file)) {
          self::$resultTable->setFile($file);
          self::$resultTable->show();
          if (self::shouldHighlightResultSource($query, $entry)) {
            self::highlightResultSource($entry);
          }
          self::syncResultTableHeader();
          return;
        }
      }
      if ($statement !== false) {
        self::$resultStatus->setText(self::formatStatementStatus($statement));
        self::$resultStatus->show();
        if (self::shouldHighlightStatementSource($query)) {
          self::highlightResultSource(['range' => $statement['range'] ?? false]);
        } else {
          self::clearResultHighlight();
        }
        return;
      }
      self::$resultStatus->setText(self::formatBatchStatus($query));
      self::$resultStatus->show();
      self::clearResultHighlight();
      return;
    }
    if (!empty($query['statements']) && is_array($query['statements'])) {
      $activeStatement = $query['activeStatement'] ?? false;
      if ($activeStatement !== false) {
        $statement = self::statementByIndex($query['statements'], (int) $activeStatement);
        if ($statement !== false) {
          self::$resultStatus->setText(self::formatStatementStatus($statement));
          self::$resultStatus->show();
          if (self::shouldHighlightStatementSource($query)) {
            self::highlightResultSource(['range' => $statement['range'] ?? false]);
          } else {
            self::clearResultHighlight();
          }
          return;
        }
      }
      self::$resultStatus->setText(self::formatBatchStatus($query));
      self::$resultStatus->show();
      self::clearResultHighlight();
      return;
    }
    $result = $query['result'] ?? false;
    if (is_array($result) && isset($result['columns'], $result['rowCount'], $result['file'])) {
      $file = ResultStore::absolutePath($result['file']);
      if ($file !== false && file_exists($file)) {
        self::$resultTable->setFile($file);
        self::$resultTable->show();
        self::syncResultTableHeader();
        self::clearResultHighlight();
        return;
      }
    }
    $text = self::formatResult($query);
    if ($text !== '') {
      self::$resultMessage->setText($text);
      self::$resultMessage->show();
    }
    self::clearResultHighlight();
  }

  private static function statementByIndex($statements, int $index) {
    foreach (is_array($statements) ? $statements : [] as $statement) {
      if ((int) ($statement['index'] ?? -1) === $index) {
        return $statement;
      }
    }
    return false;
  }

  private static function resultForStatement($results, int $statementIndex) {
    foreach (is_array($results) ? $results : [] as $result) {
      if ((int) ($result['statementIndex'] ?? -1) === $statementIndex) {
        return $result;
      }
    }
    return false;
  }

  private static function resultOffsetForStatement($results, int $statementIndex) {
    foreach (is_array($results) ? $results : [] as $offset => $result) {
      if ((int) ($result['statementIndex'] ?? -1) === $statementIndex) {
        return (int) $offset;
      }
    }
    return false;
  }

  private static function formatStatementStatus($statement): string {
    $index = (int) ($statement['index'] ?? 0);
    $status = $statement['status'] ?? 'NOT RUN';
    if ($status === 'NOT RUN') {
      return "#{$index} NOT RUN\nThis query has not been executed yet.";
    }
    $lines = ["#{$index} {$status}"];
    if (!empty($statement['startedAt'])) {
      $lines[] = 'Started: ' . date('Y-m-d H:i:s', (int) $statement['startedAt']);
    }
    if (in_array($status, ['RUNNING', 'PENDING']) && !empty($statement['startedAt'])) {
      $lines[] = 'Running: ' . self::formatDuration(microtime(true) - (float) $statement['startedAt']);
    }
    if (isset($statement['finishedAt'])) {
      $lines[] = 'Finished: ' . date('Y-m-d H:i:s', (int) $statement['finishedAt']);
    }
    if (isset($statement['result']['affectedRows'])) {
      $lines[0] .= ' affected rows: ' . $statement['result']['affectedRows'];
    } else if (isset($statement['result']['rowCount'])) {
      $lines[0] .= ' rows: ' . $statement['result']['rowCount'];
    } else if (isset($statement['time'])) {
      $lines[0] .= ' time: ' . $statement['time'] . 's';
    }
    if ($status === 'ERROR') {
      $lines[] = 'ERROR: ' . ($statement['error'] ?? 'Unknown error');
    }
    return implode("\n", $lines);
  }

  private static function formatDuration($seconds): string {
    return round(max(0, (float) $seconds), 4) . 's';
  }

  private static function shouldHighlightResultSource($query, $entry): bool {
    if (!is_array($entry) || empty($entry['range']) || !is_array($entry['range'])) {
      return false;
    }
    return self::shouldHighlightStatementSource($query);
  }

  private static function shouldHighlightStatementSource($query): bool {
    if (count($query['statements'] ?? []) > 1) {
      return true;
    }
    return count(SqlSplitter::split(self::editorText())) > 1;
  }

  private static function highlightResultSource($entry): void {
    if (!method_exists(self::$editor, 'setHighlightRanges')) {
      return;
    }
    $range = $entry['range'] ?? false;
    if (!is_array($range) || !isset($range['start'], $range['end'])) {
      return;
    }
    $text = self::editorText();
    $start = self::positionFromByteOffset($text, (int) $range['start']);
    $end = self::positionFromByteOffset($text, (int) $range['end']);
    self::$editor->setHighlightRanges([[$start[0], $start[1], $end[0], $end[1]]]);
    if (method_exists(self::$editor, 'setCursorPosition')) {
      self::$editor->setCursorPosition($start[0], $start[1]);
    }
  }

  private static function clearResultHighlight(): void {
    if (self::$searchSession === false && self::$editor !== null && method_exists(self::$editor, 'clearHighlightRanges')) {
      self::$editor->clearHighlightRanges();
    }
  }

  private static function syncResultTableHeader() {
    self::setResultTableHeaderActive(self::$activeBox === self::RESULT);
  }

  private static function setResultTableHeaderActive($active) {
    if (self::$resultTable === null || self::$resultTable === false) {
      return;
    }
    if ($active) {
      self::$resultTable->addVariant('active');
    } else {
      self::$resultTable->removeVariant('active');
    }
  }

  private static function formatResult($query) {
    $status = $query['status'] ?? 'new';
    if ($status === 'running') {
      return 'Running...';
    }
    if (!empty($query['statements']) && is_array($query['statements'])) {
      return self::formatBatchStatus($query);
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

  private static function formatBatchStatus($query): string {
    $lines = [];
    foreach (($query['statements'] ?? []) as $statement) {
      $index = (int) ($statement['index'] ?? count($lines));
      $prefix = '#' . $index . ' ' . ($statement['status'] ?? 'OK');
      if (isset($statement['result']['affectedRows'])) {
        $prefix .= ' affected rows: ' . $statement['result']['affectedRows'];
      } else if (isset($statement['result']['rowCount'])) {
        $prefix .= ' rows: ' . $statement['result']['rowCount'];
      } else if (isset($statement['result']['rows'])) {
        $prefix .= ' rows: ' . count($statement['result']['rows']);
      }
      if (isset($statement['time'])) {
        $prefix .= ' time: ' . $statement['time'] . 's';
      }
      if (!empty($statement['startedAt'])) {
        $prefix .= ' started: ' . date('Y-m-d H:i:s', (int) $statement['startedAt']);
      }
      if (in_array(($statement['status'] ?? ''), ['RUNNING', 'PENDING']) && !empty($statement['startedAt'])) {
        $prefix .= ' running: ' . self::formatDuration(microtime(true) - (float) $statement['startedAt']);
      }
      if (isset($statement['finishedAt'])) {
        $prefix .= ' finished: ' . date('Y-m-d H:i:s', (int) $statement['finishedAt']);
      }
      if (($statement['status'] ?? '') === 'ERROR') {
        $prefix .= ' ERROR: ' . ($statement['error'] ?? 'Unknown error');
      }
      $sql = trim(preg_replace('/\s+/', ' ', (string) ($statement['sql'] ?? '')));
      if ($sql !== '') {
        $prefix .= "\n  " . mb_substr($sql, 0, 160);
      }
      $lines[] = $prefix;
    }
    $info = self::formatInfo($query);
    if ($info !== '') {
      $lines[] = $info;
    }
    return implode("\n", $lines);
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

  private static function switchResult($index): bool {
    if (self::$connectionName === false) {
      return false;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false) {
      return false;
    }
    $index = (int) $index;
    $statements = $query['statements'] ?? [];
    $updates = [
      'statusVisible' => false
    ];
    if (is_array($statements) && !empty($statements)) {
      if (self::statementByIndex($statements, $index) === false) {
        return false;
      }
      $updates['activeStatement'] = $index;
      $resultOffset = self::resultOffsetForStatement($query['results'] ?? [], $index);
      if ($resultOffset !== false) {
        $updates['activeResult'] = $resultOffset;
      }
    } else {
      if (empty($query['results']) || !is_array($query['results']) || $index < 0 || $index >= count($query['results'])) {
        return false;
      }
      $updates['activeResult'] = $index;
    }
    $query = self::$queryList->update(self::$connectionName, $query['id'], $updates);
    self::showQuery($query['id']);
    self::activateResult();
    Element::refresh();
    return true;
  }

  private static function toggleResultStatus(): bool {
    if (self::$connectionName === false) {
      return false;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false || empty($query['statements']) || !is_array($query['statements'])) {
      return false;
    }
    $query = self::$queryList->update(self::$connectionName, $query['id'], [
      'statusVisible' => empty($query['statusVisible'])
    ]);
    self::showQuery($query['id']);
    self::activateResult();
    Element::refresh();
    return true;
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
      switch ($key) {
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
    if (self::$activeBox === self::RESULT && ($mod & (KeyModifier::CTRL | KeyModifier::SHIFT | KeyModifier::ALT | KeyModifier::GUI)) === 0) {
      if ($key >= KeyCode::NUM_0 && $key <= KeyCode::NUM_9) {
        return self::switchResult($key - KeyCode::NUM_0);
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
