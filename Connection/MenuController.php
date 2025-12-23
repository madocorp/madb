<?php

namespace MADB\Connection;

use SPTK\Element;

class MenuController {

  public static function updateConnectionList() {
    $connectionList = ConnectionList::getInstance();
    $nameList = $connectionList->getNameList();
    $separators = $connectionList->getSeparators();
    $menuBox = Element::byName('submenu-connection');
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
      if (in_array($name, $separators)) {
        $menuItem->addClass('MenuSeparator');
      }
    }
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
    $connectionList = ConnectionList::getInstance();
    $connection = $connectionList->current;
    if ($connection === false) {
      \SPTK\WarningPanel::forge('Menu', 'No connection selected!', 'Please select a connection from the menu before preforming this operation.');
    } else {
      $content = "The following actions will be performed.\n";
      $content .= "- Connection data will be destroyed\n";
      $content .= "- " . \MADB\Job\Cache::count($connection['name']) . " cached query results will be cleared\n";
      $content .= "- " . \MADB\Job\JobHandler::countProcesses($connection['name']) . " processes will be killed\n";
      $content .= "- " . \MADB\Job\JobHandler::countJobs($connection['name']) . " jobs will be interrupted\n";
      $content .= "- n saved queries with their results will be deleted\n";
      $content .= "%CONFIRMATION%";
      \SPTK\WarningPanel::forge(
        'Menu',
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
    \MADB\Schema\MenuController::reset();
    $confirmationPanel->remove();
    Element::refresh();
  }

}

