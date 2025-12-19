<?php

namespace MADB\Connection;

use SPTK\Element;

class EditController {

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

  public static function create($type) {
    $className = "\MADB\Connection\Connection{$type}";
    $defaults = $className::getDefaults();
    $panel = Element::byName('connection-editor-' . strtolower($type));
    $panel->setValue($defaults);
    $panel->show();
    Element::refresh();
  }

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
    JobDirector::startJob($job);
  }

  public static function testResult($result) {
    if ($result['status'] === 'OK') {
      $panel = Element::byName('connection-test-passed');
    } else {
      $panel = Element::byName('connection-test-failed');
    }
    $hostInfo = "{$result['connection']['host']}:{$result['connection']['port']} ({$result['connection']['type']})";
    $panel->setText("Host: {$hostInfo}\n{$result['result']}");
    $panel->show();
    Element::refresh();
  }

  public static function edit() {
    $connectionList = ConnectionList::getInstance();
    $connection = $connectionList->current;
    if ($connection === false) {
      $panel = Element::byName('please-select-connection');
    } else {
      $type = $connection['type'] ?? 'unknown';
      $panelName = 'connection-editor-' . strtolower($type);
      $panel = Element::byName($panelName);
      if ($panel === false) {
        throw new \Exception("Panel not found: {$panelName}");
      }
      $panel->setValue($connection);
    }
    $panel->show();
    Element::refresh();
  }

  public static function close($panel) {
    $panel->hide();
    Element::refresh();
  }



}
