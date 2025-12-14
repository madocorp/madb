<?php

namespace MADB\Connection;

use SPTK\Element;

class EditController {

  public static function open() {
    $panel = Element::getById('connection-editor');
    $panel->show();
    Element::refresh();
  }

  public static function close() {
    $panel = Element::getById('connection-editor');
    $panel->hide();
    Element::refresh();
  }

  public static function save() {
    $panel = Element::getById('connection-editor');
    $panel->hide();
    var_dump($panel->getValue());
    Element::refresh();
  }

  public static function test() {
    echo "TEST!\n";
  }

}
