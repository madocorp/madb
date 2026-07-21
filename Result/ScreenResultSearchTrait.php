<?php

namespace MADB\Result;

use \SPTK\Element;

/** Coordinates the result table search panel and SPTK table search state. */
trait ScreenResultSearchTrait {

  /** Opens the dedicated result table search panel. */
  public static function searchResult() {
    if (!self::resultTableSearchAvailable()) {
      \SPTK\Elements\WarningPanel::forge('No result table', 'Please execute a query with a table result before searching results.');
      return;
    }
    self::syncResultSearchFields();
    self::$resultSearchPanel->setValue(self::$resultSearchPanelState);
    self::$resultSearchPanel->show();
    if (method_exists(self::$resultSearchPanel, 'activateInput')) {
      self::$resultSearchPanel->activateInput('result-search-text');
    }
    Element::refresh();
  }

  /** Applies result search panel values to the result table. */
  public static function doResultSearch($panel) {
    $values = $panel->getValue();
    self::$resultSearchPanelState = self::normalizeResultSearchPanelState($values);
    $search = (string)($values['result-search-text'] ?? '');
    $regexp = self::boolValue($values['result-search-regexp'] ?? false);
    $caseSensitive = self::boolValue($values['result-search-case-sensitive'] ?? false);
    $fields = self::resultSearchFields($values);
    $scope = self::selectedResultSearchScope($values);
    if ($search === '') {
      \SPTK\Elements\WarningPanel::forge('Missing search text', 'Please enter text to search for.');
      return;
    }
    if (!self::resultTableSearchAvailable()) {
      \SPTK\Elements\WarningPanel::forge('No result table', 'Please execute a query with a table result before searching results.');
      return;
    }
    if ($regexp) {
      $pattern = self::searchPattern($search, true, $caseSensitive);
      if ($pattern === false || !self::validPattern($pattern)) {
        \SPTK\Elements\WarningPanel::forge('Invalid regexp', 'Please enter a valid regular expression.');
        return;
      }
    }

    $options = [
      'regexp' => $regexp,
      'caseSensitive' => $caseSensitive,
      'columns' => $fields
    ];
    $match = self::runResultSearch($search, $options, $scope);
    if ($match === 'pending-filter-task') {
      $panel->hide();
      Element::refresh();
      return;
    }
    if ($match === false) {
      self::$resultSearchSession = false;
      \SPTK\Elements\WarningPanel::forge('Not found', 'No match was found in the current result.');
      return;
    }
    self::$resultSearchSession = [
      'search' => $search,
      'regexp' => $regexp,
      'caseSensitive' => $caseSensitive,
      'fields' => $fields,
      'scope' => $scope
    ];
    $panel->hide();
    self::deactivateEditor();
    self::deactivateList();
    self::activateResult();
    Element::refresh();
  }

  /** Closes the result search panel and clears result search navigation state. */
  public static function closeResultSearchPanel($panel = null) {
    if ($panel !== null) {
      self::$resultSearchPanelState = self::normalizeResultSearchPanelState($panel->getValue());
      $panel->hide();
    } else if (self::$resultSearchPanel !== null) {
      $panel = self::$resultSearchPanel;
      if ($panel->isDisplayed()) {
        self::$resultSearchPanelState = self::normalizeResultSearchPanelState($panel->getValue());
      }
      $panel->hide();
    }
    self::clearResultSearchSession(true);
    Element::refresh();
  }

  /** Navigates the current result table search from keyboard shortcuts. */
  private static function navigateResultSearchSession($delta): bool {
    if (self::$resultSearchSession === false || !self::resultTableSearchAvailable()) {
      return false;
    }
    $match = $delta < 0 ? self::$resultTable->previousMatch() : self::$resultTable->nextMatch();
    if ($match === false) {
      self::clearResultSearchSession();
      Element::refresh();
      return true;
    }
    self::deactivateEditor();
    self::deactivateList();
    self::activateResult();
    Element::refresh();
    return true;
  }

  /** Clears result table search state. */
  private static function clearResultSearchSession(bool $restoreFilter = false): void {
    self::$resultSearchSession = false;
    if (self::$resultTable !== null && method_exists(self::$resultTable, 'clearSearch')) {
      self::$resultTable->clearSearch();
    }
    if ($restoreFilter) {
      self::restoreFilteredResult();
    }
  }

  /** Checks whether result table search can run. */
  private static function resultTableSearchAvailable(): bool {
    return self::$resultTable !== null &&
      self::$resultTable->isDisplayed() &&
      method_exists(self::$resultTable, 'search') &&
      method_exists(self::$resultTable, 'nextMatch') &&
      method_exists(self::$resultTable, 'previousMatch');
  }

  /** Runs the selected result search scope. */
  private static function runResultSearch(string $search, array $options, string $scope) {
    if ($scope === 'filter') {
      return self::filterResultSearch($search, $options);
    }
    if ($scope === 'previous') {
      if (!self::resultSearchStateMatches($search, $options)) {
        $match = self::$resultTable->search($search, $options);
        return $match === false ? false : self::$resultTable->previousMatch();
      }
      return self::$resultTable->previousMatch();
    }
    if ($scope === 'next') {
      if (!self::resultSearchStateMatches($search, $options)) {
        return self::$resultTable->search($search, $options);
      }
      return self::$resultTable->nextMatch();
    }
    return self::$resultTable->search($search, $options);
  }

  /** Returns whether current table search state matches panel options. */
  private static function resultSearchStateMatches(string $search, array $options): bool {
    if (!method_exists(self::$resultTable, 'getSearchState')) {
      return false;
    }
    $state = self::$resultTable->getSearchState();
    return !empty($state) &&
      ($state['text'] ?? '') === $search &&
      !empty($state['regexp']) === self::boolValue($options['regexp'] ?? false) &&
      !empty($state['caseSensitive']) === self::boolValue($options['caseSensitive'] ?? false) &&
      ($state['columns'] ?? []) === self::$resultTable->searchColumns($options['columns'] ?? null);
  }

  /** Selects result search scope from panel values. */
  private static function selectedResultSearchScope($values): string {
    if (self::boolValue($values['result-search-scope-filter'] ?? false)) {
      return 'filter';
    }
    if (self::boolValue($values['result-search-scope-all'] ?? false)) {
      return 'all';
    }
    if (self::boolValue($values['result-search-scope-previous'] ?? false)) {
      return 'previous';
    }
    if (self::boolValue($values['result-search-scope-next'] ?? false)) {
      return 'next';
    }
    return 'filter';
  }

  /** Normalizes result search panel state. */
  private static function normalizeResultSearchPanelState($values): array {
    $scope = self::selectedResultSearchScope($values);
    return [
      'result-search-text' => (string)($values['result-search-text'] ?? ''),
      'result-search-fields' => self::resultSearchFieldsValue($values),
      'result-search-header' => self::resultSearchHeaderKey(),
      'result-search-regexp' => self::boolValue($values['result-search-regexp'] ?? false),
      'result-search-case-sensitive' => self::boolValue($values['result-search-case-sensitive'] ?? false),
      'result-search-scope-filter' => $scope === 'filter',
      'result-search-scope-all' => $scope === 'all',
      'result-search-scope-next' => $scope === 'next',
      'result-search-scope-previous' => $scope === 'previous'
    ];
  }

  /** Applies current result table headers to the target fields select. */
  private static function syncResultSearchFields(): void {
    $headers = self::resultSearchHeaders();
    $headerKey = self::resultSearchHeaderKey($headers);
    $element = Element::byName('result-search-fields', self::$resultSearchPanel);
    if ($element === false) {
      return;
    }
    $element->setOptions($headers);
    $selected = self::$resultSearchPanelState['result-search-fields'] ?? false;
    $sameHeader = (self::$resultSearchPanelState['result-search-header'] ?? false) === $headerKey;
    $selectedFields = ($selected === false || !$sameHeader) ? $headers : self::fieldListFromValue($selected);
    $selectedFields = array_values(array_intersect($headers, $selectedFields));
    if (($selected !== false && $selected !== '' && empty($selectedFields)) || $selected === false) {
      $selectedFields = $headers;
    }
    self::$resultSearchPanelState['result-search-fields'] = implode(', ', $selectedFields);
    self::$resultSearchPanelState['result-search-header'] = $headerKey;
    $element->setValue(self::$resultSearchPanelState['result-search-fields']);
  }

  /** Returns result table headers usable as search target fields. */
  private static function resultSearchHeaders(): array {
    if (self::$resultTable === null || !method_exists(self::$resultTable, 'getHeader')) {
      return [];
    }
    return array_values(array_filter(
      array_map('strval', self::$resultTable->getHeader()),
      fn($header) => $header !== ''
    ));
  }

  /** Returns a stable key for the current result search fields. */
  private static function resultSearchHeaderKey($headers = null): string {
    if ($headers === null) {
      $headers = self::resultSearchHeaders();
    }
    return implode("\t", $headers);
  }

  /** Extracts selected search target fields from panel values. */
  private static function resultSearchFields($values): array {
    return array_values(array_intersect(
      self::resultSearchHeaders(),
      self::fieldListFromValue(self::resultSearchFieldsValue($values))
    ));
  }

  /** Returns the raw comma separated result-search fields panel value. */
  private static function resultSearchFieldsValue($values): string {
    return (string)($values['result-search-fields'] ?? '');
  }

  /** Parses a comma separated field list value. */
  private static function fieldListFromValue($value): array {
    return array_values(array_filter(
      array_map('trim', explode(',', (string)$value)),
      fn($field) => $field !== ''
    ));
  }

  /** Filters the visible result table to rows matching the search. */
  private static function filterResultSearch(string $search, array $options) {
    if (is_array(self::$resultFilterState)) {
      self::restoreFilteredResult(false);
    }
    $originalFile = self::$resultTableFile;
    if (!is_string($originalFile) || !is_file($originalFile)) {
      return false;
    }
    self::startResultFilterTask([
      'originalFile' => $originalFile,
      'search' => $search,
      'options' => $options,
      'sourceRows' => self::currentResultFilterSourceRows()
    ]);
    return 'pending-filter-task';
  }

  /** Builds a temporary file path for a filtered result. */
  private static function resultFilterFile(string $originalFile, string $search, array $options): string {
    $key = sha1($originalFile . "\0" . $search . "\0" . serialize($options) . "\0" . microtime(true));
    return dirname($originalFile) . '/filter-' . $key . '.tsv';
  }

  /** Resolves search column names or indexes against a parsed result header. */
  private static function resultSearchColumnIndexes(array $header, $columns): array {
    if ($columns === null || $columns === false || $columns === []) {
      return range(0, max(0, count($header) - 1));
    }
    if (!is_array($columns)) {
      $columns = [$columns];
    }
    $indexes = [];
    foreach ($columns as $column) {
      if (is_int($column) || ctype_digit((string)$column)) {
        $index = (int)$column;
      } else {
        $index = array_search((string)$column, $header, true);
        if ($index === false) {
          continue;
        }
      }
      if ($index >= 0 && $index < count($header)) {
        $indexes[] = $index;
      }
    }
    $indexes = array_values(array_unique($indexes));
    sort($indexes, SORT_NUMERIC);
    return $indexes;
  }

  /** Returns whether a parsed row matches the filter search options. */
  private static function resultSearchRowMatches(array $row, array $columns, string $search, array $options, $pattern): bool {
    $regexp = self::boolValue($options['regexp'] ?? false);
    $caseSensitive = self::boolValue($options['caseSensitive'] ?? false);
    foreach ($columns as $column) {
      $text = ($row[$column] ?? null) === null ? 'NULL' : (string)($row[$column] ?? null);
      if ($regexp) {
        if (preg_match($pattern, $text) === 1) {
          return true;
        }
      } else if ($caseSensitive ? str_contains($text, $search) : stripos($text, $search) !== false) {
        return true;
      }
    }
    return false;
  }

  /** Parses one internal escaped TSV result line. */
  private static function parseResultSearchTsvLine(string $line): array {
    $line = rtrim($line, "\r\n");
    $fields = [];
    $field = '';
    $escaping = false;
    $length = strlen($line);
    for ($i = 0; $i < $length; $i++) {
      $char = $line[$i];
      if ($escaping) {
        $field .= match ($char) {
          't' => "\t",
          'n' => "\n",
          'r' => "\r",
          '\\' => '\\',
          default => "\\{$char}",
        };
        $escaping = false;
        continue;
      }
      if ($char === "\\") {
        $escaping = true;
      } else if ($char === "\t") {
        $fields[] = ($field === '\N' ? null : $field);
        $field = '';
      } else {
        $field .= $char;
      }
    }
    if ($escaping) {
      $field .= '\\';
    }
    $fields[] = ($field === '\N' ? null : $field);
    return $fields;
  }

  /** Returns the current result row count for filter progress. */
  private static function currentResultFilterSourceRows(): int {
    if (self::$resultTable !== null && method_exists(self::$resultTable, 'getRowCount')) {
      return max(1, (int)self::$resultTable->getRowCount());
    }
    return 1;
  }

  /** Loads a completed filtered result file into the normal result table. */
  private static function loadResultFilterFile(string $originalFile, string $filterFile, int $rowCount, string $search, array $options): void {
    if (!is_file($filterFile)) {
      \SPTK\Elements\WarningPanel::forge('Filter unavailable', 'The filtered result file is no longer available.');
      \SPTK\Element::refresh();
      return;
    }
    self::$resultFilterState = [
      'originalFile' => $originalFile,
      'filterFile' => $filterFile,
      'rowCount' => $rowCount
    ];
    self::loadResultFile($filterFile, false);
    self::$resultTable->search($search, $options);
    self::$resultSearchSession = [
      'search' => $search,
      'regexp' => self::boolValue($options['regexp'] ?? false),
      'caseSensitive' => self::boolValue($options['caseSensitive'] ?? false),
      'fields' => $options['columns'] ?? [],
      'scope' => 'filter'
    ];
    self::deactivateEditor();
    self::deactivateList();
    self::activateResult();
    \SPTK\Element::refresh();
  }

  /** Opens the filter task and progress panel. */
  private static function startResultFilterTask(array $pending): void {
    self::abortPendingResultFilter();
    $targetFile = self::resultFilterFile($pending['originalFile'], $pending['search'], $pending['options']);
    $dir = dirname($targetFile);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
      \SPTK\Elements\ErrorPanel::forge('Could not filter result', 'Could not create the filtered result directory.');
      \SPTK\Element::refresh();
      return;
    }
    $source = fopen($pending['originalFile'], 'rb');
    if ($source === false) {
      \SPTK\Elements\ErrorPanel::forge('Could not filter result', 'Could not read the original result file.');
      \SPTK\Element::refresh();
      return;
    }
    $target = fopen($targetFile, 'wb');
    if ($target === false) {
      fclose($source);
      \SPTK\Elements\ErrorPanel::forge('Could not filter result', 'Could not create the filtered result file.');
      \SPTK\Element::refresh();
      return;
    }
    $headerLine = fgets($source);
    if ($headerLine === false || fwrite($target, $headerLine) === false) {
      fclose($source);
      fclose($target);
      if (is_file($targetFile)) {
        unlink($targetFile);
      }
      \SPTK\Elements\ErrorPanel::forge('Could not filter result', 'Could not initialize the filtered result file.');
      \SPTK\Element::refresh();
      return;
    }
    $header = self::parseResultSearchTsvLine($headerLine);
    $columns = self::resultSearchColumnIndexes($header, $pending['options']['columns'] ?? null);
    if (empty($columns)) {
      fclose($source);
      fclose($target);
      if (is_file($targetFile)) {
        unlink($targetFile);
      }
      \SPTK\Elements\WarningPanel::forge('No searchable fields', 'Please select at least one searchable result field.');
      \SPTK\Element::refresh();
      return;
    }
    self::$pendingResultFilterTask = [
      'source' => $source,
      'target' => $target,
      'originalFile' => $pending['originalFile'],
      'targetFile' => $targetFile,
      'search' => $pending['search'],
      'options' => $pending['options'],
      'columns' => $columns,
      'pattern' => self::boolValue($pending['options']['regexp'] ?? false)
        ? self::searchPattern($pending['search'], true, self::boolValue($pending['options']['caseSensitive'] ?? false))
        : false,
      'sourceRows' => max(1, (int)$pending['sourceRows']),
      'scannedRows' => 0,
      'writtenRows' => 0
    ];
    self::showResultFilterProgress();
    \SPTK\Element::refresh();
  }

  /** Shows progress while all filter matches are copied. */
  private static function showResultFilterProgress(): void {
    self::removeResultSearchPanelByName('result-filter-progress');
    $window = \SPTK\Element::firstByType('Window');
    if ($window === false || !is_array(self::$pendingResultFilterTask)) {
      return;
    }
    $panel = new \SPTK\Elements\Panel($window, 'result-filter-progress');
    $title = new \SPTK\Element($panel, null, null, 'PanelTitle');
    $title->addText('Filtering result');
    $content = new \SPTK\Element($panel, null, null, 'PanelContent');
    $progress = new \SPTK\Elements\ProgressBar($content, 'result-filter-progress-bar');
    $progress->setType('steps');
    $progress->setStepNumber(self::$pendingResultFilterTask['sourceRows']);
    $progress->setValue(0);
    $progress->setLabel('Scanned rows');
    $progress->setJobName('Matches: 0');
    $buttons = new \SPTK\Element($content, null, null, 'ButtonBox');
    self::addResultSearchPanelButton($buttons, 'ESCAPE', 'MADB\Result\ResultSearchController::cancelResultFilter', 'Cancel');
    $panel->show();
  }

  /** Advances the full filter task without blocking the UI loop. */
  private static function processPendingResultFilter(): void {
    if (!is_array(self::$pendingResultFilterTask)) {
      return;
    }
    $task =& self::$pendingResultFilterTask;
    $processed = 0;
    while ($processed < self::RESULT_FILTER_BATCH_LINES && ($line = fgets($task['source'])) !== false) {
      $task['scannedRows']++;
      $processed++;
      $row = self::parseResultSearchTsvLine($line);
      if (self::resultSearchRowMatches($row, $task['columns'], $task['search'], $task['options'], $task['pattern'])) {
        if (fwrite($task['target'], $line) === false) {
          self::abortPendingResultFilter();
          \SPTK\Elements\ErrorPanel::forge('Could not filter result', 'Could not write the filtered result file.');
          \SPTK\Element::refresh();
          return;
        }
        $task['writtenRows']++;
      }
    }
    self::syncResultFilterProgress();
    if ($line !== false) {
      \SPTK\Element::refresh();
      return;
    }
    self::finishPendingResultFilter(false);
  }

  /** Updates the filter progress panel. */
  private static function syncResultFilterProgress(): void {
    if (!is_array(self::$pendingResultFilterTask)) {
      return;
    }
    $progress = \SPTK\Element::byName('result-filter-progress-bar');
    if ($progress === false || !method_exists($progress, 'setValue')) {
      return;
    }
    $progress->setValue((int)self::$pendingResultFilterTask['scannedRows']);
    if (method_exists($progress, 'setJobName')) {
      $progress->setJobName('Matches: ' . (int)self::$pendingResultFilterTask['writtenRows']);
    }
  }

  /** Stops the filter task and keeps any matches copied so far. */
  public static function cancelResultFilter($panel = null): void {
    if ($panel !== null && method_exists($panel, 'remove')) {
      $panel->remove();
    }
    self::finishPendingResultFilter(true);
    \SPTK\Element::refresh();
  }

  /** Finalizes an active filter task and loads the completed or partial result. */
  private static function finishPendingResultFilter(bool $partial): void {
    if (!is_array(self::$pendingResultFilterTask)) {
      return;
    }
    $task = self::$pendingResultFilterTask;
    self::$pendingResultFilterTask = false;
    if (is_resource($task['source'])) {
      fclose($task['source']);
    }
    if (is_resource($task['target'])) {
      fclose($task['target']);
    }
    self::removeResultSearchPanelByName('result-filter-progress');
    if (!$partial && (int)$task['writtenRows'] === 0) {
      if (is_file($task['targetFile'])) {
        unlink($task['targetFile']);
      }
      \SPTK\Elements\WarningPanel::forge('Not found', 'No match was found in the current result.');
      \SPTK\Element::refresh();
      return;
    }
    self::loadResultFilterFile(
      $task['originalFile'],
      $task['targetFile'],
      (int)$task['writtenRows'],
      $task['search'],
      $task['options']
    );
  }

  /** Aborts any active filter task and removes its temporary file. */
  private static function abortPendingResultFilter(): void {
    if (!is_array(self::$pendingResultFilterTask)) {
      return;
    }
    $task = self::$pendingResultFilterTask;
    self::$pendingResultFilterTask = false;
    if (is_resource($task['source'])) {
      fclose($task['source']);
    }
    if (is_resource($task['target'])) {
      fclose($task['target']);
    }
    if (is_file($task['targetFile'])) {
      unlink($task['targetFile']);
    }
    self::removeResultSearchPanelByName('result-filter-progress');
  }

  /** Adds a button to a dynamic result-search panel. */
  private static function addResultSearchPanelButton($parent, string $hotKey, string $callback, string $text, string $name = null): void {
    $button = new \SPTK\Elements\Button($parent, $name);
    $button->setHotKey($hotKey);
    $button->setOnPress($callback);
    $button->addText($text);
  }

  /** Removes a dynamic result-search panel by name. */
  private static function removeResultSearchPanelByName(string $name): void {
    $panel = \SPTK\Element::byName($name);
    if ($panel !== false) {
      $panel->remove();
    }
  }

  /** Restores the original result file after a filter search. */
  private static function restoreFilteredResult(bool $clearSearch = true): bool {
    if (!is_array(self::$resultFilterState)) {
      return false;
    }
    $originalFile = self::$resultFilterState['originalFile'] ?? false;
    self::clearResultFilterState();
    if (!is_string($originalFile) || !is_file($originalFile)) {
      return false;
    }
    self::loadResultFile($originalFile, false);
    if ($clearSearch && self::$resultTable !== null && method_exists(self::$resultTable, 'clearSearch')) {
      self::$resultTable->clearSearch();
    }
    return true;
  }

  /** Clears filtered-result bookkeeping and removes the temporary filtered file. */
  private static function clearResultFilterState(): void {
    if (!is_array(self::$resultFilterState)) {
      return;
    }
    $filterFile = self::$resultFilterState['filterFile'] ?? false;
    self::$resultFilterState = false;
    if (is_string($filterFile) && is_file($filterFile)) {
      unlink($filterFile);
    }
  }

  /** Cleans up active and stale filtered result files before application exit. */
  public static function cleanupResultFilters(): void {
    self::abortPendingResultFilter();
    self::clearResultFilterState();
    \MADB\Result\ResultStore::deleteFilterFiles();
  }

}
