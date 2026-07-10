<?php

namespace MADB\Config;

use \SPTK\Element;

/** Routes application-level menu callbacks such as About and Quit. */
class MenuController {

  /** Coordinates about work in the application. */
  public static function about() {
    $panel = Element::byName('about');
    $panel->show();
    Element::refresh();
  }

  /** Coordinates quit work in the application. */
  public static function quit() {
    \SPTK\App::$instance->quit();
  }

}