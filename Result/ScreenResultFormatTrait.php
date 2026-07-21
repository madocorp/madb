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

/** Formats query results, batch progress, and metadata messages before they are shown in the result panel. */
trait ScreenResultFormatTrait {

  /** Formats result text for the query workspace. */
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

  /** Formats batch status text for the query workspace. */
  private static function formatBatchStatus($query): string {
    $blocks = [];
    foreach (($query['statements'] ?? []) as $statement) {
      $blocks[] = self::formatStatementStatusBlock($statement, true);
    }
    $info = self::formatInfo($query);
    if ($info !== '') {
      $blocks[] = $info;
    }
    return implode("\n\n", $blocks);
  }

  /** Formats one statement status block for the query workspace. */
  private static function formatStatementStatusBlock($statement, bool $includeSql): string {
    $index = (int) ($statement['index'] ?? 0);
    $number = $index + 1;
    $status = $statement['status'] ?? 'NOT RUN';
    $lines = ["#{$number} {$status}"];
    if ($status === 'NOT RUN') {
      $lines[] = '  This query has not been executed yet.';
    }
    if (isset($statement['result']['affectedRows'])) {
      $lines[] = '  Affected rows: ' . $statement['result']['affectedRows'];
    } else if (isset($statement['result']['rowCount'])) {
      $lines[] = '  Rows: ' . $statement['result']['rowCount'];
    } else if (isset($statement['result']['rows'])) {
      $lines[] = '  Rows: ' . count($statement['result']['rows']);
    }
    if (isset($statement['time'])) {
      $lines[] = '  Time: ' . $statement['time'] . 's';
    }
    if (!empty($statement['startedAt'])) {
      $lines[] = '  Started: ' . date('Y-m-d H:i:s', (int) $statement['startedAt']);
    }
    if (in_array($status, ['RUNNING', 'PENDING']) && !empty($statement['startedAt'])) {
      $lines[] = '  Running: ' . self::formatDuration(microtime(true) - (float) $statement['startedAt']);
    }
    if (isset($statement['finishedAt'])) {
      $lines[] = '  Finished: ' . date('Y-m-d H:i:s', (int) $statement['finishedAt']);
    }
    if ($status === 'ERROR') {
      $lines[] = '  ERROR: ' . ($statement['error'] ?? 'Unknown error');
    }
    if ($includeSql) {
      $sql = self::formatStatementSqlPreview($statement);
      if ($sql !== '') {
        $lines[] = '  SQL: ' . $sql;
      }
    }
    return implode("\n", $lines);
  }

  /** Formats a normalized SQL preview, appending ellipsis only when truncated. */
  private static function formatStatementSqlPreview($statement, int $limit = 120): string {
    $sql = trim(preg_replace('/\s+/', ' ', (string) ($statement['sql'] ?? '')));
    if ($sql === '') {
      return '';
    }
    if (mb_strlen($sql) <= $limit) {
      return $sql;
    }
    return mb_substr($sql, 0, max(0, $limit - 3)) . '...';
  }

  /** Formats info text for the query workspace. */
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

}
