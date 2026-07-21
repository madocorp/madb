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

  /** Cleans up temporary runtime files before the app exits. */
  public static function shutdown(): void {
    \MADB\Main\ScreenController::cleanupResultExports();
    \MADB\Main\ScreenController::cleanupResultFilters();
  }

}
