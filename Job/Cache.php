<?php

namespace MADB\Job;

class Cache {

  private static $cache = [];

  public static function get($key) {
    if (isset(self::$cache[$key])) {
      return ['wrokerId' => 'cache', 'status' => 'OK', 'result' => self::$cache[$key]];
    }
    return false;
  }

  public static function set($key, $response) {
    self::$cache[$key] = $response['result'];
  }

  public static function clear($key) {
    unset(self::$cache[$key]);
  }

  public static function clearLike($keyToClear) {
    foreach (self::$cache as $key => $result) {
      if (strpos($key, $keyToClear) === 0) {
        unset(self::$cache[$key]);
      }
    }
  }

}
