<?php

namespace MADB\Query;

class ResultStore {

  private static $directoryName = 'query-results';

  public static function relativePath($connectionName, $queryId) {
    return self::relativePathForResult($connectionName, $queryId, false);
  }

  public static function relativePathForResult($connectionName, $queryId, $resultIndex = false) {
    $connectionKey = substr(sha1((string)$connectionName), 0, 12);
    $queryKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$queryId);
    if ($queryKey === '') {
      $queryKey = bin2hex(random_bytes(8));
    }
    $suffix = $resultIndex === false ? '' : '-' . (int) $resultIndex;
    return self::$directoryName . "/{$connectionKey}-{$queryKey}{$suffix}.tsv";
  }

  public static function absolutePath($relativePath) {
    if ($relativePath === false || $relativePath === '') {
      return false;
    }
    if (strpos($relativePath, '/') === 0) {
      return $relativePath;
    }
    return \SPTK\Config::getFilePath($relativePath);
  }

  public static function delete($relativePath) {
    $file = self::absolutePath($relativePath);
    if ($file !== false && file_exists($file)) {
      unlink($file);
    }
  }

  public static function deleteForQuery($connectionName, $queryId) {
    self::delete(self::relativePath($connectionName, $queryId));
  }

  public static function deleteMany($relativePaths): void {
    if (!is_array($relativePaths)) {
      return;
    }
    foreach ($relativePaths as $path) {
      if (is_array($path)) {
        self::delete($path['file'] ?? false);
      } else {
        self::delete($path);
      }
    }
  }

}
