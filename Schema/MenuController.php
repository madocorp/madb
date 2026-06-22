<?php

namespace MADB\Schema;

class MenuController {

  private static $selectAfterLoad = false;
  private static $currentSchema = false;
  private static $dropSchema = false;
  private static $renameSchema = false;
  private static $renameTargetSchema = false;

  private static function schemaLabel() {
    $labels = \MADB\Connection\MenuController::getMenuLabels();
    return strtolower($labels['schema']);
  }

  public static function reset() {
    self::$currentSchema = false;
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Select a connection!');
    \MADB\Table\MenuController::reset(false);
    \SPTK\Element::refresh();
  }

  public static function loading() {
    self::$currentSchema = false;
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Loading...');
    \MADB\Table\MenuController::reset(false);
    \SPTK\Element::refresh();
  }

  public static function loadFailed() {
    self::$currentSchema = false;
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Could not get the list.');
    \MADB\Table\MenuController::reset(false);
    \SPTK\Element::refresh();
  }

  public static function select($item) {
    if (is_string($item)) {
      $schema = $item;
    } else {
      $schema = $item->getValue();
    }
    self::$currentSchema = $schema;
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    if ($connectionList->current === false) {
      return;
    }
    $job = [
      'connection' => $connectionList->current,
      'command' => 'tableList',
      'arguments' => [$schema],
      'callback' => ['\MADB\Table\MenuController', 'setTables'],
      'schema' => $schema,
      'cache' => "TableList:{$schema}"
    ];
    \MADB\Table\MenuController::setCurrentSchema($schema);
    \MADB\Table\MenuController::loading();
    \MADB\Job\JobHandler::startJob($job);
  }

  public static function create() {
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    if ($connectionList->current === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    $panel = \SPTK\Element::byName('schema-create');
    $title = \SPTK\Element::firstByType('PanelTitle', $panel);
    if ($title !== false) {
      $title->setText('Create ' . self::schemaLabel());
    }
    $panel->setValue(['name' => '']);
    $panel->show();
    \SPTK\Element::refresh();
  }

  public static function closeCreate($panel) {
    $panel->hide();
    \SPTK\Element::refresh();
  }

  public static function saveCreate($panel) {
    $values = $panel->getValue();
    $schema = trim($values['name'] ?? '');
    if ($schema === '') {
      \SPTK\Elements\WarningPanel::forge('Missing name', 'Please enter a name before creating the ' . self::schemaLabel() . '.');
      return;
    }
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    if ($connectionList->current === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    $panel->hide();
    $job = [
      'connection' => $connectionList->current,
      'command' => 'createSchema',
      'arguments' => [$schema],
      'callback' => ['\MADB\Schema\MenuController', 'created'],
      'schema' => $schema
    ];
    \MADB\Job\JobHandler::startJob($job);
    \SPTK\Element::refresh();
  }

  public static function created($response) {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not create ' . self::schemaLabel(), $response['result']);
      return;
    }
    \MADB\Job\Cache::clear($response['connection']['name'], 'SchemaList');
    self::$selectAfterLoad = $response['schema'];
    \MADB\Connection\MenuController::select($response['connection']['name']);
  }

  public static function rename() {
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    if ($connectionList->current === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    if (self::$currentSchema === false) {
      \SPTK\Elements\WarningPanel::forge('No ' . self::schemaLabel() . ' selected!', 'Please select a ' . self::schemaLabel() . ' before preforming this operation.');
      return;
    }
    $panel = \SPTK\Element::byName('schema-rename');
    $title = \SPTK\Element::firstByType('PanelTitle', $panel);
    if ($title !== false) {
      $title->setText('Rename ' . self::schemaLabel());
    }
    $panel->setValue(['name' => self::$currentSchema]);
    $panel->show();
    \SPTK\Element::refresh();
  }

  public static function closeRename($panel) {
    $panel->hide();
    \SPTK\Element::refresh();
  }

  public static function saveRename($panel) {
    $values = $panel->getValue();
    $targetSchema = trim($values['name'] ?? '');
    if ($targetSchema === '') {
      \SPTK\Elements\WarningPanel::forge('Missing name', 'Please enter the new name before renaming the ' . self::schemaLabel() . '.');
      return;
    }
    if (self::$currentSchema === false) {
      \SPTK\Elements\WarningPanel::forge('No ' . self::schemaLabel() . ' selected!', 'Please select a ' . self::schemaLabel() . ' before preforming this operation.');
      return;
    }
    if ($targetSchema === self::$currentSchema) {
      $panel->hide();
      \SPTK\Element::refresh();
      return;
    }
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    if ($connectionList->current === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    $panel->hide();
    $job = [
      'connection' => $connectionList->current,
      'command' => 'renameSchemaInfo',
      'arguments' => [self::$currentSchema, $targetSchema],
      'callback' => ['\MADB\Schema\MenuController', 'confirmRename'],
      'schema' => self::$currentSchema,
      'targetSchema' => $targetSchema
    ];
    \MADB\Job\JobHandler::startJob($job);
    \SPTK\Element::refresh();
  }

  public static function confirmRename($response) {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not inspect ' . self::schemaLabel(), $response['result']);
      return;
    }
    $schema = $response['schema'];
    $targetSchema = $response['targetSchema'];
    $info = $response['result'];
    if (!empty($info['targetExists'])) {
      \SPTK\Elements\WarningPanel::forge('Target exists', "The target " . self::schemaLabel() . " '{$targetSchema}' already exists.");
      return;
    }
    self::$renameSchema = $schema;
    self::$renameTargetSchema = $targetSchema;
    $tables = $info['tables'] ?? 0;
    $views = $info['views'] ?? 0;
    $bytes = $info['bytes'] ?? 0;
    $foreignKeys = $info['foreignKeys'] ?? 0;
    $routines = $info['routines'] ?? 0;
    $events = $info['events'] ?? 0;
    $content = "MySQL does not have a native schema rename operation.\n";
    $content .= "The following migration-style actions will be performed.\n";
    $content .= "- New " . self::schemaLabel() . " '{$targetSchema}' will be created using the source charset and collation\n";
    $content .= "- {$tables} tables will be moved from '{$schema}' to '{$targetSchema}' with RENAME TABLE\n";
    $content .= "- {$views} views will be copied into '{$targetSchema}' and dropped later with '{$schema}'\n";
    $content .= "- Table triggers will be dropped before the move and recreated on the new " . self::schemaLabel() . "\n";
    $content .= "- {$foreignKeys} foreign keys should move with their tables\n";
    $content .= "- " . self::formatSize($bytes) . " table data and indexes will be moved\n";
    $content .= "- {$routines} procedures/functions will be recreated in the new " . self::schemaLabel() . "\n";
    $content .= "- The old " . self::schemaLabel() . " '{$schema}' will be dropped after the move\n";
    $content .= "- {$events} events are not moved separately and may be dropped with the old " . self::schemaLabel() . "\n";
    $content .= "- If any step fails part way through, MaDB will not roll this back automatically; you will need to repair the schemas manually\n";
    $content .= "- Cached schema and table lists for this connection will be cleared\n";
    $content .= "%CONFIRMATION%";
    \SPTK\Elements\WarningPanel::forge(
      'Rename ' . self::schemaLabel(),
      $content,
      [
        ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close'],
        ['text' => 'Rename', 'hotKey' => 'RETURN', 'onPress' => '\MADB\Schema\MenuController::doRename']
      ]
    );
  }

  public static function doRename($confirmationPanel) {
    $values = $confirmationPanel->getValue();
    if (!isset($values['confirmed']) || $values['confirmed'] !== true) {
      return;
    }
    if (self::$renameSchema === false || self::$renameTargetSchema === false) {
      return;
    }
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    if ($connectionList->current === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    $schema = self::$renameSchema;
    $targetSchema = self::$renameTargetSchema;
    $confirmationPanel->remove();
    \MADB\Job\JobHandler::startJob([
      'connection' => $connectionList->current,
      'command' => 'renameSchema',
      'arguments' => [$schema, $targetSchema],
      'callback' => ['\MADB\Schema\MenuController', 'renamed'],
      'schema' => $schema,
      'targetSchema' => $targetSchema
    ]);
    \SPTK\Element::refresh();
  }

  public static function renamed($response) {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not rename ' . self::schemaLabel(), $response['result'] . "\nManual cleanup may be required.");
      return;
    }
    \MADB\Job\Cache::clear($response['connection']['name'], 'SchemaList');
    \MADB\Job\Cache::clear($response['connection']['name'], 'TableList:' . $response['schema']);
    \MADB\Job\Cache::clear($response['connection']['name'], 'TableList:' . $response['targetSchema']);
    self::$currentSchema = false;
    self::$renameSchema = false;
    self::$renameTargetSchema = false;
    self::$selectAfterLoad = $response['targetSchema'];
    \MADB\Connection\MenuController::select($response['connection']['name']);
  }

  private static function formatSize($bytes) {
    return sprintf('%.3f GB', $bytes / 1024 / 1024 / 1024);
  }

  public static function drop() {
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    if ($connectionList->current === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    if (self::$currentSchema === false) {
      \SPTK\Elements\WarningPanel::forge('No ' . self::schemaLabel() . ' selected!', 'Please select a ' . self::schemaLabel() . ' before preforming this operation.');
      return;
    }
    $job = [
      'connection' => $connectionList->current,
      'command' => 'schemaInfo',
      'arguments' => [self::$currentSchema],
      'callback' => ['\MADB\Schema\MenuController', 'confirmDrop'],
      'schema' => self::$currentSchema
    ];
    \MADB\Job\JobHandler::startJob($job);
  }

  public static function confirmDrop($response) {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not inspect ' . self::schemaLabel(), $response['result']);
      return;
    }
    $schema = $response['schema'];
    self::$dropSchema = $schema;
    $info = $response['result'];
    $tables = $info['tables'] ?? 0;
    $views = $info['views'] ?? 0;
    $bytes = $info['bytes'] ?? 0;
    $content = "The following actions will be performed.\n";
    $content .= "- {$schema} " . self::schemaLabel() . " will be dropped\n";
    $content .= "- {$tables} tables will be deleted\n";
    $content .= "- {$views} views will be deleted\n";
    $content .= "- " . self::formatSize($bytes) . " table data and indexes will be deleted\n";
    $content .= "- Cached schema and table lists for this connection will be cleared\n";
    $content .= "%CONFIRMATION%";
    \SPTK\Elements\WarningPanel::forge(
      'Drop ' . self::schemaLabel(),
      $content,
      [
        ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close'],
        ['text' => 'Drop', 'hotKey' => 'RETURN', 'onPress' => '\MADB\Schema\MenuController::doDrop']
      ]
    );
  }

  public static function doDrop($confirmationPanel) {
    $values = $confirmationPanel->getValue();
    if (!isset($values['confirmed']) || $values['confirmed'] !== true) {
      return;
    }
    if (self::$dropSchema === false) {
      return;
    }
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    if ($connectionList->current === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    $schema = self::$dropSchema;
    $confirmationPanel->remove();
    \MADB\Job\JobHandler::startJob([
      'connection' => $connectionList->current,
      'command' => 'dropSchema',
      'arguments' => [$schema],
      'callback' => ['\MADB\Schema\MenuController', 'dropped'],
      'schema' => $schema
    ]);
    \SPTK\Element::refresh();
  }

  public static function dropped($response) {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not drop ' . self::schemaLabel(), $response['result']);
      return;
    }
    \MADB\Job\Cache::clear($response['connection']['name'], 'SchemaList');
    \MADB\Job\Cache::clear($response['connection']['name'], 'TableList:' . $response['schema']);
    self::$currentSchema = false;
    self::$dropSchema = false;
    \MADB\Connection\MenuController::select($response['connection']['name']);
  }

  public static function setSchemas($response) {
    if ($response['status'] !== 'OK') {
      self::loadFailed();
      return;
    }
    $restoredSchema = \MADB\Table\MenuController::getCurrentSchema();
    $restoreTables = false;
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    $menuBox->clear();
    $menuBox->setOnSelect('\MADB\Schema\MenuController::select');
    $operationMenu = new \SPTK\Elements\MenuBoxItem($menuBox, 'menu-schema-operations', 'MenuSeparator');
    $operationMenu->setValue('Operations');
    $operationMenu->setSubmenu('true');
    foreach ($response['result'] as $index => $schema) {
      $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
      $menuItem->setValue($schema);
      $menuItem->setFilterable('true');
      $menuItem->setSelectable('schemas');
      if ($schema === self::$selectAfterLoad || $schema === \MADB\Table\MenuController::getCurrentSchema()) {
        $menuItem->setSelected('true');
        $menuBox->moveCursor($index + 1);
        if ($schema === $restoredSchema) {
          self::$currentSchema = $schema;
          $restoreTables = true;
        }
      }
    }
    \MADB\Table\MenuController::reset(false);
    \SPTK\Element::refresh();
    if (self::$selectAfterLoad !== false) {
      $schema = self::$selectAfterLoad;
      self::$selectAfterLoad = false;
      self::select($schema);
    } else if ($restoreTables) {
      $connectionList = \MADB\Connection\ConnectionList::getInstance();
      if ($connectionList->current !== false) {
        \MADB\Table\MenuController::loading(false);
        \MADB\Job\JobHandler::startJob([
          'connection' => $connectionList->current,
          'command' => 'tableList',
          'arguments' => [$restoredSchema],
          'callback' => ['\MADB\Table\MenuController', 'setTables'],
          'schema' => $restoredSchema,
          'cache' => "TableList:{$restoredSchema}"
        ]);
      }
    }
  }

}
