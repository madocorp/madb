<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADB');
require APP_DIR . '/SPTK/App.php';

use SPTK\Layout\{LayoutSeparator, Tile, WindowGeometry};
use SPTK\Core\Style;
use SPTK\Rendering\Grid;
use SPTK\SDLWrapper\SDL;
use SPTK\XmlParser\XmlParser;
use MADB\Engine\MongoDB\MainScreen as MongoScreen;
use MADB\Engine\SQL\MainResult;
use MADB\App\MainController;

/** Check that a panel has the expected visible height or width. */
function panelSize(int $actual, bool $visible, string $name): void {
  if (($actual > 0) !== $visible) {
    throw new RuntimeException('Unexpected panel size: ' . $name);
  }
}

$screens = (new XmlParser())->windows[0]['screens'];
foreach ($screens as $screen) {
  if (!str_starts_with($screen->id, 'main-')) {
    continue;
  }
  $layout = $screen->layout;
  $defaultState = (new ReflectionMethod(MainController::class, 'initialState'))->invoke(null, ['engine' => 'MySQL']);
  if ($layout->findNode('menu-panel')->width() !== '22' || !$defaultState['menuVisible']) {
    throw new RuntimeException('Main menu did not start visible.');
  }
  $screen->measureGrid(new Tile(0, 0, 120, 80));
  $firstId = match ($screen->id) {
    'main-mysql' => 'mysql-database-ops',
    'main-sqlite' => 'sqlite-database-ops',
    default => 'mongodb-database-ops',
  };
  $first = $layout->findTile($firstId);
  if ($first->grid()->height !== $first->instance()->preferredHeight()
    || $layout->findTile('menu-spacer')->grid()->height < 1) {
    throw new RuntimeException('Menu did not show natural item heights with spare space below.');
  }
  $menuGrid = new Grid(120, 80);
  $screen->paint($menuGrid);
  if ($menuGrid->cell($first->grid()->x, $first->grid()->y + 1)->fg != (new Style())->foreground) {
    throw new RuntimeException('Inactive menu item uses the selected text color.');
  }
  foreach (['menu-legacy-editor', 'menu-legacy-grid', 'menu-legacy-list', 'menu-legacy-madb'] as $id) {
    $group = $layout->findTile($id);
    if ($group->grid()->height !== $group->instance()->preferredHeight()) {
      throw new RuntimeException('A menu group exceeds its natural height: ' . $id);
    }
  }
  $layout->findNode('menu-panel')->setWidth('0');
  $screen->measureGrid(new Tile(0, 0, 120, 48));
  panelSize($layout->findNode('menu-panel')->grid()->width, false, 'menu can be hidden');
  $editor = $layout->findTile('query-editor');
  $screen->selectLeaf($editor);
  $screen->handleEvent((object)['type' => SDL::SDL_EVENT_KEY_DOWN,
    'key' => (object)['key' => SDL::KEY_UP, 'mod' => 0, 'repeat' => false]]);
  if ($screen->selectedLeaf() !== $editor) {
    throw new RuntimeException('Up from the editor selected a hidden menu item.');
  }
  panelSize($layout->findNode('query-list-panel')->grid()->width, true, 'query list starts visible');
  panelSize($layout->findNode('query-panel')->grid()->height, true, 'query starts open');
  panelSize($layout->findNode('result-panel')->grid()->height, false, 'result starts hidden');
  $layout->findNode('menu-panel')->setWidth('22');
  $layout->findNode('query-list-panel')->setWidth('0');
  $layout->findNode('query-panel')->setHeight('2');
  $layout->findTile('query-editor')->setHeight('0');
  $layout->findTile('compact-query')->setHeight('1');
  $layout->findTile('query-stat')->setHeight('1');
  $layout->findNode('result-panel')->setHeight('2*');
  $resultId = $screen->id === 'main-mongodb' ? 'result-text' : 'result-table';
  $layout->findTile($resultId)->setHeight('1*');
  $screen->measureGrid(new Tile(0, 0, 120, 48));
  panelSize($layout->findNode('menu-panel')->grid()->width, true, 'menu opens');
  foreach (['editor', 'grid', 'list', 'madb'] as $group) {
    if ($layout->findTile('menu-legacy-' . $group) === null) {
      throw new RuntimeException('Missing old MaDB menu group: ' . $group);
    }
  }
  $databaseId = match ($screen->id) {
    'main-mysql' => 'mysql-database-ops',
    'main-sqlite' => 'sqlite-database-ops',
    default => 'mongodb-database-ops',
  };
  $screen->selectLeaf($layout->findTile('menu-legacy-grid'));
  $screen->measureGrid(new Tile(0, 0, 120, 48));
  if (!$layout->findNode('menu-groups')->isScrolling()
    || $layout->findTile($databaseId)->isDeckTile()
    || $layout->findTile($databaseId)->grid()->height !== $layout->findTile($databaseId)->instance()->preferredHeight()
    || $layout->findTile('menu-legacy-grid')->grid()->height !== $layout->findTile('menu-legacy-grid')->instance()->preferredHeight()) {
    throw new RuntimeException('Full menu groups did not scroll at their natural heights.');
  }
  foreach (['engine', 'connection', 'cache'] as $removed) {
    if ($layout->findTile('menu-legacy-' . $removed) !== null) {
      throw new RuntimeException('Removed menu group is still present: ' . $removed);
    }
  }
  panelSize($layout->findNode('query-list-panel')->grid()->width, false, 'query list closes');
  panelSize($layout->findTile('query-editor')->grid()->height, false, 'editor collapses');
  panelSize($layout->findTile('compact-query')->grid()->height, true, 'compact query appears');
  panelSize($layout->findNode('result-panel')->grid()->height, true, 'result opens');
  if ($screen->id === 'main-mysql') {
    panelSize($layout->findNode('menu-groups')->grid()->height, true, 'MySQL menu groups appear');
    $operations = $layout->findTile('mysql-database-ops');
    $screen->selectLeaf($operations);
    $screen->measureGrid(new Tile(0, 0, 120, 48));
    $grid = new Grid(120, 48);
    $screen->paint($grid);
    $selectCell = $grid->cell($operations->grid()->x, $operations->grid()->y + 1);
    if ((array)$selectCell->fg !== (array)(new Style())->foreground) {
      throw new RuntimeException('Select is highlighted before list activation.');
    }
    $databaseOps = $operations->instance();
    $screen->handleEvent((object)['type' => SDL::SDL_EVENT_KEY_DOWN,
      'key' => (object)['key' => SDL::KEY_RETURN, 'mod' => 0, 'repeat' => false]]);
    if (!$databaseOps->active()) {
      throw new RuntimeException('First Return did not activate the operation list.');
    }
    $screen->handleEvent((object)['type' => SDL::SDL_EVENT_KEY_DOWN,
      'key' => (object)['key' => SDL::KEY_ESCAPE, 'mod' => 0, 'repeat' => false]]);
    if ($databaseOps->active()) {
      throw new RuntimeException('Escape did not leave the active operation list.');
    }
    $layout->findNode('menu-groups')->setHeight('0');
    $layout->findTile('mysql-databases')->setHeight('1*');
    $screen->measureGrid(new Tile(0, 0, 120, 48));
    panelSize($layout->findNode('menu-groups')->grid()->height, false, 'menu groups hide for database list');
    panelSize($layout->findTile('mysql-databases')->grid()->height, true, 'database picker replaces menu');
    $screen->measureArea(new Tile(0, 0, 120, 48), new WindowGeometry(8, 16, 960, 768, 0, 0));
    foreach ((new ReflectionProperty($layout->findNode('menu-groups'), 'children'))->getValue($layout->findNode('menu-groups')) as $child) {
      if ($child instanceof LayoutSeparator) {
        $area = (new ReflectionProperty($child, 'area'))->getValue($child);
        panelSize($area->height, false, 'hidden menu separator must not cross picker title');
      }
    }
    $layout->findTile('mysql-databases')->setHeight('0');
    $layout->findTile('mysql-tables')->setHeight('1*');
    $screen->measureGrid(new Tile(0, 0, 120, 48));
    panelSize($layout->findTile('mysql-tables')->grid()->height, true, 'table picker replaces menu');
  }
  if ($screen->id === 'main-mongodb') {
    MongoScreen::render($screen, ['rows' => [['{"ok":1}']]]);
    if (!str_contains($screen->widget('result-text')->text(), '"ok": 1')) {
      throw new RuntimeException('MongoDB result was not formatted as JSON.');
    }
  } else {
    MainResult::render($screen, ['columns' => ['value'], 'rows' => [[42]]]);
    if ($screen->widget('result-table')->header() !== ['value'] || $screen->widget('result-table')->rowValues(0) !== ['42']) {
      throw new RuntimeException('SQL result table was not populated.');
    }
  }
}
echo "Main layout checks passed.\n";
