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
