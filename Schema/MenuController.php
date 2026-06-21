<?php

namespace MADB\Schema;

class MenuController {

  public static function reset() {
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Select a connection!');
    \MADB\Table\MenuController::reset();
    \SPTK\Element::refresh();
  }

  public static function loading() {
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Loading...');
    \MADB\Table\MenuController::reset();
    \SPTK\Element::refresh();
  }

  public static function loadFailed() {
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Could not get the list.');
    \MADB\Table\MenuController::reset();
    \SPTK\Element::refresh();
  }

  public static function select($item) {
    if (is_string($item)) {
      $schema = $item;
    } else {
      $schema = $item->getValue();
    }
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    if ($connectionList->current === false) {
      return;
    }
    $job = [
      'connection' => $connectionList->current,
      'command' => 'tableList',
      'arguments' => [$schema],
      'callback' => ['\MADB\Table\MenuController', 'setTables'],
      'cache' => "TableList:{$schema}"
    ];
    \MADB\Table\MenuController::loading();
    \MADB\Job\JobHandler::startJob($job);
  }

  public static function setSchemas($response) {
    if ($response['status'] !== 'OK') {
      self::loadFailed();
      return;
    }
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    $menuBox->clear();
    $menuBox->setOnSelect('\MADB\Schema\MenuController::select');
    $operationMenu = new \SPTK\Elements\MenuBoxItem($menuBox, 'menu-schema-operations', 'MenuSeparator');
    $operationMenu->setValue('Operations');
    $operationMenu->setSubmenu('true');
    foreach ($response['result'] as $schema) {
      $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
      $menuItem->setValue($schema);
      $menuItem->setSelectable('schemas');
    }
    \MADB\Table\MenuController::reset();
    \SPTK\Element::refresh();
  }

}
