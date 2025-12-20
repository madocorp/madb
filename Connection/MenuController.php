<?php

namespace MADB\Connection;

class MenuController {

  public static function updateConnectionList() {
    $connectionList = ConnectionList::getInstance();
    $nameList = $connectionList->getNameList();
    $menuBox = \SPTK\Element::byName('submenu-connection');
    $menuBox->clear();
    $manageMenu = new \SPTK\MenuBoxItem($menuBox, 'menu-connection-manage', 'MenuSeparator');
    $manageMenu->addText('Manage');
    $manageMenu->setSubmenu('true');
    $currentName = false;
    if ($connectionList->current !== false) {
      $currentName = $connectionList->current['name'];
    }
    foreach ($nameList as $name) {
      $menuItem = new \SPTK\MenuBoxItem($menuBox);
      $menuItem->setRadio('connections');
      $menuItem->setOnSelect('\MADB\Connection\MenuController::select');
      $menuItem->addText($name);
      if ($name == $currentName) {
        $menuItem->setSelected('true');
      }
    }
    $menuBox->calculateGeometry();
  }

  public static function select($element, $selected) {
    $connectionList = ConnectionList::getInstance();
    $connectionList->setCurrent($element->getValue());
    if ($connectionList->current === false) {
      return;
    }
    $job = [
      'connection' => $connectionList->current,
      'command' => 'schemaList',
      'callback' => ['\MADB\Schema\MenuController', 'setSchemas']
    ];
    \MADB\Schema\MenuController::loading();
    \MADB\Job\JobHandler::startJob($job);
  }

  public static function delete() {
    
  }

}

