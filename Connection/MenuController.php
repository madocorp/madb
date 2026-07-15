<?php

namespace MADB\Connection;

use SPTK\Element;

/** Maintains the connection menu and selected connection, including schema/table menu refresh and deletion flow. */
class MenuController {

  /** Returns menu labels data used by the connection menu. */
  public static function getMenuLabels($connection = false) {
    $labels = [
      'schema' => 'Schema',
      'table' => 'Table'
    ];
    if ($connection === false) {
      $connectionList = ConnectionList::getInstance();
      $connection = $connectionList->current;
    }
    if ($connection === false || empty($connection['type'])) {
      return $labels;
    }
    $className = "\MADB\Engine\\{$connection['type']}\Connection";
    if (class_exists($className) && method_exists($className, 'getMenuLabels')) {
      return array_merge($labels, $className::getMenuLabels());
    }
    return $labels;
  }

  /** Coordinates update menu labels work in the connection menu. */
  public static function updateMenuLabels($connection = false) {
    $labels = self::getMenuLabels($connection);
    self::setMenuBarItemText('menu-schema', 2, $labels['schema']);
    self::setMenuBarItemText('menu-table', 3, $labels['table']);
  }

  /** Applies menu bar item text values to connection menu state or controls. */
  private static function setMenuBarItemText($name, $hotKey, $text) {
    $menuItem = Element::byName($name);
    $menuItem->clear();
    $menuItem->setHotKey($hotKey);
    $menuItem->addText($text);
  }

  /** Coordinates update connection list work in the connection menu. */
  public static function updateConnectionList() {
    $connectionList = ConnectionList::getInstance();
    $nameList = $connectionList->getNameList();
    $separators = $connectionList->getSeparators();
    $menuBox = Element::byName('submenu-connection');
    $menuBox->clear();
    $menuBox->setOnSelect('\MADB\Connection\MenuController::select');
    $manageMenu = new \SPTK\Elements\MenuBoxItem($menuBox, 'menu-connection-manage', 'MenuSeparator');
    $manageMenu->addText('Manage');
    $manageMenu->setSubmenu('true');
    $currentName = false;
    if ($connectionList->current !== false) {
      $currentName = $connectionList->current['name'];
    }
    foreach ($nameList as $name) {
      $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
      $menuItem->setSelectable('connections');
      $menuItem->setValue($name);
      if ($name == $currentName) {
        $menuItem->setSelected('true');
      }
      if (in_array($name, $separators)) {
        $menuItem->addClass('MenuSeparator');
      }
    }
  }

  /** Selects select and refreshes related connection menu state. */
  public static function select($item) {
    $activateEditor = true;
    if (is_string($item)) {
      $connection = $item;
    } else {
      $connection = $item->getValue();
      $menuBox = $item->findAncestorByType('MenuBox');
      if ($menuBox !== false && $menuBox->isDisplayed()) {
        $activateEditor = false;
      }
    }
    $connectionList = ConnectionList::getInstance();
    if (is_string($connection)) {
      $connectionList->setCurrent($connection);
    } else {
      $connectionList->setCurrent($connection->getValue());
    }
    if ($connectionList->current === false) {
      return;
    }
    \MADB\Main\ScreenController::loadConnection($connectionList->current['name'], $activateEditor);
    self::updateMenuLabels($connectionList->current);
    $job = [
      'connection' => $connectionList->current,
      'command' => 'schemaList',
      'callback' => ['\MADB\Schema\MenuController', 'setSchemas'],
      'cache' => 'SchemaList'
    ];
    \MADB\Schema\MenuController::loading();
    \MADB\Job\JobHandler::startJob($job);
  }

  /** Removes delete from the connection menu. */
  public static function delete() {
    $connectionList = ConnectionList::getInstance();
    $connection = $connectionList->current;
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
    }
    $job = [
      'connection' => $connection,
      'command' => 'countProcesses',
      'callback' => ['\MADB\Connection\MenuController', 'confirmDelete']
    ];
    \MADB\Job\JobHandler::startJob($job);
  }

  /** Opens or handles the delete confirmation step in the connection menu. */
  public static function confirmDelete($response) {
    $connectionList = ConnectionList::getInstance();
    $connection = $connectionList->current;
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
    } else {
      $processCount = '?';
      if ($response['status'] === 'OK') {
        $processCount = $response['result'];
      }
      $content = "The following actions will be performed.\n";
      $content .= "- Connection data will be destroyed\n";
      $content .= "- " . \MADB\Job\Cache::count($connection['name']) . " cached query results will be cleared\n";
      $content .= "- {$processCount} processes will be killed\n";
      $content .= "- " . \MADB\Job\JobHandler::countJobs($connection['name']) . " jobs will be interrupted\n";
      $content .= "- n saved queries with their results will be deleted\n";
      $content .= "%CONFIRMATION%";
      \SPTK\Elements\WarningPanel::forge(
        'Delete connection',
        $content,
        [
          ['text' => 'Delete', 'hotKey' => 'RETURN', 'onPress' => '\MADB\Connection\MenuController::doDelete'],
          ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close']
        ]
      );
    }
  }

  /** Coordinates do delete work in the connection menu. */
  public static function doDelete($confirmationPanel) {
    $values = $confirmationPanel->getValue();
    if (!isset($values['confirmed']) || $values['confirmed'] !== true) {
      return;
    }
    $connectionList = ConnectionList::getInstance();
    \MADB\Job\JobHandler::startJob([
      'connection' => $connectionList->current,
      'command' => 'killConnection'
    ]);
    \MADB\Job\Cache::clearConnection($connectionList->current['name']);
    $connectionList->delete();
    $connectionList->save();
    MenuController::updateConnectionList();
    MenuController::updateMenuLabels();
    \MADB\Schema\MenuController::reset();
    $confirmationPanel->remove();
    Element::refresh();
  }

  /** Clears current cache state from the connection menu. */
  public static function clearCurrentCache() {
    $connectionList = ConnectionList::getInstance();
    $connection = $connectionList->current;
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
    } else {
      \MADB\Job\Cache::clearConnection($connection['name']);
      \MADB\Connection\MenuController::select($connection['name']);
      \SPTK\Elements\Panel::forge('Cache cleared', "Cached data for connection '{$connection['name']}' has been sucessfully cleared.");
    }
  }

  /** Clears all cache state from the connection menu. */
  public static function clearAllCache() {
    \MADB\Job\Cache::clearAll();
    $connectionList = ConnectionList::getInstance();
    $connection = $connectionList->current;
    if ($connection !== false) {
      \MADB\Job\Cache::clearConnection($connection['name']);
      \MADB\Connection\MenuController::select($connection['name']);
      \SPTK\Elements\Panel::forge('Cache cleared', "All cached data has been successfully cleared.");
    }
  }

}
