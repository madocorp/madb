<?php

namespace MADB\Table;

class MenuController {

  public static function reset() {
    $menuBox = \SPTK\Element::byName('menu-table-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Select a schema!');
    \SPTK\Element::refresh();
  }

  public static function loading() {
    $menuBox = \SPTK\Element::byName('menu-table-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Loading...');
    \SPTK\Element::refresh();
  }

  public static function loadFailed() {
    $menuBox = \SPTK\Element::byName('menu-table-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Could not get the list.');
    \SPTK\Element::refresh();
  }

  public static function setTables($response) {
    if ($response['status'] !== 'OK') {
      self::loadFailed();
      return;
    }
    $menuBox = \SPTK\Element::byName('menu-table-list');
    $menuBox->clear();
    $operationMenu = new \SPTK\Elements\MenuBoxItem($menuBox, 'menu-table-operations', 'MenuSeparator');
    $operationMenu->setValue('Operations');
    $operationMenu->setSubmenu('true');
    foreach ($response['result'] as $table) {
      $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
      $menuItem->setValue($table);
      $menuItem->setSelectable('tables');
    }
    \SPTK\Element::refresh();
  }

}
