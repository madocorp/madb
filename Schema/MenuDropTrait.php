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
    $content = self::dropConfirmationContent($schema, $info, $response['connection']);
    $content .= "%CONFIRMATION%";
    $sql = self::dropPreviewText($schema, $response['connection']);
    $directCommand = self::isDirectDropConnection($response['connection']) ? 'dropSchema' : false;
    $directArguments = self::isDirectDropConnection($response['connection']) ? [$schema] : [];
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
    $sql = self::dropPreviewText($schema, $connectionList->current);
    $directCommand = self::isDirectDropConnection($connectionList->current) ? 'dropSchema' : false;
    $directArguments = self::isDirectDropConnection($connectionList->current) ? [$schema] : [];
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
    $operationOffset = 0;
    $operations = \MADB\Engine\EngineRegistry::primaryMenuItems($response['connection']['engine'] ?? null);
    self::setMenuItems('menu-schema-operations', $operations);
    if (!empty($operations)) {
      $menuBox->addItem([
        'name' => 'menu-schema-operations',
        'value' => 'Operations',
        'text' => 'Operations',
        'submenu' => true,
        'classes' => ['MenuSeparator']
      ]);
      $operationOffset = 1;
    }
    $selectedSchema = self::$selectAfterLoad !== false
      ? self::$selectAfterLoad
      : \MADB\Table\MenuController::getCurrentSchema();
    foreach ($response['result'] as $index => $schema) {
      $menuItem = $menuBox->addItem([
        'value' => $schema,
        'filterable' => true,
        'selectable' => 'schemas'
      ]);
      if ($schema === $selectedSchema) {
        $menuItem->setSelected(true);
        $menuBox->moveCursor($index + $operationOffset);
        if (self::$selectAfterLoad === false && $schema === $restoredSchema) {
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

  /** Returns the confirmation content for dropping an engine primary object. */
  private static function dropConfirmationContent($schema, array $info, array $connection): string {
    if (($connection['engine'] ?? '') === 'MongoDB') {
      $collections = (int)($info['collections'] ?? $info['tables'] ?? 0);
      $objects = (int)($info['objects'] ?? 0);
      $indexes = (int)($info['indexes'] ?? 0);
      $bytes = (int)($info['bytes'] ?? 0);
      $content = "The following actions will be performed.\n";
      $content .= "- {$schema} " . self::schemaLabel() . " will be dropped\n";
      $content .= "- {$collections} " . ($collections === 1 ? 'collection' : 'collections') . " will be deleted\n";
      $content .= "- {$objects} " . ($objects === 1 ? 'document' : 'documents') . " will be deleted\n";
      $content .= "- {$indexes} " . ($indexes === 1 ? 'index' : 'indexes') . " will be deleted\n";
      $content .= "- " . \MADB\App\Format::bytes($bytes) . " data and indexes will be deleted\n";
      $content .= "- Cached database and collection lists for this connection will be cleared\n";
      return $content;
    }
    $tables = $info['tables'] ?? 0;
    $views = $info['views'] ?? 0;
    $bytes = $info['bytes'] ?? 0;
    $content = "The following actions will be performed.\n";
    $content .= "- {$schema} " . self::schemaLabel() . " will be dropped\n";
    $content .= "- {$tables} tables will be deleted\n";
    $content .= "- {$views} views will be deleted\n";
    $content .= "- " . \MADB\App\Format::bytes($bytes) . " table data and indexes will be deleted\n";
    $content .= "- Cached schema and table lists for this connection will be cleared\n";
    return $content;
  }

  /** Returns SQL or command preview text for an engine primary-object drop. */
  private static function dropPreviewText($schema, array $connection): string {
    if (($connection['engine'] ?? '') === 'MongoDB') {
      $json = json_encode(['dropDatabase' => 1], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      return $json === false ? '{"dropDatabase": 1}' : $json;
    }
    return self::isSQLiteConnection($connection)
      ? self::sqliteDropSchemaPreviewSql($schema)
      : 'DROP SCHEMA ' . self::quoteIdentifier($schema) . ';';
  }

  /** Returns whether drop should execute as a direct engine command after confirmation. */
  private static function isDirectDropConnection(array $connection): bool {
    return self::isSQLiteConnection($connection);
  }

  /** Replaces a named menu box with engine-provided item definitions. */
  private static function setMenuItems(string $menuBoxName, array $items): void {
    $menuBox = self::findMenuBox($menuBoxName);
    if ($menuBox === false) {
      return;
    }
    $menuBox->clear();
    foreach ($items as $item) {
      $menuBox->addItem($item);
    }
  }

  /** Finds a menu box by name or by submenu ownership target. */
  private static function findMenuBox(string $menuBoxName) {
    $menuBox = \SPTK\Element::byName($menuBoxName);
    if ($menuBox !== false) {
      return $menuBox;
    }
    $queue = [\SPTK\Element::$root];
    while (!empty($queue)) {
      $element = array_shift($queue);
      if ($element instanceof \SPTK\Elements\MenuBox && $element->belongsTo === $menuBoxName) {
        return $element;
      }
      foreach ($element->getDescendants() as $descendant) {
        $queue[] = $descendant;
      }
    }
    return false;
  }

}
