<?php

namespace MADB\Config;

use \SPTK\Element;

class MenuController {

  public static function about() {
    $panel = Element::byName('about');
    $panel->show();
    Element::refresh();
  }

  public static function quit() {
    exit(0);
  }

}