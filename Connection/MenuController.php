<?php

namespace MADB\Connection;

class MenuController {

  public static function updateConnectionList() {
    $connectionList = ConnectionList::getInstance();
    $nameList = $connectionList->getNameList();
    $menuBox = \SPTK\Element::getById('submenu-connection');
    foreach ($nameList as $name) {
      $menuItem = new \SPTK\MenuBoxItem($menuBox);
      $menuItem->setRadio('connections');
      $text = new \SPTK\Word($menuItem);
      $text->setValue($name);
    }
  }

}

