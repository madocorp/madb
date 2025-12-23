<?php

namespace MADB\Connection;

use SPTK\Element;

class SortController {

  const SEPARATOR_STRING = '-------';

  private static $initialized = false;
  private static $separatorId = 0;

  public static function sort() {
    $connectionList = ConnectionList::getInstance();
    if ($connectionList->getCount() < 2) {
      \SPTK\WarningPanel::forge('Menu', 'Not enough connection to sort!', 'You must have at least two connections to sort.');
    } else {
      $panel = Element::byName('connection-sort');
      if (!self::$initialized) {
        $panel->addHotKey(\SPTK\KeyCode::INSERT, '\MADB\Connection\SortController::toggleSeparator');
      }
      $listElement = Element::firstByType('ListBox');
      $list = $connectionList->getNameAndTypeList();
      $separators = $connectionList->getSeparators();
      self::$separatorId = 0;
      $listElement->clear();
      $first = true;
      foreach ($list as $itemName => $itemType) {
        $item = new \SPTK\ListItem($listElement);
        $item->addText($itemName);
        $type = new \SPTK\Element($item, false, false, 'ConnectionType');
        $type->addText("[{$itemType}]");
        $item->setValue($itemName);
        if ($first === true) {
          $first = $item;
        }
        if (in_array($itemName, $separators)) {
          $item = new \SPTK\ListItem($listElement);
          $item->addText(self::SEPARATOR_STRING);
          $item->setValue(self::SEPARATOR_STRING . self::$separatorId);
          self::$separatorId++;
        }
      }
      $panel->recalculateGeometry();
      $listElement->setSelected($first);
      $panel->show();
      Element::refresh();
    }
  }

  public static function close($panel) {
    $panel->hide();
    Element::refresh();
  }

  public static function save($panel) {
    $connectionList = ConnectionList::getInstance();
    $values = $panel->getValue();
    $connectionList->sort(array_keys($values['order']));
    $connectionList->save();
    MenuController::updateConnectionList();
    $panel->hide();
    Element::refresh();
  }

  public static function toggleSeparator() {
    $listElement = Element::firstByType('ListBox');
    $current = $listElement->getActive();
    if (strpos($current->getValue(), self::SEPARATOR_STRING) === 0) {
      $current->remove();
    } else {
      $item = new \SPTK\ListItem($listElement);
      $item->addText(self::SEPARATOR_STRING);
      $item->setValue(self::SEPARATOR_STRING . self::$separatorId);
      self::$separatorId++;
      $item->moveAfter($current);
      $listElement->setSelected($item);
    }
    $listElement->recalculateGeometry();
    Element::refresh();
  }

}
