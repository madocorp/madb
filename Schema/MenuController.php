<?php

namespace MADB\Schema;

class MenuController {

  public static function reset() {
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Select a connection!');
    \SPTK\Element::refresh();
  }

  public static function loading() {
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Loading...');
    \SPTK\Element::refresh();
  }

  public static function loadFailed() {
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Could not get the list.');
    \SPTK\Element::refresh();
  }

  public static function setSchemas($response) {
    if ($response['status'] !== 'OK') {
      self::loadFailed();
      return;
    }
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    $menuBox->clear();
    $operationMenu = new \SPTK\Elements\MenuBoxItem($menuBox, 'menu-schema-operations', 'MenuSeparator');
    $operationMenu->setValue('Operations');
    $operationMenu->setSubmenu('true');
    foreach ($response['result'] as $schema) {
      $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
      $menuItem->setValue($schema);
    }
    \SPTK\Element::refresh();
  }

}

