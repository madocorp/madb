<?php

namespace MADB\Schema;

/** Handles schema drop inspection, confirmation, execution, and schema menu refresh. */
trait MenuDropTrait {

  /** Coordinates drop work in the schema menu. */
  public static function drop() {
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    if ($connectionList->current === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    if (!\MADB\Connection\MenuController::requireOperation('schemaDrop', 'Dropping ' . self::schemaLabel(), $connectionList->current)) {
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

  /** Opens or handles the drop confirmation step in the schema menu. */
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
    $sql = self::isSQLiteConnection($response['connection'])
      ? self::sqliteDropSchemaPreviewSql($schema)
      : 'DROP SCHEMA ' . self::quoteIdentifier($schema) . ';';
    $directCommand = self::isSQLiteConnection($response['connection']) ? 'dropSchema' : false;
    $directArguments = self::isSQLiteConnection($response['connection']) ? [$schema] : [];
    \MADB\Query\GeneratedQueryController::open([
      'title' => 'Drop ' . self::schemaLabel(),
      'name' => 'DROP ' . $schema,
      'sql' => $sql,
      'connection' => $response['connection'],
      'schema' => $schema,
      'cacheKeys' => ['SchemaList', 'TableList:' . $schema],
      'refresh' => 'schemas',
      'directCommand' => $directCommand,
      'directArguments' => $directArguments,
      'confirmation' => [
        'title' => 'Drop ' . self::schemaLabel(),
        'content' => $content
      ]
    ]);
  }

  /** Coordinates do drop work in the schema menu. */
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
    if (!\MADB\Connection\MenuController::requireOperation('schemaDrop', 'Dropping ' . self::schemaLabel(), $connectionList->current)) {
      return;
    }
    $schema = self::$dropSchema;
    $confirmationPanel->remove();
    $sql = self::isSQLiteConnection($connectionList->current)
      ? self::sqliteDropSchemaPreviewSql($schema)
      : 'DROP SCHEMA ' . self::quoteIdentifier($schema) . ';';
    $directCommand = self::isSQLiteConnection($connectionList->current) ? 'dropSchema' : false;
    $directArguments = self::isSQLiteConnection($connectionList->current) ? [$schema] : [];
    \MADB\Query\GeneratedQueryController::open([
      'title' => 'Drop ' . self::schemaLabel(),
      'name' => 'DROP ' . $schema,
      'sql' => $sql,
      'connection' => $connectionList->current,
      'schema' => $schema,
      'cacheKeys' => ['SchemaList', 'TableList:' . $schema],
      'refresh' => 'schemas',
      'directCommand' => $directCommand,
      'directArguments' => $directArguments
    ]);
    \SPTK\Element::refresh();
  }

  /** Coordinates dropped work in the schema menu. */
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

  /** Applies schemas values to schema menu state or controls. */
  public static function setSchemas($response) {
    if ($response['status'] !== 'OK') {
      self::loadFailed();
      $connection = $response['connection']['name'] ?? 'selected connection';
      \SPTK\Elements\ErrorPanel::forge("Could not connect to {$connection}", $response['result']);
      return;
    }
    $restoredSchema = \MADB\Table\MenuController::getCurrentSchema();
    $restoreTables = false;
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    $menuBox->clear();
    $menuBox->setOnSelect('\MADB\Schema\MenuController::select');
    $menuBox->addItem([
      'name' => 'menu-schema-operations',
      'value' => 'Operations',
      'text' => 'Operations',
      'submenu' => true,
      'classes' => ['MenuSeparator']
    ]);
    foreach ($response['result'] as $index => $schema) {
      $menuItem = $menuBox->addItem([
        'value' => $schema,
        'filterable' => true,
        'selectable' => 'schemas'
      ]);
      if ($schema === self::$selectAfterLoad || $schema === \MADB\Table\MenuController::getCurrentSchema()) {
        $menuItem->setSelected(true);
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

  /** Builds SQLite preview SQL for attached database drop. */
  private static function sqliteDropSchemaPreviewSql($schema): string {
    return implode("\n", [
      '-- SQLite attached database drop preview.',
      '-- MADB will detach this database and delete its sidecar file.',
      'DETACH DATABASE ' . self::quoteSQLiteIdentifier($schema) . ';'
    ]);
  }

}
