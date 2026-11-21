<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADB');
require APP_DIR . '/SPTK/App.php';

use MADB\App\QueryList;
use MADB\App\MainController;
use SPTK\XmlParser\XmlParser;

/** Check a persistent query list without touching the user's application data. */
function checkQueries(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$home = sys_get_temp_dir() . '/madb-queries-' . bin2hex(random_bytes(5));
$originalHome = getenv('HOME');
mkdir($home);
putenv('HOME=' . $home);
try {
  mkdir($home . '/.madb', 0700);
  file_put_contents($home . '/.madb/query-history.json', json_encode(['connections' => [
    'legacy' => [['id' => 'old-query', 'query' => 'SELECT 42', 'state' => 'done']],
  ]]));
  $list = new QueryList();
  checkQueries($list->active('legacy')['text'] === 'SELECT 42', 'Existing queries were not imported.');
  $list->initializeDatabases('legacy', 'old_default');
  checkQueries($list->active('legacy')['database'] === 'old_default', 'Imported query did not inherit its connection database.');
  $list->initializeDatabases('legacy', 'new_default');
  checkQueries($list->active('legacy')['database'] === 'old_default', 'A changed connection default replaced a query database.');
  $first = $list->ensure('first', 'starting_db');
  checkQueries(count($list->entries('first')) === 1 && $first['text'] === ''
    && $first['database'] === 'starting_db', 'First connection needs a blank tab with its default database.');
  $list->updateDatabase('first', $first['id'], 'orders');
  $new = $list->create('first');
  checkQueries($new['database'] === 'orders', 'New query did not inherit the active query database.');
  $list->updateDatabase('first', $new['id'], 'analytics');
  $list->select('first', $first['id']);
  $list->updateText('first', $first['id'], 'SELECT 1');
  $list->status('first', $first['id'], 'ok');
  $list->status('first', $first['id'], 'ok');
  checkQueries(count($list->entries('first')) === 2, 'Running a query added a tab.');
  $second = $list->cloneActive('first');
  checkQueries($second['text'] === 'SELECT 1' && $second['database'] === 'orders'
    && count($list->entries('first')) === 3, 'Clone did not copy query text and database.');
  checkQueries($list->rename('first', $second['id'], 'Useful query'), 'Rename failed.');
  checkQueries(!$list->rename('first', $second['id'], "bad\nname"), 'Multiline name was accepted.');
  $list->togglePin('first');
  checkQueries($list->entries('first')[0]['id'] === $second['id'], 'Pinned query did not move first.');
  $list->ensure('second');
  checkQueries(count($list->entries('second')) === 1, 'Connections shared their query tabs.');
  $list = new QueryList();
  checkQueries($list->activeId('first') === $second['id'] && $list->active('first')['name'] === 'Useful query',
    'Active query or name was not persisted.');
  checkQueries($list->active('first')['database'] === 'orders' && $list->get('first', $new['id'])['database'] === 'analytics'
    && $list->get('first', $first['id'])['database'] === 'orders',
    'Query databases were not persisted.');
  $list->deleteActive('first');
  checkQueries($list->activeId('first') === $new['id'], 'Deleting a tab did not select its neighbor.');
  checkQueries($list->active('first')['database'] === 'analytics', 'Deleting a tab lost its neighbor database.');
  $list->deleteActive('first');
  checkQueries($list->activeId('first') === $first['id'], 'Deleting a tab did not select its next neighbor.');
  $list->deleteActive('first');
  checkQueries(count($list->entries('first')) === 1 && $list->active('first')['text'] === ''
    && $list->active('first')['database'] === 'orders',
    'Deleting the final tab did not leave a blank query.');
  $list->deleteConnection('first');
  checkQueries($list->entries('first') === [] && count($list->entries('second')) === 1,
    'Deleting a connection affected another query list.');
  $alpha = $list->ensure('order');
  $list->rename('order', $alpha['id'], 'Alpha');
  $beta = $list->create('order', 'Beta');
  $gamma = $list->create('order', 'Gamma');
  $list->select('order', $alpha['id']);
  $list->togglePin('order');
  $list->select('order', $beta['id']);
  $list->togglePin('order');
  checkQueries(array_column($list->entries('order'), 'id') === [$beta['id'], $alpha['id'], $gamma['id']],
    'Pinning did not move the query to the top.');
  $delta = $list->create('order', 'Delta');
  checkQueries(array_column($list->entries('order'), 'id') === [$beta['id'], $alpha['id'], $delta['id'], $gamma['id']],
    'New query did not start after pinned rows.');
  $list->select('order', $beta['id']);
  $list->togglePin('order');
  checkQueries(array_column($list->entries('order'), 'id') === [$alpha['id'], $beta['id'], $delta['id'], $gamma['id']],
    'Unpinning did not move the query after pinned rows.');
  checkQueries($list->setPinned('order', [$alpha['id'], $gamma['id']]), 'List marks did not change pins.');
  checkQueries(array_column($list->entries('order'), 'id') === [$gamma['id'], $alpha['id'], $beta['id'], $delta['id']],
    'Newly marked row did not move to the top.');
  checkQueries(!$list->setPinned('order', [$gamma['id'], $alpha['id']]),
    'A row move treated unchanged pin marks as a new pin action.');
  $list->reorder('order', [$alpha['id'], $gamma['id'], $beta['id'], $delta['id']], $gamma['id']);
  $ordered = $list->entries('order');
  checkQueries(array_column($ordered, 'id') === [$alpha['id'], $gamma['id'], $beta['id'], $delta['id']]
    && array_column($ordered, 'pinned') === [true, true, false, false],
    'Moving inside the pinned section changed pin flags.');
  $list->select('order', $alpha['id']);
  $screens = (new XmlParser())->windows[0]['screens'];
  $screen = array_values(array_filter($screens, static fn($screen): bool => $screen->id === 'main-mysql'))[0];
  (new ReflectionProperty(MainController::class, 'queries'))->setValue(null, $list);
  (new ReflectionMethod(MainController::class, 'showQueries'))->invoke(null, $screen, 'order');
  $widget = $screen->widget('query-list');
  checkQueries($widget->items()[0]['label'] === 'Alpha' && $widget->activeValue() === $alpha['id']
    && $widget->getValue() === [$alpha['id'], $gamma['id']], 'List cursor and pin marks were not independent.');
  $widget->setFilter('Alpha');
  (new ReflectionMethod(MainController::class, 'showQueries'))->invoke(null, $screen, 'order');
  checkQueries($widget->filter() === 'Alpha' && $widget->activeValue() === $alpha['id'],
    'Refreshing query list lost name filtering or its cursor.');
  checkQueries($list->setPinned('order', [$gamma['id']]), 'Unmarking a pinned row did not remove its pin.');
  checkQueries(array_column($list->entries('order'), 'id') === [$gamma['id'], $alpha['id'], $beta['id'], $delta['id']],
    'Unmarked row did not move after the pinned section.');
  $unpinned = $list->ensure('boundary');
  $pinned = $list->create('boundary');
  $list->togglePin('boundary');
  $list->reorder('boundary', [$unpinned['id'], $pinned['id']], $unpinned['id']);
  checkQueries(array_column($list->entries('boundary'), 'id') === [$unpinned['id'], $pinned['id']]
    && array_column($list->entries('boundary'), 'pinned') === [true, true],
    'Moving an unpinned row up changed the other row’s pin.');
  $list->setPinned('boundary', [$pinned['id']]);
  $list->reorder('boundary', [$unpinned['id'], $pinned['id']], $pinned['id']);
  checkQueries(array_column($list->entries('boundary'), 'id') === [$unpinned['id'], $pinned['id']]
    && array_column($list->entries('boundary'), 'pinned') === [false, false],
    'Moving a pinned row down changed the other row’s pin.');
  $list = new QueryList();
  checkQueries(array_column($list->entries('order'), 'pinned') === [true, false, false, false],
    'Reordered pin flags were not persisted.');
} finally {
  putenv($originalHome === false ? 'HOME' : 'HOME=' . $originalHome);
  foreach (glob($home . '/.madb/*') ?: [] as $file) {
    unlink($file);
  }
  if (is_dir($home . '/.madb')) {
    rmdir($home . '/.madb');
  }
  rmdir($home);
}
echo "Query list checks passed.\n";
