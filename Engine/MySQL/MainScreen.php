<?php

namespace MADB\Engine\MySQL;

use MADB\App\MainController;
use MADB\App\DelayedProgress;
use MADB\App\SessionController;
use SPTK\App;
use SPTK\Core\{Screen, Window};
use SPTK\Events\EventContext;
use SPTK\Layout\LayoutLeaf;
use SPTK\Widgets\List\ListView;

/** Run MySQL's menu groups and replace them with schema/table pickers. */
final class MainScreen {

  private static int $menuGeneration = 0;
  private static ?DelayedProgress $menuProgress = null;

  /** Stop menu progress when its main screen is left. */
  public static function cancelPending(): void {
    self::$menuGeneration++;
    self::$menuProgress?->finish();
    self::$menuProgress = null;
  }

  /** Run the selected operation or accept a schema/table from a picker. */
  public static function choose(EventContext $event): bool {
    if (!$event->widget instanceof ListView || !$event->widget->active() || !MainController::menuVisible()) {
      return false;
    }
    $value = $event->widget->activeValue();
    if ($value === null) {
      return true;
    }
    return match ($event->widget->id()) {
      'mysql-database-ops' => self::chooseOperation('database', $value),
      'mysql-table-ops' => self::chooseOperation('table', $value),
      'mysql-databases' => self::selectDatabase($value),
      'mysql-tables' => self::selectTable($value),
      default => false,
    };
  }

  /** Let Escape leave an active list, then restore or close the menu. */
  public static function cancelPicker(EventContext $event): bool {
    if ($event->widget instanceof ListView && $event->widget->active()) {
      return false;
    }
    $screen = self::screen();
    $selectedId = $event->widget?->id() ?? '';
    if (!str_starts_with($selectedId, 'mysql-') && !str_starts_with($selectedId, 'menu-legacy-')) {
      return false;
    }
    if (!MainController::menuVisible()) {
      return false;
    }
    if ($screen->layout->findTile('mysql-databases')->height() !== '0'
      || $screen->layout->findTile('mysql-tables')->height() !== '0' || self::$menuProgress !== null) {
      self::restoreMenu($screen, true);
    } else {
      MainController::closeMenu();
    }
    return true;
  }

  /** Restore the menu groups after a picker or screen change. */
  public static function restoreMenu(Screen $screen, bool $focus): void {
    self::cancelPending();
    $screen->layout->findNode('menu-groups')->setHeight('1*');
    $screen->layout->findTile('mysql-databases')->setHeight('0');
    $screen->layout->findTile('mysql-tables')->setHeight('0');
    if ($focus) {
      MainController::focusMenuTile('mysql-database-ops');
    }
  }

  /** Open an implemented picker or explain a future operation. */
  private static function chooseOperation(string $kind, string $operation): bool {
    if ($operation !== 'select') {
      self::screen()->statusBar->info(ucfirst($kind) . ' ' . str_replace('-', ' ', $operation) . ' is not available yet.', 'background');
      return true;
    }
    if ($kind === 'table' && (MainController::currentConnection()['database'] ?? '') === '') {
      self::screen()->statusBar->error('Select a database first.');
      return true;
    }
    $connectionId = MainController::currentConnection()['id'];
    $generation = ++self::$menuGeneration;
    $query = $kind === 'database' ? 'SHOW DATABASES' : 'SHOW TABLES';
    self::$menuProgress?->finish();
    $progress = self::$menuProgress = new DelayedProgress(self::screen()->statusBar, 'Loading ' . $kind . 's…');
    try {
      SessionController::appQuery($connectionId, $query, static function(array $response) use ($connectionId, $kind, $generation, $progress): void {
        $progress->finish();
        if (self::$menuProgress === $progress) {
          self::$menuProgress = null;
        }
        if (self::window()->currentScreenId() !== 'main-mysql'
          || MainController::currentConnection()['id'] !== $connectionId
          || !MainController::menuVisible() || self::$menuGeneration !== $generation) {
          return;
        }
        $screen = self::screen();
        if (!($response['ok'] ?? false)) {
          $screen->statusBar->error((string)($response['error'] ?? 'Could not list ' . $kind . 's.'));
          return;
        }
        $names = [];
        foreach ($response['result']['rows'] ?? [] as $row) {
          $name = $row[0] ?? null;
          if (is_string($name) && $name !== '') {
            $names[] = ['value' => $name, 'label' => $name];
          }
        }
        $id = $kind === 'database' ? 'mysql-databases' : 'mysql-tables';
        $screen->widget($id)->setItems($names);
        self::showPicker($screen, $id);
      });
    } catch (\Throwable $error) {
      $progress->finish();
      self::$menuProgress = null;
      self::screen()->statusBar->error($error->getMessage());
    }
    return true;
  }

  /** Replace the menu groups with one scrollable list. */
  private static function showPicker(Screen $screen, string $id): void {
    $screen->layout->findNode('menu-groups')->setHeight('0');
    $screen->layout->findTile('mysql-databases')->setHeight($id === 'mysql-databases' ? '1*' : '0');
    $screen->layout->findTile('mysql-tables')->setHeight($id === 'mysql-tables' ? '1*' : '0');
    MainController::focusMenuTile($id);
    $leaf = $screen->layout->findTile($id);
    if ($leaf instanceof LayoutLeaf) {
      $screen->activateLeaf($leaf);
      self::window()->refreshLayout();
    }
  }

  /** Apply USE on the existing app worker, then update the shared schema context. */
  private static function selectDatabase(string $name): bool {
    $id = MainController::currentConnection()['id'];
    $queryId = MainController::activeQueryId();
    self::$menuProgress?->finish();
    $progress = self::$menuProgress = new DelayedProgress(self::screen()->statusBar, 'Selecting database…');
    try {
      SessionController::selectDatabase($id, $name, static function(array $response) use ($id, $queryId, $name, $progress): void {
        $progress->finish();
        if (self::$menuProgress === $progress) {
          self::$menuProgress = null;
        }
        $visible = self::window()->currentScreenId() === 'main-mysql'
          && MainController::currentConnection()['id'] === $id
          && MainController::activeQueryId() === $queryId;
        if ($response['ok'] ?? false) {
          MainController::databaseSelected($id, $queryId, $name);
        }
        if ($visible) {
          $screen = self::screen();
          if (!($response['ok'] ?? false)) {
            $screen->statusBar->error((string)($response['error'] ?? 'Could not select database.'));
          } else {
            self::restoreMenu($screen, MainController::menuVisible());
          }
        }
      });
    } catch (\Throwable $error) {
      $progress->finish();
      self::$menuProgress = null;
      self::screen()->statusBar->error($error->getMessage());
    }
    return true;
  }

  /** Load the selected table through the ordinary user query worker pool. */
  private static function selectTable(string $name): bool {
    self::restoreMenu(self::screen(), true);
    MainController::selectTable($name);
    return true;
  }

  /** Return the MySQL main screen. */
  private static function screen(): Screen {
    return self::window()->screen('main-mysql');
  }

  /** Return the app's only window. */
  private static function window(): Window {
    return array_values(App::eventLoop()->windows())[0];
  }

}
