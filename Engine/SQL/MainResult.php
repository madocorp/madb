<?php

namespace MADB\Engine\SQL;

use SPTK\Core\Screen;
use SPTK\Widgets\Table\Table;

/** Render a SQL worker response as a navigable table. */
final class MainResult {

  /** Replace the visible result table with a bounded SQL result. */
  public static function render(Screen $screen, array $result): void {
    $table = $screen->widget('result-table');
    if (!$table instanceof Table) {
      throw new \LogicException('SQL result table is missing.');
    }
    $rows = [];
    foreach ($result['rows'] ?? [] as $row) {
      $rows[] = array_map(self::cell(...), $row);
    }
    $table->setRows(array_map('strval', $result['columns'] ?? []), $rows);
  }

  /** Turn a bounded worker cell into table text. */
  private static function cell(mixed $value): ?string {
    if ($value === null) {
      return null;
    }
    if (is_array($value)) {
      return isset($value['base64']) ? '[base64] ' . $value['base64'] : (string)($value['text'] ?? json_encode($value));
    }
    return (string)$value;
  }

}
