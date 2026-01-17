<?php

namespace MADB\Config;

class ConfigDir {

  private static $dirName = '.madb';
  private static $path = false;

  private static function setPath() {
    $home = getenv('HOME') ?: getenv('USERPROFILE');
    if (!$home) {
      $home = '.';
    }
    self::$path = realpath($home) . '/' . self::$dirName;
    if (!is_dir(self::$path)) {
      mkdir(self::$path);
    }
  }

  public static function getPath() {
    if (self::$path === false) {
      self::setPath();
    }
    return self::$path;
  }

  public static function getFilePath($name) {
    if (self::$path === false) {
      self::setPath();
    }
    return self::$path . '/' . $name;
  }

}
