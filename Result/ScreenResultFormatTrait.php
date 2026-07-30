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
    if (($query['status'] ?? 'new') === 'running' && !empty($query['info']['interruptRequested'])) {
      $blocks[] = 'Stop requested: current chunk will finish, following chunks will not run.';
    }
    foreach (self::startedBatchStatusStatements($query['statements'] ?? []) as $statement) {
      $blocks[] = self::formatStatementStatusBlock($statement, true);
    }
    $info = self::formatInfo($query);
    if ($info !== '') {
      $blocks[] = $info;
    }
    $controlMessage = self::formatBatchControlMessage($query);
    if ($controlMessage !== '') {
      $blocks[] = $controlMessage;
    }
    if (empty($blocks) && ($query['status'] ?? 'new') === 'running') {
      return 'Running...';
    }
    return implode("\n\n", $blocks);
  }

  /** Formats final stop/kill state for the end of a batch status report. */
  private static function formatBatchControlMessage($query): string {
    if (($query['status'] ?? 'new') === 'running') {
      return '';
    }
    if (!empty($query['info']['killRequested'])) {
      return 'Query worker killed.';
    }
    if (!empty($query['info']['interruptRequested'])) {
      return 'Query interrupted.';
    }
    return '';
  }

  /** Returns only statements that have actually reached execution. */
  private static function startedBatchStatusStatements($statements): array {
    $started = [];
    foreach (is_array($statements) ? $statements : [] as $statement) {
      if (in_array(($statement['status'] ?? ''), ['RUNNING', 'OK', 'ERROR'], true)) {
        $started[] = $statement;
      }
    }
    return $started;
  }

  /** Formats only the active statement while a batch is running. */
  private static function formatRunningBatchStatus($query, $statement): string {
    if (self::isSmallQueryBatch($query)) {
      return self::formatBatchStatus($query);
    }
    $statements = is_array($query['statements'] ?? false) ? $query['statements'] : [];
    $total = count($statements);
    $chunkSize = max(1, (int)($query['info']['batch']['chunkSize'] ?? self::QUERY_LARGE_BATCH_MIN_CHUNK_SIZE));
    $currentStatement = self::runningOrLastBatchStatement($query, $statement);
    $statementNumber = $currentStatement === false ? 0 : (int)($currentStatement['index'] ?? 0) + 1;
    $statementNumber = max(1, min(max(1, $total), $statementNumber));
    $chunkNumber = (int)ceil($statementNumber / $chunkSize);
    $chunkTotal = (int)ceil(max(1, $total) / $chunkSize);
    $lines = [
      'Chunk size: ' . $chunkSize,
      'Chunk: ' . $chunkNumber . ' / ' . $chunkTotal,
      'Statement: ' . $statementNumber . ' / ' . $total
    ];
    if (!empty($query['info']['interruptRequested'])) {
      $lines[] = 'Stop requested: current chunk will finish, following chunks will not run.';
    }
    $info = self::formatRunningBatchInfo($query);
    if ($info !== '') {
      $lines[] = $info;
    }
    return implode("\n", $lines);
  }

  /** Formats elapsed whole-batch time while a large batch is still running. */
  private static function formatRunningBatchInfo($query): string {
    $startedAt = (float)($query['info']['batch']['startedAt'] ?? 0);
    if ($startedAt <= 0) {
      foreach (($query['statements'] ?? []) as $statement) {
        if (!empty($statement['startedAt'])) {
          $startedAt = (float)$statement['startedAt'];
          break;
        }
      }
    }
    if ($startedAt <= 0) {
      $startedAt = (float)($query['info']['times']['s'] ?? 0);
    }
    if ($startedAt <= 0) {
      return '';
    }
    $lines = [];
    $pid = $query['info']['pid'] ?? false;
    if ($pid !== false) {
      $lines[] = 'PID: ' . $pid;
    }
    $lines[] = 'Time: ' . self::formatDuration(microtime(true) - $startedAt);
    return implode("\n", $lines);
  }

  /** Returns the statement currently running or the last statement returned by the latest chunk. */
  private static function runningOrLastBatchStatement($query, $statement) {
    if ($statement !== false && ($statement['status'] ?? '') === 'RUNNING') {
      return $statement;
    }
    foreach (($query['statements'] ?? []) as $candidate) {
      if (($candidate['status'] ?? '') === 'RUNNING') {
        return $candidate;
      }
    }
    $lastChunk = $query['info']['lastChunkStatements'] ?? [];
    if (is_array($lastChunk) && !empty($lastChunk)) {
      return end($lastChunk);
    }
    $lastFinished = false;
    foreach (($query['statements'] ?? []) as $candidate) {
      if (in_array(($candidate['status'] ?? ''), ['OK', 'ERROR'], true)) {
        $lastFinished = $candidate;
      }
    }
    return $lastFinished;
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
