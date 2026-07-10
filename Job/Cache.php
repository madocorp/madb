<?php

namespace MADB\Job;

/** Stores cached job responses for connection-specific schema and table metadata. */
class Cache {

  private static $cache = [];

  /** Returns get data used by the background job system. */
  public static function get($connection, $key) {
    if (isset(self::$cache[$connection][$key])) {
      return ['wrokerId' => 'cache', 'status' => 'OK', 'result' => self::$cache[$connection][$key]];
    }
    return false;
  }

  /** Applies set values to background job system state or controls. */
  public static function set($connection, $key, $response) {
    self::$cache[$connection][$key] = $response['result'];
  }

  /** Clears all state from the background job system. */
  public static function clearAll() {
    self::$cache = [];
  }

  /** Clears connection state from the background job system. */
  public static function clearConnection($connection) {
    unset(self::$cache[$connection]);
  }

  /** Clears clear state from the background job system. */
  public static function clear($connection, $key) {
    unset(self::$cache[$connection][$key]);
  }

  /** Coordinates count work in the background job system. */
  public static function count($connection) {
    if (!isset(self::$cache[$connection])) {
      return 0;
    }
    return count(self::$cache[$connection]);
  }

}
