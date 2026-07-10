<?php

namespace MADB\Connection;

use SPTK\Element;

/** Handles the connection status panel for listing, inspecting, and killing backend jobs or processes. */
class StatusController {

  /** Opens the connection status panel for the active connection. */
  public static function show() {
    $panel = Element::byName('connection-status');
    $listElement = Element::firstByType('ListBox', $panel);
    $listElement->clear();
    $panel->show();
    Element::refresh();
    \MADB\Connection\StatusController::refresh();
  }

  /** Refreshes the connection status panel job/process list. */
  public static function refresh() {
    $job = [
      'command' => 'getStatus',
      'callback' => ['\MADB\Connection\StatusController', 'update']
    ];
    \MADB\Job\JobHandler::startJob($job);
  }

  /** Renders connection status rows returned by the background job system. */
  public static function update($response) {
    if ($response['status'] !== 'OK') {
      return;
    }
    $connectionList = ConnectionList::getInstance();
    $connections = $connectionList->getNameAndTypeList();
    $panel = Element::byName('connection-status');
    $listElement = Element::firstByType('ListBox', $panel);
    $listElement->clear();
    foreach ($response['result'] as $pid => $processInfo) {
      $connectionName = $processInfo['connectionName'];
      $type = $connections[$connectionName];
      if ($processInfo['idle'] === false) {
        $status = "WORKING on job {$projectInfo['jid']}";
      } else {
        $status = 'IDLE';
      }
      $time = sprintf("%.2fs", microtime(true) - $processInfo['since']);
      $item = new \SPTK\Elements\ListItem($listElement);
      $item->setValue($pid);
      $w = new \SPTK\Element($item, null, 'w10', 'Cell');
      $w->addText($pid);
      $w = new \SPTK\Element($item, null, 'w40', 'Cell');
      $w->addText($connectionName);
      $w = new \SPTK\Element($item, null, 'w20', 'Cell');
      $w->addText($type);
      $w = new \SPTK\Element($item, null, 'w20', 'Cell');
      $w->addText($status);
      $w = new \SPTK\Element($item, null, 'w10', 'Cell');
      $w->addText($time);
    }
    $listElement->moveCursor(0);
    Element::refresh();
  }

  /** Closes the close panel in the connection menu. */
  public static function close($panel) {
    $panel->hide();
    Element::refresh();
  }

  /** Requests termination of the selected connection process from the status panel. */
  public static function kill($panel) {
    $values = $panel->getValue();
    $pid = $values['status'];
    \MADB\Job\JobHandler::startJob([
      'command' => 'killProcess',
      'pid' => $pid,
      'callback' => ['\MADB\Connection\StatusController', 'refresh']
    ]);
  }

  /** Requests detailed status for the selected connection process. */
  public static function info($panel) {
    $job = [
      'command' => 'getStatus',
      'callback' => ['\MADB\Connection\StatusController', 'infoUpdate']
    ];
    \MADB\Job\JobHandler::startJob($job);
  }

  /** Displays detailed process information in the connection status panel. */
  public static function infoUpdate($response) {
    $panel = Element::byName('connection-status');
    $values = $panel->getValue();
    $targetPid = $values['status'];
    foreach ($response['result'] as $pid => $processInfo) {
      if ($pid == $targetPid) {
        $panel = Element::byName('connection-process-info');
        $processBox = Element::byName('process', $panel);
        $processBox->clear();
        $processBox->addText(
          "Process ID: {$pid}\n" .
          "Connection name: {$processInfo['connectionName']}\n" .
          "Status: " . ($processInfo['idle'] ? 'IDLE' : 'WORKING') . "\n" .
          sprintf("In this status for %.2f seconds", microtime(true) - $processInfo['since']) . "\n" .
          "Job ID: " . ($processInfo['jid'] === false ? '-' : $processInfo['jid'])
        );
        $connectionList = ConnectionList::getInstance();
        $connectionInfo = $connectionList->get($processInfo['connectionName']);
        $connectionBox = Element::byName('connection', $panel);
        $connectionBox->clear();
        $connectionBox->addText(
          "Name: {$connectionInfo['name']}\n" .
          "Type: {$connectionInfo['type']}\n" .
          "Host: {$connectionInfo['host']}\n" .
          "Port: {$connectionInfo['port']}"
        );
        $jobInfo = \MADB\Job\JobHandler::getJob($processInfo['jid']);
        $jobBox = Element::byName('job', $panel);
        $jobBox->clear();
        $jobBox->addText($jobInfo['arguments'] ?? '-');
        $panel->show();
        Element::refresh();
        return;
      }
    }
    \MADB\Connection\StatusController::update($response);
  }

}
