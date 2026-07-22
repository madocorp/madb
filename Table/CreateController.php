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

  /** Returns whether the current connection is SQLite. */
  private static function isSQLiteConnection(): bool {
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    $connection = $connectionList->current;
    return is_array($connection) && strcasecmp((string)($connection['type'] ?? ''), 'SQLite') === 0;
  }

}
