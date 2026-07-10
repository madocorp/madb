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
    $lines = [];
    foreach (($query['statements'] ?? []) as $statement) {
      $index = (int) ($statement['index'] ?? count($lines));
      $prefix = '#' . ($index + 1) . ' ' . ($statement['status'] ?? 'OK');
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
