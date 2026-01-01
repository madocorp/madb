<?php

namespace MADB\Config;

class Init {

  public static function callback() {
    new \MADB\Connection\ConnectionList;
    \MADB\Connection\MenuController::updateConnectionList();
    \MADB\Main\ScreenController::init();
  }

}