<?php

namespace MADB\Connection;

use SPTK\Element;

/** Handles the connection sort panel and separator toggle used by the connection menu. */
class SortController {

  const SEPARATOR_STRING = '-------';

  private static $initialized = false;
  private static $separatorId = 0;

  /** Coordinates sort work in the connection menu. */
  public static function sort() {
    $connectionList = ConnectionList::getInstance();
    if ($connectionList->getCount() < 2) {
      \SPTK\Elements\WarningPanel::forge('Not enough connection to sort!', 'You must have at least two connections to sort.');
    } else {
      $panel = Element::byName('connection-sort');
      if (!self::$initialized) {
        $panel->addHotKey(\SPTK\SDLWrapper\KeyCode::INSERT, '\MADB\Connection\SortController::toggleSeparator');
        self::$initialized = true;
      }
      $listElement = Element::firstByType('ListBox', $panel);
      $list = $connectionList->getNameAndTypeList();
      $separators = $connectionList->getSeparators();
      self::$separatorId = 0;
      $listElement->clear();
      foreach ($list as $itemName => $itemType) {
        $listElement->addItem([
          'value' => $itemName,
          'text' => "[{$itemType}] {$itemName}"
        ]);
        if (in_array($itemName, $separators)) {
          $listElement->addItem([
            'value' => self::SEPARATOR_STRING . self::$separatorId,
            'text' => self::SEPARATOR_STRING
          ]);
          self::$separatorId++;
        }
      }
      $panel->recalculateGeometry();
      $panel->show();
      $panel->activateInput('order');
      Element::refresh();
    }
  }

  /** Closes the close panel in the connection menu. */
  public static function close($panel) {
    $panel->hide();
    Element::refresh();
  }

  /** Saves save values from the connection menu panel or state. */
  public static function save($panel) {
    $connectionList = ConnectionList::getInstance();
    $values = $panel->getValue();
    $connectionList->sort($values['order']);
    $connectionList->save();
    MenuController::updateConnectionList();
    $panel->hide();
    Element::refresh();
  }

  /** Coordinates toggle separator work in the connection menu. */
  public static function toggleSeparator() {
    $panel = Element::byName('connection-sort');
    $listElement = $panel === false ? false : Element::byName('order', $panel);
    if ($listElement === false) {
      return;
    }
    $current = $listElement->getActive();
    if ($current === false) {
      return;
    }
    if (strpos($current->getValue(), self::SEPARATOR_STRING) === 0) {
      $current->remove();
    } else {
      $item = $listElement->addItem([
        'value' => self::SEPARATOR_STRING . self::$separatorId,
        'text' => self::SEPARATOR_STRING
      ]);
      self::$separatorId++;
      $item->moveAfter($current);
    }
    $listElement->recalculateGeometry();
    Element::refresh();
  }

}
