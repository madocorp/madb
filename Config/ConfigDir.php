<?php

namespace MADB\Config;

class ConfigDir {

  protected $dirName = '.MADB';
  protected $path;

  public function __construct() {
    $home = getenv('HOME') ?: getenv('USERPROFILE'); // SDL_GetPrefPath ?
    if (!$home) {
      $home = '.';
    }
    $this->path = realpath($home . DIRECTORY_SEPARATOR . $this->dirName);
    if (!is_dir($this->path)) {
      mkdir($this->path);
    }
  }

  public function getPath() {
    return $this->path;
  }

}
