<?php

namespace MADB\Connection;

use SPTK\Element;

/** Maintains the connection menu and selected connection, including schema/table menu refresh and deletion flow. */
class MenuController {

  private static string|false $pendingPasswordConnectionName = false;
  private static bool $pendingPasswordActivateEditor = true;
  private static bool $deferPasswordPrompt = false;

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
    $menuBox->addItem([
      'name' => 'menu-connection-manage',
      'value' => 'Manage',
      'text' => 'Manage',
      'submenu' => true,
      'classes' => ['MenuSeparator']
    ]);
    $currentName = false;
    if ($connectionList->current !== false) {
      $currentName = $connectionList->current['name'];
    }
    foreach ($nameList as $name) {
      $item = [
        'value' => $name,
        'selectable' => 'connections'
      ];
      if ($name == $currentName) {
        $item['selected'] = true;
      }
      if (in_array($name, $separators)) {
        $item['classes'] = ['MenuSeparator'];
      }
      $menuBox->addItem($item);
    }
  }

  /** Selects select and refreshes related connection menu state. */
  public static function select($item) {
    $activateEditor = true;
    $sourceMenu = false;
    if (is_string($item)) {
      $connection = $item;
    } else {
      $connection = $item->getValue();
      $menuBox = $item->findAncestorByType('MenuBox');
      if ($menuBox !== false && $menuBox->isDisplayed()) {
        $activateEditor = false;
        $sourceMenu = $menuBox->findAncestorByType('Menu');
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
    if ($connectionList->secretsLocked($connectionList->current)) {
      if ($sourceMenu !== false) {
        $sourceMenu->closeMenu();
        self::deferConnectionPasswordPrompt($connectionList->current['name'], $activateEditor);
        return;
      }
      self::promptConnectionPassword($connectionList->current['name'], $activateEditor);
      return;
    }
    self::openCurrentConnection($activateEditor);
  }

  private static function deferConnectionPasswordPrompt(string $connectionName, bool $activateEditor): void {
    self::$pendingPasswordConnectionName = $connectionName;
    self::$pendingPasswordActivateEditor = $activateEditor;
    self::$deferPasswordPrompt = true;
  }

  public static function showPendingPasswordPrompt(): void {
    if (!self::$deferPasswordPrompt || self::$pendingPasswordConnectionName === false) {
      return;
    }
    self::$deferPasswordPrompt = false;
    self::promptConnectionPassword(self::$pendingPasswordConnectionName, self::$pendingPasswordActivateEditor);
  }

  private static function promptConnectionPassword(string $connectionName, bool $activateEditor): void {
    self::$pendingPasswordConnectionName = $connectionName;
    self::$pendingPasswordActivateEditor = $activateEditor;
    self::$deferPasswordPrompt = false;
    $panel = Element::byName('connection-password-prompt');
    if ($panel === false) {
      \SPTK\Elements\WarningPanel::forge('Database password required', "Enter the database password for '{$connectionName}'.");
      Element::refresh();
      return;
    }
    $connectionNameLabel = Element::byName('connection-password-name', $panel);
    if ($connectionNameLabel !== false) {
      $connectionNameLabel->setText("Connection: {$connectionName}");
    }
    $panel->setValue([
      'connection-password' => ''
    ]);
    $panel->show();
    $panel->raise();
    $panel->activateInput('connection-password');
    Element::refresh();
  }

  public static function connectWithPassword($panel): void {
    if (self::$pendingPasswordConnectionName === false) {
      $panel->hide();
      Element::refresh();
      return;
    }
    $values = $panel->getValue();
    $connectionList = ConnectionList::getInstance();
    if (!$connectionList->setSessionPassword(self::$pendingPasswordConnectionName, (string)($values['connection-password'] ?? ''))) {
      \SPTK\Elements\WarningPanel::forge('Connection not found', 'The selected connection no longer exists.');
      Element::refresh();
      return;
    }
    $activateEditor = self::$pendingPasswordActivateEditor;
    self::$pendingPasswordConnectionName = false;
    self::$pendingPasswordActivateEditor = true;
    $panel->hide();
    self::openCurrentConnection($activateEditor);
  }

  private static function openCurrentConnection(bool $activateEditor): void {
    $connectionList = ConnectionList::getInstance();
    if ($connectionList->current === false) {
      return;
    }
    \MADB\Main\ScreenController::loadConnection($connectionList->current['name'], $activateEditor);
    self::updateMenuLabels($connectionList->current);
    $job = [
      'connection' => $connectionList->current,
      'command' => 'schemaList',
      'callback' => ['\MADB\Connection\MenuController', 'schemaListResult'],
      'cache' => 'SchemaList'
    ];
    \MADB\Schema\MenuController::loading();
    \MADB\Job\JobHandler::startJob($job);
  }

  public static function schemaListResult($response): void {
    if (($response['status'] ?? 'ERROR') !== 'OK') {
      $connectionName = $response['connection']['name'] ?? false;
      if (is_string($connectionName)) {
        ConnectionList::getInstance()->clearSessionPassword($connectionName);
      }
    }
    \MADB\Schema\MenuController::setSchemas($response);
    Element::refresh();
  }

  /** Removes delete from the connection menu. */
  public static function delete() {
    $connectionList = ConnectionList::getInstance();
    $connection = $connectionList->current;
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
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
      $queryCount = \MADB\Query\QueryList::getInstance()->countForConnection($connection['name']);
      $jobCount = \MADB\Job\JobHandler::countInterruptibleJobs($connection['name']);
      $content = "The following actions will be performed.\n";
      $content .= "- Connection data will be destroyed\n";
      $content .= "- {$processCount} " . ($processCount === 1 ? 'process' : 'processes') . " will be killed\n";
      if ($jobCount > 0) {
        $content .= "- {$jobCount} " . ($jobCount === 1 ? 'job' : 'jobs') . " will be interrupted\n";
      }
      $content .= "- {$queryCount} saved " . ($queryCount === 1 ? 'query with its results' : 'queries with their results') . " will be deleted\n";
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
    $connectionName = $connectionList->current['name'];
    \MADB\Job\JobHandler::startJob([
      'connection' => $connectionList->current,
      'command' => 'killConnection'
    ]);
    \MADB\Job\Cache::clearConnection($connectionName);
    \MADB\Query\QueryList::getInstance()->deleteConnection($connectionName);
    $connectionList->delete();
    $connectionList->save();
    \MADB\Main\ScreenController::loadConnection(false);
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
