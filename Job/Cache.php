<?php

namespace MADB\Job;

class Cache {

  private static $cache = [];

  public static function get($connection, $key) {
    if (isset(self::$cache[$connection][$key])) {
      return ['wrokerId' => 'cache', 'status' => 'OK', 'result' => self::$cache[$connection][$key]];
    }
    return false;
  }

  public static function set($connection, $key, $response) {
    self::$cache[$connection][$key] = $response['result'];
  }

  public static function clearAll() {
    self::$cache = [];
  }

  public static function clearConnection($connection) {
    unset(self::$cache[$connection]);
  }

  public static function clear($connection, $key) {
    unset(self::$cache[$connection][$key]);
  }

  public static function count($connection) {
    if (!isset(self::$cache[$connection])) {
      return 0;
    }
    return count(self::$cache[$connection]);
  }

}
