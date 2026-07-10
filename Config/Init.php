<?php

namespace MADB\Config;

/** Initializes application configuration when the UI starts. */
class Init {

  /** Coordinates callback work in the application. */
  public static function callback() {
    new \MADB\Connection\ConnectionList;
    new \MADB\Query\QueryList;
    \MADB\Connection\MenuController::updateConnectionList();
    \MADB\Main\ScreenController::init();
  }

}
