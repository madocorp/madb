<?php

namespace MADB\Connection;

use SPTK\Element;

class EditController {

  public static function create() {
    $panel = Element::byName('connection-editor');
    $connection = new Connection([]);
    $panel->setValue($connection->data);
    $panel->show();
    Element::refresh();
  }

  public static function edit() {
    $connectionList = ConnectionList::getInstance();
    $connection = $connectionList->current;
    if ($connection === false) {
      $panel = Element::byName('please-select-connection');
    } else {
      $panel = Element::byName('connection-editor');
      $panel->setValue($connection->data);
    }
    $panel->show();
    Element::refresh();
  }

  public static function close() {
    $panel = Element::byName('connection-editor');
    $panel->hide();
    Element::refresh();
  }

  public static function save() {
    $panel = Element::byName('connection-editor');
    $panel->hide();
    $connectionData = $panel->getValue();
    $connections = ConnectionList::getInstance();
    $connections->add($connectionData);
    $connections->save();
    MenuController::updateConnectionList();
    Element::refresh();
  }

  public static function test() {
    echo "TEST!\n";
  }

}
