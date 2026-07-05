<?php

namespace MADB\Query;

class SqlSplitter {

  public static function split($sql): array {
    $sql = (string) $sql;
    $length = strlen($sql);
    $statements = [];
    $start = 0;
    $state = 'normal';

    for ($i = 0; $i < $length; $i++) {
      $char = $sql[$i];
      $next = $i + 1 < $length ? $sql[$i + 1] : '';

      if ($state === 'normal') {
        if ($char === "'") {
          $state = 'single';
          continue;
        }
        if ($char === '"') {
          $state = 'double';
          continue;
        }
        if ($char === '`') {
          $state = 'identifier';
          continue;
        }
        if ($char === '-' && $next === '-') {
          $after = $i + 2 < $length ? $sql[$i + 2] : '';
          if ($after === '' || ctype_space($after)) {
            $state = 'line-comment';
            $i++;
            continue;
          }
        }
        if ($char === '#') {
          $state = 'line-comment';
          continue;
        }
        if ($char === '/' && $next === '*') {
          $state = 'block-comment';
          $i++;
          continue;
        }
        if ($char === ';') {
          self::appendStatement($statements, $sql, $start, $i + 1);
          $start = $i + 1;
        }
        continue;
      }

      if ($state === 'single') {
        if ($char === '\\') {
          $i++;
          continue;
        }
        if ($char === "'" && $next === "'") {
          $i++;
          continue;
        }
        if ($char === "'") {
          $state = 'normal';
        }
        continue;
      }

      if ($state === 'double') {
        if ($char === '\\') {
          $i++;
          continue;
        }
        if ($char === '"' && $next === '"') {
          $i++;
          continue;
        }
        if ($char === '"') {
          $state = 'normal';
        }
        continue;
      }

      if ($state === 'identifier') {
        if ($char === '`' && $next === '`') {
          $i++;
          continue;
        }
        if ($char === '`') {
          $state = 'normal';
        }
        continue;
      }

      if ($state === 'line-comment') {
        if ($char === "\n" || $char === "\r") {
          $state = 'normal';
        }
        continue;
      }

      if ($state === 'block-comment') {
        if ($char === '*' && $next === '/') {
          $state = 'normal';
          $i++;
        }
      }
    }

    self::appendStatement($statements, $sql, $start, $length);
    return $statements;
  }

  public static function statementAt($sql, $offset) {
    $offset = max(0, (int) $offset);
    $statements = self::split($sql);
    foreach ($statements as $statement) {
      if ($offset >= $statement['start'] && $offset <= $statement['end']) {
        return $statement;
      }
    }
    foreach ($statements as $statement) {
      if ($offset < $statement['start']) {
        return $statement;
      }
    }
    return empty($statements) ? false : $statements[count($statements) - 1];
  }

  private static function appendStatement(&$statements, $sql, $rawStart, $rawEnd): void {
    $start = $rawStart;
    $end = $rawEnd;
    while ($start < $end && ctype_space($sql[$start])) {
      $start++;
    }
    while ($end > $start && ctype_space($sql[$end - 1])) {
      $end--;
    }
    if ($end > $start && $sql[$end - 1] === ';') {
      $end--;
      while ($end > $start && ctype_space($sql[$end - 1])) {
        $end--;
      }
    }
    if ($end <= $start) {
      return;
    }
    $statements[] = [
      'sql' => substr($sql, $start, $end - $start),
      'start' => $start,
      'end' => $end,
      'rawStart' => $rawStart,
      'rawEnd' => $rawEnd
    ];
  }

}
