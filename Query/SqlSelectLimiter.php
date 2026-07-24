<?php

namespace MADB\Query;

/** Adds MADB's default SELECT limit marker behavior to split SQL statements. */
class SqlSelectLimiter {

  /** Rewrites selected statements in editor SQL by appending missing SELECT limits. */
  public static function editorSql(string $sql, array $statements, int $limit, $selectedIndexes = false): string {
    $ranges = [];
    foreach ($statements as $offset => $statement) {
      $index = (int)($statement['index'] ?? $offset);
      if (is_array($selectedIndexes) && !in_array($index, $selectedIndexes, true)) {
        continue;
      }
      $statementSql = (string)($statement['sql'] ?? '');
      if (!self::shouldAppendLimit($statementSql)) {
        continue;
      }
      $insert = self::limitInsertOffset($statementSql);
      $absolute = (int)($statement['start'] ?? 0) + $insert;
      $prefix = $insert > 0 && !ctype_space($statementSql[$insert - 1]) ? "\n" : '';
      $suffix = $insert < strlen($statementSql) && !ctype_space($statementSql[$insert]) ? "\n" : '';
      $ranges[] = [
        'offset' => $absolute,
        'text' => $prefix . 'LIMIT ' . $limit . $suffix
      ];
    }
    if (empty($ranges)) {
      return $sql;
    }
    usort($ranges, fn($a, $b) => $b['offset'] <=> $a['offset']);
    foreach ($ranges as $range) {
      $sql = substr($sql, 0, $range['offset']) . $range['text'] . substr($sql, $range['offset']);
    }
    return $sql;
  }

  /** Returns the SQL to execute for a statement, stripping MADB-only markers. */
  public static function executionSql(string $sql): string {
    return self::removeRanges($sql, self::unlimitedMarkerRanges($sql));
  }

  /** Returns whether a statement needs an automatic LIMIT clause. */
  private static function shouldAppendLimit(string $sql): bool {
    return self::statementType($sql) === 'SELECT'
      && !self::hasTopLevelKeyword($sql, 'LIMIT')
      && empty(self::unlimitedMarkerRanges($sql));
  }

  /** Returns where an automatic LIMIT should be inserted inside the statement SQL. */
  private static function limitInsertOffset(string $sql): int {
    $fallback = strlen(rtrim($sql));
    foreach (self::topLevelWords($sql) as $word) {
      if (in_array($word['upper'], ['FOR', 'LOCK'], true)) {
        return (int)$word['offset'];
      }
    }
    return $fallback;
  }

  /** Returns removable ranges for MADB's top-level UNLIMITED marker. */
  private static function unlimitedMarkerRanges(string $sql): array {
    $ranges = [];
    $words = self::topLevelWords($sql);
    $count = count($words);
    foreach ($words as $index => $word) {
      if ($word['upper'] !== 'UNLIMITED' || !self::isUnlimitedMarkerPosition($words, $index)) {
        continue;
      }
      $start = (int)$word['offset'];
      $end = (int)$word['end'];
      while ($start > 0 && ctype_space($sql[$start - 1])) {
        $start--;
      }
      if ($start === (int)$word['offset']) {
        while ($end < strlen($sql) && ctype_space($sql[$end])) {
          $end++;
        }
      }
      $ranges[] = ['start' => $start, 'end' => $end];
    }
    return $ranges;
  }

  /** Returns whether a top-level UNLIMITED word is in marker position. */
  private static function isUnlimitedMarkerPosition(array $words, int $index): bool {
    for ($i = $index + 1, $count = count($words); $i < $count; $i++) {
      if (!in_array($words[$i]['upper'], ['FOR', 'UPDATE', 'LOCK', 'IN', 'SHARE', 'MODE'], true)) {
        return false;
      }
    }
    return true;
  }

  /** Removes SQL byte ranges from right to left. */
  private static function removeRanges(string $sql, array $ranges): string {
    if (empty($ranges)) {
      return trim($sql);
    }
    usort($ranges, fn($a, $b) => $b['start'] <=> $a['start']);
    foreach ($ranges as $range) {
      $sql = substr($sql, 0, $range['start']) . substr($sql, $range['end']);
    }
    return trim($sql);
  }

  /** Returns the primary SQL statement type for automatic limit behavior. */
  private static function statementType(string $sql): string {
    $words = self::topLevelWords($sql);
    if (($words[0]['upper'] ?? '') === 'WITH') {
      foreach ($words as $word) {
        if (in_array($word['upper'], ['SELECT', 'UPDATE', 'DELETE'], true)) {
          return $word['upper'];
        }
      }
    }
    foreach ($words as $word) {
      if ($word['upper'] === 'WITH') {
        continue;
      }
      return $word['upper'];
    }
    return '';
  }

  /** Returns whether a keyword appears at top level in SQL text. */
  private static function hasTopLevelKeyword(string $sql, string $keyword): bool {
    $keyword = strtoupper($keyword);
    foreach (self::topLevelWords($sql) as $word) {
      if ($word['upper'] === $keyword) {
        return true;
      }
    }
    return false;
  }

  /** Yields top-level SQL words while ignoring strings, identifiers, comments, and nested expressions. */
  private static function topLevelWords(string $sql): array {
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
          'offset' => $offset,
          'end' => $i + 1
        ];
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

}
