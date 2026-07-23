<?php

namespace MADB\Result;

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
  private static function showResult($query) {
    self::applyResultInfoMenu();
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
      if (self::$resultInfoVisible) {
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
          self::showResultFile($query, $entry, $result, $file);
          if (self::shouldHighlightResultSource($query, $entry)) {
            self::highlightResultSource($entry);
          }
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
    $inferred = self::singleTableContextFromSql(self::activeResultStatementSql($query), self::currentSchema($query));
    if ($inferred !== false) {
      return $inferred;
    }
    if (($query['schema'] ?? '') !== '' && ($query['table'] ?? '') !== '') {
      return [
        'schema' => $query['schema'],
        'table' => $query['table']
      ];
    }
    return false;
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
    return trim((string)($query['sql'] ?? ''));
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
    self::saveResultRowNumbersSetting();
    self::applyResultRowNumbers();
    Element::refresh();
    return true;
  }

  /** Loads the global result row-number preference. */
  private static function loadResultRowNumbersSetting(): void {
    $settings = self::loadSettings();
    self::$resultRowNumbers = self::boolSetting($settings['resultRowNumbers'] ?? true);
  }

  /** Saves the global result row-number preference. */
  private static function saveResultRowNumbersSetting(): void {
    $settings = self::loadSettings();
    $settings['resultRowNumbers'] = self::$resultRowNumbers;
    \SPTK\Config::save(self::settingsFile(), $settings);
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
    self::$resultQueryEditor = !self::$resultQueryEditor;
    self::saveResultQueryEditorSetting();
    self::applyResultQueryEditor();
    self::syncResultFastPreview();
    Element::refresh();
    return true;
  }

  /** Loads the global result query-editor visibility preference. */
  private static function loadResultQueryEditorSetting(): void {
    $settings = self::loadSettings();
    self::$resultQueryEditor = self::boolSetting($settings['resultQueryEditor'] ?? true);
  }

  /** Saves the global result query-editor visibility preference. */
  private static function saveResultQueryEditorSetting(): void {
    $settings = self::loadSettings();
    $settings['resultQueryEditor'] = self::$resultQueryEditor;
    \SPTK\Config::save(self::settingsFile(), $settings);
  }

  /** Applies query-editor visibility state to menus and the current result layout. */
  private static function applyResultQueryEditor(): void {
    $menuItem = Element::byName('menu-query-editor');
    if ($menuItem !== false && method_exists($menuItem, 'setLeft')) {
      $menuItem->setLeft(self::$resultQueryEditor ? 'X' : '');
    }
    if (self::$connectionName === false) {
      return;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query !== false) {
      self::applyResultWorkspaceLayout($query);
    }
  }

  /** Loads the global result info visibility preference. */
  private static function loadResultInfoSetting(): void {
    $settings = self::loadSettings();
    self::$resultInfoVisible = self::boolSetting($settings['resultInfoVisible'] ?? false);
  }

  /** Saves the global result info visibility preference. */
  private static function saveResultInfoSetting(): void {
    $settings = self::loadSettings();
    $settings['resultInfoVisible'] = self::$resultInfoVisible;
    \SPTK\Config::save(self::settingsFile(), $settings);
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
    self::saveResultFastPreviewSetting();
    self::applyResultFastPreview();
    self::restoreResultFocusAfterFastPreview();
    Element::refresh();
    return true;
  }

  /** Loads the global result fast-preview preference. */
  private static function loadResultFastPreviewSetting(): void {
    $settings = self::loadSettings();
    self::$resultFastPreview = self::boolSetting($settings['resultFastPreview'] ?? false);
  }

  /** Saves the global result fast-preview preference. */
  private static function saveResultFastPreviewSetting(): void {
    $settings = self::loadSettings();
    $settings['resultFastPreview'] = self::$resultFastPreview;
    \SPTK\Config::save(self::settingsFile(), $settings);
  }

  /** Applies result fast-preview state to the preview box and menu marker. */
  private static function applyResultFastPreview(): void {
    $menuItem = Element::byName('menu-query-fast-preview');
    if ($menuItem !== false && method_exists($menuItem, 'setLeft')) {
      $menuItem->setLeft(self::$resultFastPreview ? 'X' : '');
    }
    if (self::$connectionName !== false) {
      $query = self::$queryList->getActive(self::$connectionName);
      if ($query !== false) {
        self::applyResultWorkspaceLayout($query);
      }
    }
    self::syncResultFastPreview();
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
    $value = self::$resultTable->getActiveCellValue();
    if ($value === false) {
      return false;
    }
    $field = (string)($headers[(int)$col1] ?? '');
    $prefix = $field === '' ? 'Row ' . ((int)$row1 + 1) : $field . ' / row ' . ((int)$row1 + 1);
    $separator = str_repeat('-', mb_strlen($prefix));
    return $prefix . "\n" . $separator . "\n" . ($value === null ? 'NULL' : (string)$value);
  }

  /** Loads MADB settings from the user config directory. */
  private static function loadSettings(): array {
    return \MADB\App\Settings::load();
  }

  /** Returns the MADB settings file path. */
  private static function settingsFile(): string {
    return \MADB\App\Settings::file();
  }

  /** Normalizes loose setting values into booleans. */
  private static function boolSetting($value): bool {
    return $value === true || $value === 1 || $value === '1' || $value === 'true';
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
    $value = self::$resultTable->getActiveCellValue();
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
    if ($bytes >= 1073741824) {
      return round($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
      return round($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
      return round($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
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
    return count(SqlSplitter::split(self::editorText())) > 1;
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
      return;
    }
    self::$editor->setHighlightRanges([[$start[0], $start[1], $end[0], $end[1]]]);
    if (method_exists(self::$editor, 'setCursorPosition')) {
      self::$editor->setCursorPosition($start[0], $start[1]);
    }
    self::$resultHighlightKey = $highlightKey;
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
