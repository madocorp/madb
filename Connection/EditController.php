<?php

namespace MADB\Connection;

use SPTK\Element;

/** Handles the connection editor panel for creating, editing, saving, and testing connection definitions. */
class EditController {

  /** Routes legacy static callbacks into the connection menu. */
  public static function __callStatic($name, $arguments) {
    if (strpos($name, 'create') === 0) {
      $type = str_replace('create', '', $name);
      self::create($type);
    }
    if (strpos($name, 'save') === 0) {
      $type = str_replace('save', '', $name);
      self::save($type);
    }
    if (strpos($name, 'test') === 0) {
      $type = str_replace('test', '', $name);
      self::test($type);
    }
  }

  /** Creates create data for the connection menu. */
  public static function create($type) {
    $className = "\MADB\Engine\\{$type}\Connection";
    $defaults = $className::getDefaults();
    $panel = Element::byName('connection-editor-' . strtolower($type));
    $panel->setValue($defaults);
    $panel->show();
    Element::refresh();
  }

  /** Saves save values from the connection menu panel or state. */
  public static function save($type) {
    $panel = Element::byName('connection-editor-' . strtolower($type));
    $panel->hide();
    $connectionData = $panel->getValue();
    $connectionData['type'] = $type;
    $connections = ConnectionList::getInstance();
    $connections->add($connectionData);
    $connections->save();
    MenuController::updateConnectionList();
    Element::refresh();
  }

  /** Coordinates test work in the connection menu. */
  public static function test($type) {
    $panel = Element::byName('connection-editor-' . strtolower($type));
    $connectionData = $panel->getValue();
    $connectionData['type'] = $type;
    $connectionData['name'] = "TEST#{$connectionData['name']}";
    $job = [
      'connection' => $connectionData,
      'command' => 'test',
      'callback' => ['\MADB\Connection\EditController', 'testResult']
    ];
    \MADB\Job\JobHandler::startJob($job);
  }

  /** Coordinates test result work in the connection menu. */
  public static function testResult($result) {
    $hostInfo = "{$result['connection']['host']}:{$result['connection']['port']} ({$result['connection']['type']})";
    if ($result['status'] === 'OK') {
      \SPTK\Elements\Panel::forge("Test passed", "Host: {$hostInfo}\n{$result['result']}");
    } else {
      \SPTK\Elements\ErrorPanel::forge("Test failed", "Host: {$hostInfo}\n{$result['result']}");
    }
  }

  /** Coordinates edit work in the connection menu. */
  public static function edit() {
    $connectionList = ConnectionList::getInstance();
    $connection = $connectionList->current;
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
    } else {
      $type = $connection['type'] ?? 'unknown';
      $panelName = 'connection-editor-' . strtolower($type);
      $panel = Element::byName($panelName);
      if ($panel === false) {
        throw new \Exception("Panel not found: {$panelName}");
      }
      $panel->setValue($connection);
      $panel->show();
      Element::refresh();
    }
  }

  /** Closes the close panel in the connection menu. */
  public static function close($panel) {
    $panel->hide();
    Element::refresh();
  }

}
