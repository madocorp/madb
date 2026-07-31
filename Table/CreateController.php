<?php

namespace MADB\Table;

/** Routes object create menu items to engine-specific create panels. */
class CreateController {

  /** Opens the table create flow for the selected connection engine. */
  public static function openTableCreate() {
    if (self::isSQLiteConnection()) {
      \MADB\Table\SQLiteTableCreateController::openCreate();
      return;
    }
    \MADB\Table\EditorController::openCreate();
  }

  /** Opens the view create flow for the selected connection engine. */
  public static function openViewCreate() {
    if (self::isSQLiteConnection()) {
      \MADB\Table\SQLiteViewCreateController::openCreate();
      return;
    }
    \MADB\Table\ViewController::openCreate();
  }

  /** Opens the collection create flow for engines that expose collections. */
  public static function openCollectionCreate() {
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    if ($connectionList->current === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    $panel = \SPTK\Element::byName('mongodb-collection-create');
    $panel->setValue([
      'mongodb-collection-database' => \MADB\Table\MenuController::getCurrentSchema() ?: ($connectionList->current['database'] ?? ''),
      'mongodb-collection-name' => ''
    ]);
    $panel->show();
    \SPTK\Element::refresh();
  }

  /** Generates a MongoDB createCollection command from the create panel. */
  public static function generateCollectionCreate($panel): void {
    $values = $panel->getValue();
    $database = trim((string)($values['mongodb-collection-database'] ?? ''));
    $collection = trim((string)($values['mongodb-collection-name'] ?? ''));
    if ($database === '') {
      \SPTK\Elements\WarningPanel::forge('Missing database', 'Please enter the database name before creating the collection.');
      return;
    }
    if ($collection === '') {
      \SPTK\Elements\WarningPanel::forge('Missing collection', 'Please enter the collection name before creating the collection.');
      return;
    }
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    if ($connectionList->current === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    $panel->hide();
    \MADB\Query\GeneratedQueryController::open([
      'title' => 'Create collection',
      'name' => 'CREATE ' . $database . '.' . $collection,
      'sql' => self::mongoCreateCollectionCommand($collection),
      'connection' => $connectionList->current,
      'schema' => $database,
      'table' => $collection,
      'cacheKeys' => ['SchemaList', 'TableList:' . $database],
      'refresh' => 'schemasThenTables'
    ]);
    \SPTK\Element::refresh();
  }

  /** Returns whether the current connection is SQLite. */
  private static function isSQLiteConnection(): bool {
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    $connection = $connectionList->current;
    return is_array($connection) && strcasecmp((string)($connection['engine'] ?? ''), 'SQLite') === 0;
  }

  /** Builds a MongoDB create command preview. */
  private static function mongoCreateCollectionCommand(string $collection): string {
    $json = json_encode(['create' => $collection], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? '{"create": ""}' : $json;
  }

}
