<?php

namespace MADB\Connection;

use SPTK\Element;

/** Handles the connection editor panel for creating, editing, saving, and testing connection definitions. */
class EditController {

  private static $editingConnectionName = false;

  /** Routes legacy static callbacks into the connection menu. */
  public static function __callStatic($name, $arguments) {
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
  public static function create() {
    $type = \MADB\Engine\EngineRegistry::active();
    self::$editingConnectionName = false;
    $className = \MADB\Engine\EngineRegistry::connectionClass($type);
    $defaults = $className::getDefaults();
    $panel = Element::byName(\MADB\Engine\EngineRegistry::connectionPanel($type));
    $panel->setValue($defaults);
    $panel->show();
    $panel->activateInput('name');
    Element::refresh();
  }

  /** Saves save values from the connection menu panel or state. */
  public static function save($type) {
    $panel = Element::byName(\MADB\Engine\EngineRegistry::connectionPanel($type));
    $connectionData = $panel->getValue();
    $connectionData['engine'] = $type;
    if (\MADB\App\Settings::masterPasswordConfigured() && !\MADB\App\Settings::isUnlocked()) {
      \SPTK\Elements\WarningPanel::forge('Master password required', 'Unlock the master password before saving connections.');
      Element::refresh();
      return;
    }
    $originalConnectionName = self::$editingConnectionName;
    $connections = ConnectionList::getInstance();
    if ($connections->exists($connectionData['name'], $originalConnectionName)) {
      \SPTK\Elements\WarningPanel::forge(
        'Connection already exists',
        "Connection '{$connectionData['name']}' already exists. Choose a different name before saving."
      );
      Element::refresh();
      return;
    }
    $panel->hide();
    if (!$connections->add($connectionData, $originalConnectionName)) {
      $panel->show();
      $panel->activateInput('name');
      \SPTK\Elements\WarningPanel::forge(
        'Connection already exists',
        "Connection '{$connectionData['name']}' already exists. Choose a different name before saving."
      );
      Element::refresh();
      return;
    }
    self::$editingConnectionName = false;
    if ($originalConnectionName !== false && $originalConnectionName !== $connectionData['name']) {
      $connections->setCurrent($connectionData['name']);
    }
    $connections->save();
    MenuController::updateConnectionList();
    self::closeIdleConnections($connectionData, $originalConnectionName);
    Element::refresh();
  }

  /** Requests cleanup of idle workers that still hold the saved connection. */
  private static function closeIdleConnections($connectionData, $originalConnectionName = false): void {
    $connectionData['_cleanupNames'] = self::getCleanupConnectionNames($connectionData['name'] ?? false, $originalConnectionName);
    \MADB\Job\JobHandler::startJob([
      'connection' => $connectionData,
      'command' => 'getStatus',
      'callback' => ['\MADB\Connection\EditController', 'closeIdleConnectionsResult']
    ]);
  }

  /** Returns the connection names that may still have open workers after save. */
  private static function getCleanupConnectionNames($connectionName, $originalConnectionName): array {
    $connectionNames = [];
    foreach ([$connectionName, $originalConnectionName] as $name) {
      if ($name !== false && $name !== '' && !in_array($name, $connectionNames, true)) {
        $connectionNames[] = $name;
      }
    }
    return $connectionNames;
  }

  /** Closes idle workers for a saved connection and warns about busy workers. */
  public static function closeIdleConnectionsResult($response): void {
    if (($response['status'] ?? 'ERROR') !== 'OK') {
      return;
    }
    $connectionName = $response['connection']['name'] ?? false;
    $cleanupNames = $response['connection']['_cleanupNames'] ?? self::getCleanupConnectionNames($connectionName, false);
    if (empty($cleanupNames)) {
      return;
    }
    $running = [];
    $cleanupNameMap = array_flip($cleanupNames);
    foreach (($response['result'] ?? []) as $pid => $processInfo) {
      $processConnectionName = $processInfo['connectionName'] ?? false;
      if ($processConnectionName === false || !isset($cleanupNameMap[$processConnectionName])) {
        continue;
      }
      if (($processInfo['idle'] ?? false) === false) {
        $running[$pid] = $processConnectionName;
        continue;
      }
      \MADB\Job\JobHandler::startJob([
        'command' => 'killProcess',
        'pid' => $pid
      ]);
    }
    if (!empty($running)) {
      $runningConnectionNames = array_values(array_unique(array_values($running)));
      $runningConnectionList = "'" . implode("', '", $runningConnectionNames) . "'";
      \SPTK\Elements\WarningPanel::forge(
        'Connection still in use',
        "Connection '{$connectionName}' was saved, but " . count($running) . " running job(s) are still using open connection instance(s) for {$runningConnectionList}.\n" .
        "Those connection instance(s) will stay alive until killed from Connection status.\n" .
        "Running PID(s): " . self::formatRunningProcesses($running)
      );
    }
  }

  /** Formats running worker IDs with the connection name they still use. */
  private static function formatRunningProcesses($running): string {
    $items = [];
    foreach ($running as $pid => $connectionName) {
      $items[] = "{$pid} ({$connectionName})";
    }
    return implode(', ', $items);
  }

  /** Coordinates test work in the connection menu. */
  public static function test($type) {
    $panel = Element::byName(\MADB\Engine\EngineRegistry::connectionPanel($type));
    $connectionData = $panel->getValue();
    $connectionData['engine'] = $type;
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
    $hostInfo = self::formatConnectionTarget($result['connection'] ?? []);
    if ($result['status'] === 'OK') {
      \SPTK\Elements\Panel::forge("Test passed", "Target: {$hostInfo}\n" . self::formatTestResult($result), false, false, 'w55');
    } else {
      \SPTK\Elements\ErrorPanel::forge("Test failed", "Target: {$hostInfo}\n{$result['result']}", false, false, false);
    }
  }

  /** Formats connection target details for test result panels. */
  private static function formatConnectionTarget(array $connection): string {
    if (($connection['engine'] ?? '') === 'SQLite') {
      return ($connection['path'] ?? '-') . ' (SQLite)';
    }
    if (($connection['engine'] ?? '') === 'MongoDB') {
      return ($connection['host'] ?? '-') . ':' . ($connection['port'] ?? '-') . '/' . ($connection['database'] ?? '') . ' (MongoDB)';
    }
    return ($connection['host'] ?? '-') . ':' . ($connection['port'] ?? '-') . ' (' . ($connection['engine'] ?? '-') . ')';
  }

  /** Formats successful test output with server version metadata when available. */
  private static function formatTestResult($result): string {
    $testResult = $result['result'] ?? '';
    if (is_string($testResult)) {
      return $testResult;
    }
    $lines = [
      $testResult['message'] ?? 'The connection to the server was successful.'
    ];
    $serverInfo = $testResult['serverInfo'] ?? ($result['serverInfo'] ?? false);
    if (is_array($serverInfo) && !empty($serverInfo['version'])) {
      $label = $serverInfo['vendorLabel'] ?? 'MySQL-compatible';
      $lines[] = "Server: {$label} {$serverInfo['version']}";
      if (!empty($serverInfo['versionComment'])) {
        $lines[] = "Comment: {$serverInfo['versionComment']}";
      }
    }
    return implode("\n", $lines);
  }

  /** Coordinates edit work in the connection menu. */
  public static function edit() {
    $connectionList = ConnectionList::getInstance();
    $connection = $connectionList->current;
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
    } else {
      $type = $connection['engine'] ?? 'unknown';
      $panelName = \MADB\Engine\EngineRegistry::connectionPanel($type);
      $panel = Element::byName($panelName);
      if ($panel === false) {
        throw new \Exception("Panel not found: {$panelName}");
      }
      if ($connectionList->secretsLocked($connection)) {
        \SPTK\Elements\WarningPanel::forge('Master password required', 'Unlock the master password before editing this connection.');
        Element::refresh();
        return;
      }
      self::$editingConnectionName = $connection['name'];
      $panel->setValue($connection);
      $panel->show();
      $panel->activateInput('name');
      Element::refresh();
    }
  }

  /** Closes the close panel in the connection menu. */
  public static function close($panel) {
    $panel->hide();
    Element::refresh();
  }

}
