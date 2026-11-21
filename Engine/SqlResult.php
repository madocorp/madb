<?php

namespace MADB\Engine;

/** Collect a bounded preview of one SQL statement's result. */
final class SqlResult {

  private const MAX_ROWS = 500;
  private const MAX_CELL_BYTES = 65536;

  public static function run(\PDO $pdo, string $sql): array {
    if (trim($sql) === '') {
      throw new \InvalidArgumentException('Query is empty.');
    }
    $started = microtime(true);
    $statement = $pdo->query($sql);
    if ($statement === false) {
      throw new \RuntimeException('Query failed.');
    }
    $columns = [];
    for ($index = 0; $index < $statement->columnCount(); $index++) {
      $columns[] = (string)($statement->getColumnMeta($index)['name'] ?? 'Column ' . ($index + 1));
    }
    $rows = [];
    while (count($rows) < self::MAX_ROWS && ($row = $statement->fetch(\PDO::FETCH_NUM)) !== false) {
      $rows[] = array_map(self::cell(...), $row);
    }
    $truncated = $statement->fetch(\PDO::FETCH_NUM) !== false;
    return [
      'columns' => $columns,
      'rows' => $rows,
      'affectedRows' => $statement->rowCount(),
      'truncated' => $truncated,
      'elapsedMs' => (int)round((microtime(true) - $started) * 1000),
    ];
  }

  /** Preserve binary values and mark long preview cells. */
  private static function cell(mixed $value): mixed {
    if (!is_string($value)) {
      return $value;
    }
    $truncated = strlen($value) > self::MAX_CELL_BYTES;
    $sample = $truncated ? substr($value, 0, self::MAX_CELL_BYTES) : $value;
    if (!mb_check_encoding($sample, 'UTF-8')) {
      return ['base64' => base64_encode($sample), 'truncated' => $truncated];
    }
    return $truncated ? ['text' => mb_strcut($value, 0, self::MAX_CELL_BYTES, 'UTF-8'), 'truncated' => true] : $value;
  }

}
