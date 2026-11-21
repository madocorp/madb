<?php

namespace MADB\App;

use MADB\Engine\MongoDB\MainScreen as MongoScreen;
use MADB\Engine\MySQL\MainScreen as MySQLScreen;
use MADB\Engine\SQLite\MainScreen as SQLiteScreen;
use SPTK\App;
use SPTK\Events\EventContext;
use SPTK\Layout\LayoutLeaf;
use SPTK\Widgets\List\ListView;

/** Handle menu groups retained from the older MaDB interface. */
final class MenuController {

  /** Focus the current engine's database group with D. */
  public static function focusDatabase(EventContext $event): bool {
    $screen = self::screenId();
    $id = match ($screen) {
      'main-mysql' => 'mysql-database-ops',
      'main-sqlite' => 'sqlite-database-ops',
      'main-mongodb' => 'mongodb-database-ops',
      default => null,
    };
    return $id !== null && self::focus($event, $id);
  }

  /** Focus MySQL's table group with T. */
  public static function focusTable(EventContext $event): bool {
    return self::focus($event, 'mysql-table-ops');
  }

  /** Focus SQLite's object group with O. */
  public static function focusObject(EventContext $event): bool {
    return self::focus($event, 'sqlite-object-ops');
  }

  /** Focus MongoDB's collection group with C. */
  public static function focusCollection(EventContext $event): bool {
    return self::focus($event, 'mongodb-collection-ops');
  }

  /** Focus the shared Editor group with E. */
  public static function focusEditor(EventContext $event): bool {
    return self::focus($event, 'menu-legacy-editor');
  }

  /** Focus the shared Grid group with G. */
  public static function focusGrid(EventContext $event): bool {
    return self::focus($event, 'menu-legacy-grid');
  }

  /** Focus the shared List group with L. */
  public static function focusList(EventContext $event): bool {
    return self::focus($event, 'menu-legacy-list');
  }

  /** Focus the shared MaDB group with M. */
  public static function focusMadb(EventContext $event): bool {
    return self::focus($event, 'menu-legacy-madb');
  }

  /** Run an available shortcut or explain a future menu action. */
  public static function choose(EventContext $event): bool {
    if (!$event->widget instanceof ListView || !$event->widget->active()) {
      return false;
    }
    $id = $event->widget->id() ?? '';
    $prefix = 'menu-legacy-';
    $engineGroups = [
      'sqlite-database-ops' => 'Database',
      'sqlite-object-ops' => 'Object',
      'mongodb-database-ops' => 'Database',
      'mongodb-collection-ops' => 'Collection',
    ];
    if (!str_starts_with($id, $prefix) && !isset($engineGroups[$id])) {
      return false;
    }
    if (!MainController::menuVisible()) {
      return false;
    }
    $value = $event->widget->activeValue();
    if ($value === null) {
      return true;
    }
    $group = $engineGroups[$id] ?? substr($id, strlen($prefix));
    if ($group === 'editor' && $value === 'execute') {
      return MainController::runQuery($event);
    }
    if ($group === 'list') {
      return QueryListController::choose($value);
    }
    if (SQLiteScreen::menu($event) || MongoScreen::menu($event)) {
      return true;
    }
    $label = ucfirst(str_replace('-', ' ', $group . ' ' . $value));
    $window = array_values(App::eventLoop()->windows())[0];
    $window->screen($window->currentScreenId())->statusBar->info($label . ' is not available yet.', 'background');
    return true;
  }

  /** Focus and activate a menu block from any inactive tile on the main screen. */
  private static function focus(EventContext $event, string $id): bool {
    $window = array_values(App::eventLoop()->windows())[0];
    $screen = $window->screen($window->currentScreenId());
    if ($screen->activeLeaf() !== null) {
      return false;
    }
    if (!MainController::menuVisible()) {
      MainController::toggleMenu($event);
    }
    if ($screen->layout->findNode('menu-groups')->height() === '0' && $screen->id === 'main-mysql') {
      MySQLScreen::restoreMenu($screen, false);
    }
    $leaf = $screen->layout->findTile($id);
    if (!$leaf instanceof LayoutLeaf || $screen->layout->findNode('menu-groups')->height() === '0') {
      return false;
    }
    MainController::focusMenuTile($id);
    if ($leaf->grid()->width === 0 || $leaf->grid()->height === 0) {
      return false;
    }
    $screen->activateLeaf($leaf);
    $window->refreshLayout();
    return true;
  }

  /** Return the visible screen identifier for an engine-specific shortcut. */
  private static function screenId(): string {
    $window = array_values(App::eventLoop()->windows())[0];
    return $window->currentScreenId();
  }

}
