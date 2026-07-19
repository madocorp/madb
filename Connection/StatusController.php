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
    $panel->activateInput('status');
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
      $connectionName = $processInfo['connectionName'] ?: '-';
      $type = $connections[$connectionName] ?? '-';
      if ($processInfo['idle'] === false) {
        $status = "WORKING on job {$processInfo['jid']}";
      } else {
        $status = 'IDLE';
      }
      $time = sprintf("%.2fs", microtime(true) - $processInfo['since']);
      $listElement->addItem([
        'value' => $pid,
        'columns' => [$pid, $connectionName, $type, $status, $time]
      ]);
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
        $processBox->setValue(
          "Process ID: {$pid}\n" .
          "Connection name: {$processInfo['connectionName']}\n" .
          "Status: " . ($processInfo['idle'] ? 'IDLE' : 'WORKING') . "\n" .
          sprintf("In this status for %.2f seconds", microtime(true) - $processInfo['since']) . "\n" .
          "Job ID: " . ($processInfo['jid'] === false ? '-' : $processInfo['jid'])
        );
        $connectionList = ConnectionList::getInstance();
        $connectionInfo = $connectionList->get($processInfo['connectionName']);
        $connectionBox = Element::byName('connection', $panel);
        if ($connectionInfo === false) {
          $connectionBox->setValue('-');
        } else {
          $connectionBox->setValue(self::formatConnectionInfo($connectionInfo, $processInfo['serverInfo'] ?? false));
        }
        $jobInfo = \MADB\Job\JobHandler::getJob($processInfo['jid']);
        $jobBox = Element::byName('job', $panel);
        $jobBox->setValue(self::formatJobInfo($jobInfo));
        $panel->show();
        $panel->activateInput('connection-process-ok');
        Element::refresh();
        return;
      }
    }
    \MADB\Connection\StatusController::update($response);
  }

  /** Formats job status without exposing connection credentials. */
  private static function formatJobInfo($jobInfo): string {
    if ($jobInfo === false) {
      return '-';
    }
    $lines = [
      'Command: ' . ($jobInfo['command'] ?? '-'),
      'Arguments: ' . self::formatStatusValue($jobInfo['arguments'] ?? '-')
    ];
    foreach (['schema', 'table', 'queryId'] as $key) {
      if (isset($jobInfo[$key])) {
        $lines[] = ucfirst($key) . ': ' . self::formatStatusValue($jobInfo[$key]);
      }
    }
    return implode("\n", $lines);
  }

  /** Formats status details for TextBox values. */
  private static function formatStatusValue($value): string {
    if ($value === false || $value === null || $value === '') {
      return '-';
    }
    if (is_scalar($value)) {
      return (string) $value;
    }
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? '-' : $json;
  }

  /** Formats saved connection details without assuming network host fields. */
  private static function formatConnectionInfo(array $connectionInfo, $serverInfo): string {
    $lines = [
      'Name: ' . ($connectionInfo['name'] ?? '-'),
      'Type: ' . ($connectionInfo['type'] ?? '-')
    ];
    if (($connectionInfo['type'] ?? '') === 'SQLite') {
      $lines[] = 'Path: ' . ($connectionInfo['path'] ?? '-');
    } else {
      $lines[] = 'Host: ' . ($connectionInfo['host'] ?? '-');
      $lines[] = 'Port: ' . ($connectionInfo['port'] ?? '-');
    }
    $lines[] = self::formatServerInfo($serverInfo);
    return implode("\n", $lines);
  }

  /** Formats detected database server version and capability metadata. */
  private static function formatServerInfo($serverInfo): string {
    if (!is_array($serverInfo) || empty($serverInfo['version'])) {
      return 'Server: -';
    }
    $label = $serverInfo['vendorLabel'] ?? 'MySQL-compatible';
    $version = $serverInfo['version'] ?? '-';
    $lines = [
      "Server: {$label} {$version}"
    ];
    if (!empty($serverInfo['versionComment'])) {
      $lines[] = 'Comment: ' . $serverInfo['versionComment'];
    }
    $capabilities = self::formatServerCapabilities($serverInfo['capabilities'] ?? []);
    if ($capabilities !== '') {
      $lines[] = 'Capabilities: ' . $capabilities;
    }
    return implode("\n", $lines);
  }

  /** Formats compact server capability labels. */
  private static function formatServerCapabilities($capabilities): string {
    if (!is_array($capabilities) || empty($capabilities)) {
      return '';
    }
    $labels = [];
    if (!empty($capabilities['sqlite'])) {
      $labels[] = 'SQLite database file';
    }
    if (!empty($capabilities['nativeJson'])) {
      $labels[] = 'native JSON';
    } elseif (!empty($capabilities['mariaDbJsonAlias'])) {
      $labels[] = 'JSON alias';
    }
    if (!empty($capabilities['descendingIndexes'])) {
      $labels[] = 'descending indexes';
    }
    if (!empty($capabilities['invisibleIndexes'])) {
      $labels[] = 'invisible indexes';
    }
    if (!empty($capabilities['checkConstraints'])) {
      $labels[] = 'check constraints';
    }
    return implode(', ', $labels);
  }

}
