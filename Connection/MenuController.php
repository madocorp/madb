<?php

namespace MADB\Connection;

class MenuController {

  public static function updateConnectionList() {
    $connectionList = ConnectionList::getInstance();
    $nameList = $connectionList->getNameList();
    $menuBox = \SPTK\Element::byName('submenu-connection');
    $menuBox->clear();
    $manageMenu = new \SPTK\MenuBoxItem($menuBox, 'menu-connection-manage', 'MenuSeparator');
    $menuText = new \SPTK\Word($manageMenu);
    $menuText->setValue('Manage');
    $manageMenu->setSubmenu('true');
    $currentName = false;
    if ($connectionList->current !== false) {
      $currentName = $connectionList->current->data['name'];
    }
    foreach ($nameList as $name) {
      $menuItem = new \SPTK\MenuBoxItem($menuBox);
      $menuItem->setRadio('connections');
      $menuItem->setOnSelect('\MADB\Connection\MenuController::select');
      $text = new \SPTK\Word($menuItem);
      $text->setValue($name);
      if ($name == $currentName) {
        $menuItem->setSelected('true');
      }
    }
    $menuBox->calculateGeometry();
  }

  public static function select($element, $selected) {
    $connectionList = ConnectionList::getInstance();
    $connectionList->setCurrent($element->getValue());
  }

}

