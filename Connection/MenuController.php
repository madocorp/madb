<?php

namespace MADB\Connection;

use SPTK\Element;

class MenuController {

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

  public static function updateMenuLabels($connection = false) {
    $labels = self::getMenuLabels($connection);
    Element::byName('menu-schema')->setText($labels['schema']);
    Element::byName('menu-table')->setText($labels['table']);
  }

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

  public static function select($item) {
    if (is_string($item)) {
      $connection = $item;
    } else {
      $connection = $item->getValue();
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
          ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close'],
          ['text' => 'Delete', 'hotKey' => 'RETURN', 'onPress' => '\MADB\Connection\MenuController::doDelete']
        ]
      );
    }
  }

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
