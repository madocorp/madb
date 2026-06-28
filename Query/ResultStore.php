<?php

namespace MADB\Query;

class ResultStore {

  private static $directoryName = 'query-results';

  public static function relativePath($connectionName, $queryId) {
    $connectionKey = substr(sha1((string)$connectionName), 0, 12);
    $queryKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$queryId);
    if ($queryKey === '') {
      $queryKey = bin2hex(random_bytes(8));
    }
    return self::$directoryName . "/{$connectionKey}-{$queryKey}.tsv";
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

}
