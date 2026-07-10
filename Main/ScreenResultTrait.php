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

/** Renders query results and batch statement status in the result panel, including source-statement highlighting. */
trait ScreenResultTrait {

  /** Clears result state from the query workspace. */
  private static function clearResult($clearHighlight = true) {
    self::clearPendingResultLoad();
    self::$resultMessage->setText('');
    self::$resultMessage->hide();
    self::$resultStatus->setText('');
    self::$resultStatus->hide();
    self::$resultTable->hide();
    if ($clearHighlight) {
      self::clearResultHighlight();
    }
  }

  /** Coordinates show result work in the query workspace. */
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
  private static function loadResultFile(string $file): void {
    self::$resultTable->setFile($file);
    self::$resultTable->show();
    self::syncResultTableHeader();
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
    $index = (int) ($statement['index'] ?? 0);
    $number = $index + 1;
    $status = $statement['status'] ?? 'NOT RUN';
    if ($status === 'NOT RUN') {
      return "#{$number} NOT RUN\nThis query has not been executed yet.";
    }
    $lines = ["#{$number} {$status}"];
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
