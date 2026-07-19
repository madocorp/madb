<?php

namespace MADB\App;

/** Initializes application configuration when the UI starts. */
class Init {

  /** Coordinates callback work in the application. */
  public static function callback() {
    new \MADB\Connection\ConnectionList;
    new \MADB\List\QueryList;
    \MADB\Connection\MenuController::updateConnectionList();
    \MADB\Main\ScreenController::init();
    \MADB\App\MenuController::askMasterPassword();
  }

}
