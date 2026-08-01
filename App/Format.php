<?php

namespace MADB\App;

/** Shared formatting helpers for user-facing values. */
class Format {

  /** Formats bytes with binary units. */
  public static function bytes($bytes, int $precision = 2, string $separator = ' '): string {
    $value = max(0, (int)$bytes);
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
      $value /= 1024;
      $unit++;
    }
    if ($unit === 0) {
      return (string)(int)$value . $separator . $units[$unit];
    }
    $formatted = number_format($value, max(0, $precision), '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');
    return $formatted . $separator . $units[$unit];
  }

}
