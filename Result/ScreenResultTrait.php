<?php

namespace MADB\Result;

use \SPTK\Element;
use \MADB\Result\ResultStore;

/** Renders query results and batch statement status in the result panel, including source-statement highlighting. */
trait ScreenResultTrait {

  /** Clears result state from the query workspace. */
  private static function clearResult($clearHighlight = true) {
    self::clearPendingResultLoad();
    self::abortPendingResultFilter();
    self::clearResultSearchSession();
    self::clearResultFilterState();
    self::$resultMessage->setText('');
    self::$resultMessage->hide();
    self::$resultStatus->setText('');
    self::$resultStatus->hide();
    self::hideResultFastPreview();
    self::$resultTable->hide();
    if ($clearHighlight) {
      self::clearResultHighlight();
    }
  }

  /** Coordinates show result work in the query workspace. */
  private static function showResult($query, bool $preserveStatusState = false) {
    self::applyResultInfoMenu();
    $statusState = self::resultStatusState($preserveStatusState);
    self::clearResult(false);
    if (($query['status'] ?? 'new') === 'running' && !empty($query['statements']) && is_array($query['statements'])) {
      $activeStatement = $query['activeStatement'] ?? false;
      $statement = $activeStatement === false ? false : self::statementByIndex($query['statements'], (int) $activeStatement);
      self::setResultStatusText(self::formatRunningBatchStatus($query, $statement), $statusState);
      self::$resultStatus->show();
      if (self::isSmallQueryBatch($query) && $statement !== false && self::shouldHighlightStatementSource($query)) {
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
          self::setResultStatusText(self::formatStatementStatus($statement), $statusState);
          self::$resultStatus->show();
          if (self::shouldHighlightStatementSource($query)) {
            self::highlightResultSource(['range' => $statement['range'] ?? false]);
          } else {
            self::clearResultHighlight();
          }
          return;
        }
      }
      if (self::$resultInfoVisible) {
        self::setResultStatusText(self::formatBatchStatus($query), $statusState);
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
          self::showResultFile($query, $entry, $result, $file);
          if (self::shouldHighlightResultSource($query, $entry)) {
            self::highlightResultSource($entry);
          }
          return;
        }
      }
      if ($statement !== false) {
        self::setResultStatusText(self::formatStatementStatus($statement), $statusState);
        self::$resultStatus->show();
        if (self::shouldHighlightStatementSource($query)) {
          self::highlightResultSource(['range' => $statement['range'] ?? false]);
        } else {
          self::clearResultHighlight();
        }
        return;
      }
      self::setResultStatusText(self::formatBatchStatus($query), $statusState);
      self::$resultStatus->show();
      self::clearResultHighlight();
      return;
    }
    if (!empty($query['statements']) && is_array($query['statements'])) {
      $activeStatement = $query['activeStatement'] ?? false;
      if (self::$resultInfoVisible) {
        self::setResultStatusText(self::formatBatchStatus($query), $statusState);
        self::$resultStatus->show();
        $statement = $activeStatement === false ? false : self::statementByIndex($query['statements'], (int) $activeStatement);
        if ($statement !== false && self::shouldHighlightStatementSource($query)) {
          self::highlightResultSource(['range' => $statement['range'] ?? false]);
        } else {
          self::clearResultHighlight();
        }
        return;
      }
      if ($activeStatement !== false) {
        $statement = self::statementByIndex($query['statements'], (int) $activeStatement);
        if ($statement !== false) {
          self::setResultStatusText(self::formatStatementStatus($statement), $statusState);
          self::$resultStatus->show();
          if (self::shouldHighlightStatementSource($query)) {
            self::highlightResultSource(['range' => $statement['range'] ?? false]);
          } else {
            self::clearResultHighlight();
          }
          return;
        }
      }
      self::setResultStatusText(self::formatBatchStatus($query), $statusState);
      self::$resultStatus->show();
      self::clearResultHighlight();
      return;
    }
    $result = $query['result'] ?? false;
    if (is_array($result) && isset($result['columns'], $result['rowCount'], $result['file'])) {
      $file = ResultStore::absolutePath($result['file']);
      if ($file !== false && file_exists($file)) {
        self::showResultFile($query, false, $result, $file);
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

  /** Returns the current status TextBox scroll/cursor state when progress rendering should preserve it. */
  private static function resultStatusState(bool $preserveStatusState) {
    if (!$preserveStatusState || self::$resultStatus === false || !method_exists(self::$resultStatus, 'saveState')) {
      return false;
    }
    return self::$resultStatus->saveState();
  }

  /** Updates the status TextBox without forcing scroll back to the first line during progress refreshes. */
  private static function setResultStatusText(string $text, $state = false): void {
    if (is_array($state) && method_exists(self::$resultStatus, 'setValueAndState')) {
      self::$resultStatus->setValueAndState($text, $state);
      return;
    }
    self::$resultStatus->setText($text);
  }

  /** Shows small result files immediately and defers large result files until list movement is idle. */
  private static function showResultFile($query, $entry, $result, string $file): void {
    $size = filesize($file) ?: 0;
    if ($size <= self::IMMEDIATE_RESULT_BYTES) {
      self::loadResultFile($file);
      return;
    }
    self::scheduleResultFileLoad($query, $entry, $result, $file, $size);
  }

  /** Loads a result file into the table widget. */
  private static function loadResultFile(string $file, bool $clearFilter = true): void {
    self::$resultSearchSession = false;
    if ($clearFilter) {
      self::clearResultFilterState();
    }
    self::$resultTableFile = $file;
    self::applyResultRowNumbers();
    self::$resultTable->setFile($file);
    self::$resultTable->show();
    self::syncResultTableHeader();
    self::syncResultFastPreview();
  }

  /** Returns metadata for the active result when it belongs to a table-backed query. */
  public static function activeResultTableContext() {
    if (
      self::$connectionName === false ||
      self::$resultTable === null ||
      self::$resultTable === false ||
      !self::$resultTable->isDisplayed()
    ) {
      return false;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false) {
      return false;
    }
    $result = false;
    if (!empty($query['results']) && is_array($query['results'])) {
      $activeStatement = $query['activeStatement'] ?? false;
      $entry = false;
      if ($activeStatement !== false) {
        $entry = self::resultForStatement($query['results'], (int)$activeStatement);
      }
      if ($entry === false) {
        $active = max(0, min((int)($query['activeResult'] ?? count($query['results']) - 1), count($query['results']) - 1));
        $entry = $query['results'][$active] ?? false;
      }
      $result = is_array($entry) ? ($entry['result'] ?? false) : false;
    } else {
      $result = $query['result'] ?? false;
    }
    if (!is_array($result) || !isset($result['columns'], $result['rowCount'], $result['file'])) {
      return false;
    }
    $tableContext = self::resultTableContextFromQuery($query);
    if ($tableContext === false) {
      return false;
    }
    return [
      'connectionName' => self::$connectionName,
      'queryId' => $query['id'] ?? false,
      'schema' => $tableContext['schema'],
      'table' => $tableContext['table'],
      'columns' => array_values($result['columns'])
    ];
  }

  /** Returns stored or inferred single-table context for a result query. */
  private static function resultTableContextFromQuery(array $query) {
    $sql = self::activeResultStatementSql($query);
    $defaultSchema = self::currentPrimary($query);
    $inferred = self::singleCollectionContextFromMongoCommand($sql, $defaultSchema);
    if ($inferred !== false) {
      return $inferred;
    }
    $inferred = self::singleTableContextFromSql($sql, $defaultSchema);
    if ($inferred !== false) {
      return $inferred;
    }
    if (($query['primary'] ?? '') !== '' && ($query['secondary'] ?? '') !== '') {
      return [
        'schema' => $query['primary'],
        'table' => $query['secondary']
      ];
    }
    return false;
  }

  /** Infers a collection context from a MongoDB command document. */
  private static function singleCollectionContextFromMongoCommand(string $text, string $defaultSchema = '') {
    $text = trim($text);
    if ($text === '' || $text[0] !== '{') {
      return false;
    }
    $decoded = json_decode($text, true);
    if (!is_array($decoded) || array_is_list($decoded)) {
      return false;
    }
    $collection = false;
    foreach (['find', 'aggregate', 'update', 'delete', 'insert'] as $command) {
      if (isset($decoded[$command]) && is_string($decoded[$command]) && $decoded[$command] !== '') {
        $collection = $decoded[$command];
        break;
      }
    }
    if ($collection === false || $defaultSchema === '') {
      return false;
    }
    return [
      'schema' => $defaultSchema,
      'table' => $collection
    ];
  }

  /** Returns the SQL statement that produced the currently active result. */
  private static function activeResultStatementSql(array $query): string {
    $statements = $query['statements'] ?? [];
    if (is_array($statements) && !empty($statements)) {
      $activeStatement = (int)($query['activeStatement'] ?? 0);
      foreach ($statements as $statement) {
        if ((int)($statement['index'] ?? -1) === $activeStatement) {
          return trim((string)($statement['sql'] ?? ''));
        }
      }
    }
    return trim((string)($query['text'] ?? ''));
  }

  /** Infers a table context from a conservative single-table SELECT statement. */
  private static function singleTableContextFromSql(string $sql, string $defaultSchema = '') {
    $fromOffset = self::topLevelKeywordOffset($sql, 'FROM');
    if ($fromOffset === false) {
      return false;
    }
    $tableSql = trim(substr($sql, $fromOffset + 4));
    $clauseOffset = self::firstTopLevelClauseOffset($tableSql, ['WHERE', 'GROUP BY', 'ORDER BY', 'HAVING', 'LIMIT', 'UNION', 'FOR', 'LOCK']);
    if ($clauseOffset !== false) {
      $tableSql = trim(substr($tableSql, 0, $clauseOffset));
    }
    $tableSql = rtrim($tableSql, " \t\r\n;");
    if ($tableSql === '' || $tableSql[0] === '(' || self::hasTopLevelSeparator($tableSql, ',') || self::topLevelKeywordOffset($tableSql, 'JOIN') !== false) {
      return false;
    }
    $identifier = '`(?:``|[^`])+`|"(?:\"\"|[^"])+"|[A-Za-z_][A-Za-z0-9_$]*';
    if (!preg_match('/^(' . $identifier . ')(?:\s*\.\s*(' . $identifier . '))?(?:\s*\.\s*(' . $identifier . '))?(?:\s+|$)/', $tableSql, $match)) {
      return false;
    }
    $parts = array_values(array_filter(array_slice($match, 1, 3), fn($part) => $part !== ''));
    $parts = array_map([self::class, 'unquoteSqlIdentifier'], $parts);
    if (count($parts) >= 2) {
      return [
        'schema' => $parts[count($parts) - 2],
        'table' => $parts[count($parts) - 1]
      ];
    }
    if ($defaultSchema === '') {
      return false;
    }
    return [
      'schema' => $defaultSchema,
      'table' => $parts[0]
    ];
  }

  /** Finds a top-level SQL keyword while ignoring strings, identifiers, comments, and nested expressions. */
  private static function topLevelKeywordOffset(string $sql, string $keyword) {
    $upperKeyword = strtoupper($keyword);
    foreach (self::topLevelSqlWords($sql) as $word) {
      if ($word['upper'] === $upperKeyword) {
        return $word['offset'];
      }
    }
    return false;
  }

  /** Finds the first top-level clause keyword in SQL text. */
  private static function firstTopLevelClauseOffset(string $sql, array $keywords) {
    $wanted = array_flip(array_map('strtoupper', $keywords));
    foreach (self::topLevelSqlWords($sql) as $word) {
      if (isset($wanted[$word['upper']])) {
        return $word['offset'];
      }
    }
    return false;
  }

  /** Returns whether a separator appears at top-level in SQL text. */
  private static function hasTopLevelSeparator(string $sql, string $separator): bool {
    $depth = 0;
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
      $char = $sql[$i];
      if ($char === "'" || $char === '"' || $char === '`') {
        $i = self::skipQuotedSql($sql, $i, $char);
      } else if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
        $i = self::skipLineComment($sql, $i + 2);
      } else if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
        $i = self::skipBlockComment($sql, $i + 2);
      } else if ($char === '(') {
        $depth++;
      } else if ($char === ')') {
        $depth = max(0, $depth - 1);
      } else if ($depth === 0 && $char === $separator) {
        return true;
      }
    }
    return false;
  }

  /** Yields top-level SQL words with two-word clause names merged. */
  private static function topLevelSqlWords(string $sql): array {
    $words = [];
    $depth = 0;
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
      $char = $sql[$i];
      if ($char === "'" || $char === '"' || $char === '`') {
        $i = self::skipQuotedSql($sql, $i, $char);
      } else if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
        $i = self::skipLineComment($sql, $i + 2);
      } else if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
        $i = self::skipBlockComment($sql, $i + 2);
      } else if ($char === '(') {
        $depth++;
      } else if ($char === ')') {
        $depth = max(0, $depth - 1);
      } else if ($depth === 0 && preg_match('/[A-Za-z_]/', $char)) {
        $offset = $i;
        while ($i + 1 < $length && preg_match('/[A-Za-z0-9_$]/', $sql[$i + 1])) {
          $i++;
        }
        $words[] = [
          'upper' => strtoupper(substr($sql, $offset, $i - $offset + 1)),
          'offset' => $offset
        ];
      }
    }
    for ($i = 0; $i < count($words) - 1; $i++) {
      $pair = $words[$i]['upper'] . ' ' . $words[$i + 1]['upper'];
      if ($pair === 'GROUP BY' || $pair === 'ORDER BY') {
        $words[$i]['upper'] = $pair;
        array_splice($words, $i + 1, 1);
      }
    }
    return $words;
  }

  /** Skips a quoted SQL string or identifier. */
  private static function skipQuotedSql(string $sql, int $offset, string $quote): int {
    $length = strlen($sql);
    for ($i = $offset + 1; $i < $length; $i++) {
      if ($sql[$i] === '\\' && $quote !== '`') {
        $i++;
      } else if ($sql[$i] === $quote) {
        if (($sql[$i + 1] ?? '') === $quote) {
          $i++;
          continue;
        }
        return $i;
      }
    }
    return $length - 1;
  }

  /** Skips a line SQL comment. */
  private static function skipLineComment(string $sql, int $offset): int {
    $end = strpos($sql, "\n", $offset);
    return $end === false ? strlen($sql) - 1 : $end;
  }

  /** Skips a block SQL comment. */
  private static function skipBlockComment(string $sql, int $offset): int {
    $end = strpos($sql, '*/', $offset);
    return $end === false ? strlen($sql) - 1 : $end + 1;
  }

  /** Removes SQL identifier quotes. */
  private static function unquoteSqlIdentifier(string $identifier): string {
    $identifier = trim($identifier);
    if (strlen($identifier) >= 2 && $identifier[0] === '`' && substr($identifier, -1) === '`') {
      return str_replace('``', '`', substr($identifier, 1, -1));
    }
    if (strlen($identifier) >= 2 && $identifier[0] === '"' && substr($identifier, -1) === '"') {
      return str_replace('""', '"', substr($identifier, 1, -1));
    }
    return $identifier;
  }

  /** Returns metadata for the active table result when it belongs to the requested table. */
  public static function activeTableResultContext(string $schema, string $table) {
    $context = self::activeResultTableContext();
    if ($context === false || $context['schema'] !== $schema || $context['table'] !== $table) {
      return false;
    }
    return $context;
  }

  /** Returns active table result metadata with cursor row values. */
  public static function activeResultRowContext() {
    $context = self::activeResultTableContext();
    if (
      $context === false ||
      self::$resultTable === null ||
      self::$resultTable === false ||
      !method_exists(self::$resultTable, 'getActiveRowValues') ||
      !method_exists(self::$resultTable, 'getCursor')
    ) {
      return false;
    }
    $row = self::$resultTable->getActiveRowValues();
    if ($row === false) {
      return false;
    }
    [$rowIndex, $columnIndex] = self::$resultTable->getCursor();
    $context['row'] = $row;
    $context['rowIndex'] = $rowIndex;
    $context['columnIndex'] = $columnIndex;
    $context['field'] = (string)($context['columns'][$columnIndex] ?? '');
    return $context;
  }

  /** Returns active table result metadata with selected row values. */
  public static function activeResultRowsContext() {
    $context = self::activeResultTableContext();
    if (
      $context === false ||
      self::$resultTable === null ||
      self::$resultTable === false ||
      !method_exists(self::$resultTable, 'getRowRangeValues') ||
      !method_exists(self::$resultTable, 'getSelection')
    ) {
      return false;
    }
    [$row1, , $row2, ] = self::$resultTable->getSelection();
    $rowStart = min((int)$row1, (int)$row2);
    $rowEnd = max((int)$row1, (int)$row2);
    $rows = self::$resultTable->getRowRangeValues($rowStart, $rowEnd);
    if (empty($rows)) {
      return false;
    }
    $context['rows'] = $rows;
    $context['rowStart'] = $rowStart;
    $context['rowEnd'] = $rowEnd;
    return $context;
  }

  /** Toggles result table row numbers from menus and main-screen shortcuts. */
  public static function toggleResultRowNumbers($item = null): bool {
    self::$resultRowNumbers = !self::$resultRowNumbers;
    self::applyResultRowNumbers();
    Element::refresh();
    return true;
  }

  /** Applies result row-number state to the table and menu marker. */
  private static function applyResultRowNumbers(): void {
    if (self::$resultTable !== null && self::$resultTable !== false && method_exists(self::$resultTable, 'setRowNumbers')) {
      self::$resultTable->setRowNumbers(self::$resultRowNumbers);
    }
    $menuItem = Element::byName('menu-query-row-numbers');
    if ($menuItem !== false && method_exists($menuItem, 'setLeft')) {
      $menuItem->setLeft(self::$resultRowNumbers ? 'X' : '');
    }
  }

  /** Toggles whether the query editor is shown above result sets. */
  public static function toggleResultQueryEditor($item = null): bool {
    if (self::$queryResultOnlyLayout) {
      self::exitResultOnlyLayout();
      Element::refresh();
      return true;
    }
    if (self::$connectionName === false) {
      return false;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false || !(self::hasResult($query) || (($query['status'] ?? 'new') === 'running' && !empty($query['statements'])))) {
      return false;
    }
    self::$queryResultOnlyLayout = true;
    self::$resultQueryEditor = false;
    self::deactivateList();
    if (self::$activeBox !== self::RESULT) {
      self::withSuppressedFocusChange(function(): void {
        self::deactivateEditor();
        self::activateResult();
      });
    }
    self::applyResultQueryEditor();
    self::syncResultFastPreview();
    Element::refresh();
    return true;
  }

  /** Applies query-editor visibility state to menus and the current result layout. */
  private static function applyResultQueryEditor(): void {
    self::applyResultQueryEditorMenu();
    if (self::$connectionName === false) {
      return;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query !== false) {
      self::applyResultWorkspaceLayout($query);
    }
  }

  /** Applies temporary query-editor visibility state to the Result menu marker. */
  private static function applyResultQueryEditorMenu(): void {
    $menuItem = Element::byName('menu-query-editor');
    if ($menuItem !== false && method_exists($menuItem, 'setLeft')) {
      $menuItem->setLeft(self::$queryResultOnlyLayout ? 'X' : '');
    }
  }

  /** Restores normal query/result layout after temporary result-only mode. */
  private static function exitResultOnlyLayout(): bool {
    if (!self::$queryResultOnlyLayout) {
      return false;
    }
    self::$queryResultOnlyLayout = false;
    self::$resultQueryEditor = true;
    self::applyResultQueryEditor();
    self::syncResultFastPreview();
    return true;
  }

  /** Applies result info visibility state to the result menu marker. */
  private static function applyResultInfoMenu(): void {
    $menuItem = Element::byName('menu-query-info');
    if ($menuItem !== false && method_exists($menuItem, 'setLeft')) {
      $menuItem->setLeft(self::$resultInfoVisible ? 'X' : '');
    }
  }

  /** Toggles automatic result field preview from menus and main-screen shortcuts. */
  public static function toggleResultFastPreview($item = null): bool {
    self::$resultFastPreview = !self::$resultFastPreview;
    self::applyResultFastPreview();
    self::restoreResultFocusAfterFastPreview();
    Element::refresh();
    return true;
  }

  /** Restores the default temporary result view for a newly active query. */
  private static function resetTemporaryResultViewState(): void {
    self::$queryReviewLayout = false;
    self::$queryResultOnlyLayout = false;
    self::$resultQueryEditor = true;
    self::$resultInfoVisible = false;
    self::$resultFastPreview = false;
    self::$resultRowNumbers = false;
    self::applyQueryViewMenu();
    self::applyResultQueryEditorMenu();
    self::applyResultInfoMenu();
    self::applyResultFastPreviewMenu();
    self::applyResultRowNumbers();
    self::hideResultFastPreview();
  }

  /** Applies result fast-preview state to the preview box and menu marker. */
  private static function applyResultFastPreview(): void {
    self::applyResultFastPreviewMenu();
    if (self::$connectionName !== false) {
      $query = self::$queryList->getActive(self::$connectionName);
      if ($query !== false) {
        self::applyResultWorkspaceLayout($query);
      }
    }
    self::syncResultFastPreview();
  }

  /** Applies temporary fast-preview state to the Result menu marker. */
  private static function applyResultFastPreviewMenu(): void {
    $menuItem = Element::byName('menu-query-fast-preview');
    if ($menuItem !== false && method_exists($menuItem, 'setLeft')) {
      $menuItem->setLeft(self::$resultFastPreview ? 'X' : '');
    }
  }

  /** Refreshes the automatic result preview for the active cell or selection. */
  public static function syncResultFastPreview($table = null): bool {
    if (
      !self::$resultFastPreview ||
      self::$activeBox !== self::RESULT ||
      self::$resultPreview === false ||
      self::$resultPreviewText === false ||
      self::$resultTable === false ||
      !self::$resultTable->isDisplayed() ||
      !method_exists(self::$resultTable, 'getActiveCellValue')
    ) {
      self::hideResultFastPreview();
      return false;
    }
    $text = self::formatResultFastPreview();
    if ($text === false) {
      self::hideResultFastPreview();
      return false;
    }
    $key = sha1($text);
    if (self::$resultPreviewKey !== $key) {
      self::$resultPreviewText->setValue($text);
      self::$resultPreviewKey = $key;
    }
    self::$resultPreview->show();
    self::$resultPreview->raise();
    Element::immediateRender(self::$resultPreview);
    self::restoreResultFocusAfterFastPreview();
    return true;
  }

  /** Keeps the result table as the active input surface after preview overlay updates. */
  private static function restoreResultFocusAfterFastPreview(): void {
    if (self::$activeBox === self::RESULT && self::$result !== false) {
      self::$result->raise();
      self::setResultTableHeaderActive(true);
    }
  }

  /** Hides the automatic result preview box. */
  private static function hideResultFastPreview(): void {
    self::$resultPreviewKey = false;
    if (self::$resultPreview !== false) {
      self::$resultPreview->hide();
    }
  }

  /** Formats the automatic result preview text. */
  private static function formatResultFastPreview() {
    if (!method_exists(self::$resultTable, 'getSelection') || !method_exists(self::$resultTable, 'getHeader')) {
      return false;
    }
    [$row1, $col1, $row2, $col2] = self::$resultTable->getSelection();
    $rows = max(0, (int)$row2 - (int)$row1 + 1);
    $cols = max(0, (int)$col2 - (int)$col1 + 1);
    $headers = self::$resultTable->getHeader();
    if ($rows > 1 || $cols > 1) {
      $fields = array_slice($headers, (int)$col1, $cols);
      $lines = [
        'Selection: ' . $rows . ' x ' . $cols,
        'Rows: ' . ((int)$row1 + 1) . '-' . ((int)$row2 + 1),
        'Fields: ' . implode(', ', array_map('strval', $fields))
      ];
      return implode("\n", $lines);
    }
    $value = self::activeResultCellDisplayValue();
    if ($value === false) {
      return false;
    }
    $field = (string)($headers[(int)$col1] ?? '');
    $prefix = $field === '' ? 'Row ' . ((int)$row1 + 1) : $field . ' / row ' . ((int)$row1 + 1);
    $separator = str_repeat('-', mb_strlen($prefix));
    return $prefix . "\n" . $separator . "\n" . ($value === null ? 'NULL' : (string)$value);
  }

  /** Shows the full value under the result table cursor. */
  private static function showActiveFieldValue(): bool {
    if (
      self::$activeBox !== self::RESULT ||
      self::$resultTable === false ||
      !self::$resultTable->isDisplayed() ||
      self::$fieldValuePanel === false ||
      !method_exists(self::$resultTable, 'getActiveCellValue')
    ) {
      return false;
    }
    $value = self::activeResultCellDisplayValue();
    if ($value === false) {
      return false;
    }
    self::$fieldValuePanel->setValue([
      'query-field-value-text' => $value === null ? 'NULL' : (string)$value
    ]);
    self::$fieldValuePanel->show();
    Element::refresh();
    return true;
  }

  /** Opens the full-document editor for the active MongoDB result row. */
  public static function editActiveMongoDocument(): bool {
    $context = self::activeMongoDocumentContext();
    if ($context === false) {
      return false;
    }
    if (self::$mongoDocumentEditorPanel === false) {
      \SPTK\Elements\WarningPanel::forge('Document editor unavailable', 'The MongoDB document editor panel is not available.');
      return true;
    }
    $document = self::mongoDocumentByContext($context);
    if ($document === false) {
      \SPTK\Elements\ErrorPanel::forge('Could not load document', 'The selected MongoDB document could not be loaded by _id.');
      return true;
    }
    $context['mode'] = 'update';
    self::openMongoDocumentEditor($context, $document, 'Edit MongoDB document');
    return true;
  }

  /** Opens the MongoDB document insert editor for the active result collection. */
  public static function insertActiveMongoDocument(): bool {
    $context = self::activeMongoInsertContext();
    if ($context === false) {
      return false;
    }
    self::openMongoDocumentInsert($context['connection'], $context['schema'], $context['table'], $context['queryId'] ?? false);
    return true;
  }

  /** Opens the MongoDB document insert editor for an explicit collection context. */
  public static function openMongoDocumentInsert(array $connection, string $schema, string $table, $queryId = false): bool {
    if (($connection['engine'] ?? '') !== 'MongoDB') {
      return false;
    }
    if ($schema === '' || $table === '') {
      return false;
    }
    self::openMongoDocumentEditor([
      'mode' => 'insert',
      'connection' => $connection,
      'schema' => $schema,
      'table' => $table,
      'queryId' => $queryId
    ], '{}', 'Insert MongoDB document');
    return true;
  }

  /** Opens a generated MongoDB write query preview from the document editor. */
  public static function previewMongoDocumentUpdate($panel): void {
    if (!is_array(self::$mongoDocumentEditState)) {
      \SPTK\Elements\WarningPanel::forge('Document editor is not ready', 'Please open the MongoDB document editor again.');
      return;
    }
    if ($panel === null || !method_exists($panel, 'getValue')) {
      return;
    }
    $values = $panel->getValue();
    $json = self::resultTextValue($values['mongodb-document-editor-text'] ?? '');
    $state = self::$mongoDocumentEditState;
    $insert = ($state['mode'] ?? 'update') === 'insert';
    $query = $insert
      ? self::mongoDocumentInsertQuery($state, $json)
      : self::mongoDocumentUpdateQuery($state, $json);
    if ($query === false) {
      return;
    }
    if (self::$mongoDocumentEditorPanel !== false) {
      self::$mongoDocumentEditorPanel->hide();
    }
    self::$mongoDocumentEditState = false;
    \MADB\Query\GeneratedQueryController::open([
      'title' => ($insert ? 'Insert MongoDB document' : 'Update MongoDB document'),
      'name' => ($insert ? 'INSERT ' : 'UPDATE ') . $state['schema'] . '.' . $state['table'],
      'sql' => $query,
      'connection' => $state['connection'],
      'schema' => $state['schema'],
      'table' => $state['table'],
      'expectsResult' => false,
      'allowNoRefreshRun' => true,
      'refreshQueryId' => $state['queryId'] ?? false
    ]);
  }

  /** Opens the shared MongoDB document editor panel. */
  private static function openMongoDocumentEditor(array $state, string $document, string $title): void {
    if (self::$mongoDocumentEditorPanel === false) {
      \SPTK\Elements\WarningPanel::forge('Document editor unavailable', 'The MongoDB document editor panel is not available.');
      return;
    }
    $titleElement = \SPTK\Element::firstByType('PanelTitle', self::$mongoDocumentEditorPanel);
    if ($titleElement !== false) {
      $titleElement->setText($title);
    }
    self::$mongoDocumentEditState = $state;
    self::$mongoDocumentEditorPanel->setValue([
      'mongodb-document-editor-text' => $document
    ]);
    self::$mongoDocumentEditorPanel->show();
    if (method_exists(self::$mongoDocumentEditorPanel, 'activateInput')) {
      self::$mongoDocumentEditorPanel->activateInput('mongodb-document-editor-text');
    }
    Element::refresh();
  }

  /** Returns active MongoDB document identity and refresh context. */
  private static function activeMongoDocumentContext() {
    if (
      self::$activeBox !== self::RESULT ||
      self::$connectionName === false ||
      self::$resultTable === false ||
      !self::$resultTable->isDisplayed()
    ) {
      return false;
    }
    $connection = \MADB\Connection\ConnectionList::getInstance()->get(self::$connectionName);
    if (($connection['engine'] ?? '') !== 'MongoDB') {
      return false;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false || !method_exists(self::$resultTable, 'getHeader') || !method_exists(self::$resultTable, 'getActiveRowValues')) {
      return false;
    }
    $tableContext = self::resultTableContextFromQuery($query);
    if ($tableContext === false) {
      return false;
    }
    $headers = self::$resultTable->getHeader();
    $idIndex = array_search('_id', $headers, true);
    if ($idIndex === false) {
      return false;
    }
    $row = self::$resultTable->getActiveRowValues();
    if (!is_array($row) || !array_key_exists($idIndex, $row)) {
      return false;
    }
    return [
      'connection' => $connection,
      'schema' => $tableContext['schema'],
      'table' => $tableContext['table'],
      'id' => (string)$row[$idIndex],
      'queryId' => $query['id'] ?? false
    ];
  }

  /** Returns active MongoDB collection context for document insert. */
  private static function activeMongoInsertContext() {
    if (self::$connectionName === false) {
      return false;
    }
    $connection = \MADB\Connection\ConnectionList::getInstance()->get(self::$connectionName);
    if (($connection['engine'] ?? '') !== 'MongoDB') {
      return false;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false) {
      return false;
    }
    $tableContext = self::resultTableContextFromQuery($query);
    if ($tableContext === false) {
      return false;
    }
    return [
      'connection' => $connection,
      'schema' => $tableContext['schema'],
      'table' => $tableContext['table'],
      'queryId' => $query['id'] ?? false
    ];
  }

  /** Loads a full MongoDB document for an active-row context. */
  private static function mongoDocumentByContext(array $context) {
    $className = \MADB\Engine\EngineRegistry::connectionClass('MongoDB');
    try {
      $mongo = new $className($context['connection']);
      return $mongo->findDocumentById($context['schema'], $context['table'], $context['id']);
    } catch (\Exception $e) {
      return false;
    }
  }

  /** Normalizes text editor values from SPTK controls. */
  private static function resultTextValue($value): string {
    if (is_array($value)) {
      return implode("\n", $value);
    }
    return (string)$value;
  }

  /** Builds a MongoDB update command preview for the edited document. */
  private static function mongoDocumentUpdateQuery(array $context, string $json) {
    $className = \MADB\Engine\EngineRegistry::connectionClass('MongoDB');
    try {
      $mongo = new $className($context['connection']);
      $filter = $mongo->documentIdFilterJson($context['schema'], $context['table'], $context['id']);
      $document = $mongo->replacementDocumentJson($json, true);
    } catch (\Exception $e) {
      \SPTK\Elements\ErrorPanel::forge('Could not build update query', $e->getMessage());
      return false;
    }
    $command = [
      'update' => $context['table'],
      'updates' => [[
        'q' => json_decode($filter, true),
        'u' => json_decode($document, true),
        'multi' => false,
        'upsert' => false
      ]]
    ];
    $json = json_encode($command, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? '' : $json;
  }

  /** Builds a MongoDB insert command preview for the edited document. */
  private static function mongoDocumentInsertQuery(array $context, string $json) {
    $className = \MADB\Engine\EngineRegistry::connectionClass('MongoDB');
    try {
      $mongo = new $className($context['connection']);
      $document = $mongo->insertDocumentJson($json, true);
    } catch (\Exception $e) {
      \SPTK\Elements\ErrorPanel::forge('Could not build insert query', $e->getMessage());
      return false;
    }
    $command = [
      'insert' => $context['table'],
      'documents' => [
        json_decode($document)
      ]
    ];
    $json = json_encode($command, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? '' : $json;
  }

  /** Returns the active result cell value, resolving special metadata-backed fields. */
  private static function activeResultCellDisplayValue() {
    $value = self::$resultTable->getActiveCellValue();
    if ($value === false) {
      return false;
    }
    if (!method_exists(self::$resultTable, 'getSelection') || !method_exists(self::$resultTable, 'getHeader')) {
      return $value;
    }
    [, $col1, , ] = self::$resultTable->getSelection();
    $headers = self::$resultTable->getHeader();
    if (($headers[(int)$col1] ?? '') !== '_document') {
      return $value;
    }
    $context = self::activeMongoDocumentContext();
    $document = $context === false ? false : self::mongoDocumentByContext($context);
    return $document === false ? $value : $document;
  }

  /** Schedules a large result file to load after cursor movement settles. */
  private static function scheduleResultFileLoad($query, $entry, $result, string $file, int $size): void {
    self::$pendingResultGeneration++;
    $generation = self::$pendingResultGeneration;
    self::$pendingResultLoad = [
      'generation' => $generation,
      'dueAt' => self::nowMs() + self::DEFERRED_RESULT_IDLE_MS,
      'connectionName' => self::$connectionName,
      'queryId' => $query['id'] ?? false,
      'file' => $file,
      'size' => $size
    ];
    self::$resultStatus->setText(self::formatDeferredResultStatus($result, $size));
    self::$resultStatus->show();
    if (self::shouldHighlightResultSource($query, $entry)) {
      self::highlightResultSource($entry);
    } else {
      self::clearResultHighlight();
    }
  }

  /** Clears pending large result loads. */
  private static function clearPendingResultLoad(): void {
    self::$pendingResultGeneration++;
    self::$pendingResultLoad = false;
  }

  /** Handles timer ticks for deferred result loading. */
  public static function timer($now = null): void {
    \MADB\Connection\MenuController::showPendingPasswordPrompt();
    self::applyPendingQueryEditorTokenizer($now);
    self::processPendingResultFilter();
    self::processPendingResultExport();
    self::loadPendingResultFile($now);
  }

  /** Loads a pending large result when it is still current and past its idle delay. */
  private static function loadPendingResultFile($now = null): void {
    if (self::$pendingResultLoad === false) {
      return;
    }
    $now ??= self::nowMs();
    if ($now < self::$pendingResultLoad['dueAt']) {
      return;
    }
    $pending = self::$pendingResultLoad;
    self::$pendingResultLoad = false;
    if ($pending['generation'] !== self::$pendingResultGeneration) {
      return;
    }
    if (self::$connectionName !== $pending['connectionName']) {
      return;
    }
    if (self::$queryList->getActiveId(self::$connectionName) !== $pending['queryId']) {
      return;
    }
    if (!file_exists($pending['file'])) {
      return;
    }
    self::loadResultFile($pending['file']);
    self::$resultStatus->hide();
    Element::refresh();
  }

  /** Returns current time in milliseconds. */
  private static function nowMs(): int {
    return (int) round(microtime(true) * 1000);
  }

  /** Formats deferred result status text for large result files. */
  private static function formatDeferredResultStatus($result, int $size): string {
    $rows = (int) ($result['rowCount'] ?? 0);
    return $rows . ' row(s), ' . self::formatBytes($size) . "\nLoading result after cursor movement stops...";
  }

  /** Formats byte counts for result status messages. */
  private static function formatBytes(int $bytes): string {
    return \MADB\App\Format::bytes($bytes);
  }

  /** Coordinates statement by index work in the query workspace. */
  private static function statementByIndex($statements, int $index) {
    foreach (is_array($statements) ? $statements : [] as $statement) {
      if ((int) ($statement['index'] ?? -1) === $index) {
        return $statement;
      }
    }
    return false;
  }

  /** Coordinates result for statement work in the query workspace. */
  private static function resultForStatement($results, int $statementIndex) {
    foreach (is_array($results) ? $results : [] as $result) {
      if ((int) ($result['statementIndex'] ?? -1) === $statementIndex) {
        return $result;
      }
    }
    return false;
  }

  /** Coordinates result offset for statement work in the query workspace. */
  private static function resultOffsetForStatement($results, int $statementIndex) {
    foreach (is_array($results) ? $results : [] as $offset => $result) {
      if ((int) ($result['statementIndex'] ?? -1) === $statementIndex) {
        return (int) $offset;
      }
    }
    return false;
  }

  /** Formats statement status text for the query workspace. */
  private static function formatStatementStatus($statement): string {
    return self::formatStatementStatusBlock($statement, false);
  }

  /** Formats duration text for the query workspace. */
  private static function formatDuration($seconds): string {
    return round(max(0, (float) $seconds), 4) . 's';
  }

  /** Checks should highlight result source for query workspace decisions. */
  private static function shouldHighlightResultSource($query, $entry): bool {
    if (!is_array($entry) || empty($entry['range']) || !is_array($entry['range'])) {
      return false;
    }
    return self::shouldHighlightStatementSource($query);
  }

  /** Checks should highlight statement source for query workspace decisions. */
  private static function shouldHighlightStatementSource($query): bool {
    if (count($query['statements'] ?? []) > 1) {
      return true;
    }
    if (strlen(self::editorText()) > self::HIGHLIGHT_SPLIT_MAX_BYTES) {
      return false;
    }
    return count(self::language()->split(self::editorText())) > 1;
  }

  /** Coordinates highlight result source work in the query workspace. */
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
    $activeId = self::$connectionName === false ? false : self::$queryList->getActiveId(self::$connectionName);
    $highlightKey = self::$connectionName . ':' . $activeId . ':' . (int) $range['start'] . ':' . (int) $range['end'];
    if (self::$resultHighlightKey === $highlightKey) {
      self::scrollResultSourceToTop($start[0]);
      return;
    }
    self::$editor->setHighlightRanges([[$start[0], $start[1], $end[0], $end[1]]]);
    if (method_exists(self::$editor, 'setCursorPosition')) {
      self::$editor->setCursorPosition($start[0], $start[1]);
    }
    self::scrollResultSourceToTop($start[0]);
    self::$resultHighlightKey = $highlightKey;
  }

  /** Scrolls the highlighted source statement to the top of the query editor. */
  private static function scrollResultSourceToTop(int $row): void {
    if (method_exists(self::$editor, 'scrollToRow')) {
      self::$editor->scrollToRow($row);
    }
  }

  /** Clears result highlight state from the query workspace. */
  private static function clearResultHighlight(): void {
    self::$resultHighlightKey = false;
    if (self::$searchSession === false && self::$editor !== null && method_exists(self::$editor, 'clearHighlightRanges')) {
      self::$editor->clearHighlightRanges();
    }
  }

  /** Synchronizes result table header state inside the query workspace. */
  private static function syncResultTableHeader() {
    self::setResultTableHeaderActive(self::$activeBox === self::RESULT);
  }

  /** Applies result table header active values to query workspace state or controls. */
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

}
