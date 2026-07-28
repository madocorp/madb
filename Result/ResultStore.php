<?php

namespace MADB\Result;

/** Builds and removes result file paths for query execution output. */
class ResultStore {

  private static $directoryName = 'query-results';

  private static function connectionKey($connectionName): string {
    return substr(sha1((string)$connectionName), 0, 12);
  }

  private static function rootDirectory(): string {
    return rtrim(\MADB\App\Settings::queryResultDirectory(), '/');
  }

  /** Coordinates relative path work in the query support. */
  public static function relativePath($connectionName, $queryId) {
    return self::relativePathForResult($connectionName, $queryId, false);
  }

  /** Coordinates relative path for result work in the query support. */
  public static function relativePathForResult($connectionName, $queryId, $resultIndex = false) {
    $connectionKey = self::connectionKey($connectionName);
    $queryKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$queryId);
    if ($queryKey === '') {
      $queryKey = bin2hex(random_bytes(8));
    }
    $suffix = $resultIndex === false ? '' : '-' . (int) $resultIndex;
    return "{$connectionKey}/{$queryKey}{$suffix}.tsv";
  }

  /** Coordinates absolute path work in the query support. */
  public static function absolutePath($relativePath) {
    if ($relativePath === false || $relativePath === '') {
      return false;
    }
    if (strpos($relativePath, '/') === 0) {
      return $relativePath;
    }
    return self::rootDirectory() . '/' . ltrim($relativePath, '/');
  }

  /** Removes delete from the query support. */
  public static function delete($relativePath) {
    $file = self::absolutePath($relativePath);
    if ($file !== false && file_exists($file)) {
      unlink($file);
    }
  }

  /** Removes for query from the query support. */
  public static function deleteForQuery($connectionName, $queryId) {
    self::delete(self::relativePath($connectionName, $queryId));
  }

  /** Removes many from the query support. */
  public static function deleteMany($relativePaths): void {
    if (!is_array($relativePaths)) {
      return;
    }
    foreach ($relativePaths as $path) {
      if (is_array($path)) {
        self::delete($path['file'] ?? false);
        if (isset($path['result']) && is_array($path['result'])) {
          self::delete($path['result']['file'] ?? false);
        }
      } else {
        self::delete($path);
      }
    }
  }

  /** Removes temporary filtered result files left by result search filtering. */
  public static function deleteFilterFiles(): void {
    $root = self::rootDirectory();
    foreach ([
      $root . '/filter-*.tsv',
      $root . '/*/filter-*.tsv'
    ] as $pattern) {
      foreach (glob($pattern, GLOB_NOSORT) ?: [] as $file) {
        if (is_file($file)) {
          unlink($file);
        }
      }
    }
  }

}
