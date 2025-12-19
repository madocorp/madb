<?php

namespace MADB\Schema;

class MenuController {

  public static function loading() {
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    $menuBox->clear();
    $menuItem = new \SPTK\MenuBoxItem($menuBox);
    $menuItem->addText('Loading...');
    $menuBox->calculateGeometry();
    \SPTK\Element::refresh();
  }

  public static function loadFailed() {
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    $menuBox->clear();
    $menuItem = new \SPTK\MenuBoxItem($menuBox);
    $menuItem->addText('Could not get the list.');
    $menuBox->calculateGeometry();
    \SPTK\Element::refresh();
  }

  public static function setSchemas($response) {
    if ($response['status'] !== 'OK') {
      self::loadFailed();
      return;
    }
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    $menuBox->clear();
    $operationMenu = new \SPTK\MenuBoxItem($menuBox, 'menu-schema-operations', 'MenuSeparator');
    $operationMenu->addText('Operations');
    $operationMenu->setSubmenu('true');
    foreach ($response['result'] as $schema) {
      $menuItem = new \SPTK\MenuBoxItem($menuBox);
      $menuItem->addText($schema);
    }
    $menuBox->calculateGeometry();
    \SPTK\Element::refresh();
  }

}

