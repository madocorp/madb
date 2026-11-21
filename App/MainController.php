<?php

namespace MADB\App;

use MADB\Connection\Store;
use MADB\Engine\MongoDB\MainScreen as MongoScreen;
use MADB\Engine\MySQL\MainScreen as MySQLScreen;
use MADB\Engine\SQL\MainResult;
use SPTK\App;
use SPTK\Core\{Screen, Window};
use SPTK\Events\EventContext;
use SPTK\Layout\{LayoutLeaf, LayoutNode};
use SPTK\Widgets\List\ListView;
use SPTK\Widgets\TextEditor\TextEditor;

/** Share main-screen panels, shortcuts, query execution, and per-connection state. */
final class MainController {

  private static ?Window $window = null;
  private static ?QueryList $queries = null;
  private static ?string $activeId = null;
  private static ?string $previousId = null;
  private static array $states = [];

  /** Show an engine-specific main screen for one saved connection. */
  public static function open(array $connection): void {
    self::$window ??= array_values(App::eventLoop()->windows())[0];
    self::$queries ??= new QueryList();
    self::rememberQuery();
    if (self::$activeId !== null && self::$states[self::$activeId]['connection']['engine'] === 'MySQL') {
      MySQLScreen::cancelPending();
    }
    $id = $connection['id'];
    self::$queries->initializeDatabases($id, (string)($connection['database'] ?? ''));
    $query = self::$queries->ensure($id, (string)($connection['database'] ?? ''));
    self::$activeId = $id;
    self::$previousId = $id;
    if ($connection['engine'] === 'MySQL') {
      $connection['database'] = $query['database'];
    }
    self::$states[$id] ??= self::initialState($connection);
    self::$states[$id]['connection'] = $connection;
    self::$states[$id]['queryId'] = $query['id'];
    self::$states[$id]['query'] = $query['text'];
    self::$states[$id]['title'] = $query['name'];
    $screenId = self::screenId($connection['engine']);
    self::$window->setCurrentScreenId($screenId);
    $screen = self::screen();
    $screen->statusBar->clear();
    if ($connection['engine'] === 'MySQL') {
      MySQLScreen::restoreMenu($screen, false);
    }
    self::editor($screen)->setValue(self::$states[$id]['query']);
    $screen->widget('query-list')->setFilter('');
    self::showQueries($screen, $id);
    self::render($screen, self::$states[$id]);
    self::layout($screen, self::$states[$id]['editorOpen'] ? 'query-editor' : 'compact-query');
    SessionController::open($connection, $screenId);
  }

  /** Return to the connection board while retaining the active draft. */
  public static function back(EventContext $event): bool {
    self::rememberQuery();
    $id = self::$activeId;
    if ($id !== null && self::$states[$id]['connection']['engine'] === 'MySQL') {
      MySQLScreen::cancelPending();
    }
    self::$activeId = null;
    SessionController::leaveMain();
    Controller::returnToBoard($id);
    return true;
  }

  /** Reopen the most recent connection from the board with F1 or Escape. */
  public static function returnPrevious(EventContext $event): bool {
    if (self::$previousId === null) {
      return false;
    }
    $connection = (new Store())->get(self::$previousId);
    if ($connection === null) {
      self::$previousId = null;
      return false;
    }
    self::open($connection);
    return true;
  }

  /** Show or hide the engine-specific menu on the left. */
  public static function toggleMenu(EventContext $event): bool {
    $state = &self::activeState();
    if ($state['menuVisible']) {
      self::closeMenu();
      return true;
    }
    $state['menuVisible'] = !$state['menuVisible'];
    $firstMenu = match ($state['connection']['engine']) {
      'MySQL' => 'mysql-database-ops',
      'SQLite' => 'sqlite-database-ops',
      default => 'mongodb-database-ops',
    };
    self::layout(self::screen(), $state['menuVisible'] ? $firstMenu : 'query-editor');
    return true;
  }

  /** Close an engine menu and return focus to the query editor. */
  public static function closeMenu(): void {
    $state = &self::activeState();
    if (!$state['menuVisible']) {
      return;
    }
    $state['menuVisible'] = false;
    if ($state['connection']['engine'] === 'MySQL') {
      MySQLScreen::restoreMenu(self::screen(), false);
    }
    self::layout(self::screen(), $state['editorOpen'] ? 'query-editor' : 'compact-query');
  }

  /** Collapse the query to two summary rows or reopen its editor. */
  public static function toggleEditor(EventContext $event): bool {
    self::rememberQuery();
    $state = &self::activeState();
    $state['editorOpen'] = !$state['editorOpen'];
    self::render(self::screen(), $state);
    self::layout(self::screen(), $state['editorOpen'] ? 'query-editor' : 'compact-query');
    return true;
  }

  /** Collapse results to two status rows or reopen the full result view. */
  public static function toggleResults(EventContext $event): bool {
    $state = &self::activeState();
    if ($state['result'] === null) {
      return true;
    }
    $state['resultOpen'] = !$state['resultOpen'];
    $resultId = $state['connection']['engine'] === 'MongoDB' ? 'result-text' : 'result-table';
    self::layout(self::screen(), $state['resultOpen'] ? $resultId : 'result-stat');
    return true;
  }

  /** Show or hide this connection's query list on the right. */
  public static function toggleQueryList(EventContext $event): bool {
    $state = &self::activeState();
    $state['listVisible'] = !$state['listVisible'];
    self::layout(self::screen(), $state['listVisible'] ? 'query-list'
      : ($state['editorOpen'] ? 'query-editor' : 'compact-query'));
    return true;
  }

  /** Send the current editor query to an ordinary pooled worker. */
  public static function runQuery(EventContext $event): bool {
    $id = self::$activeId;
    if ($id === null) {
      return false;
    }
    self::rememberQuery();
    $state = &self::$states[$id];
    $query = trim($state['query']);
    if ($query === '') {
      self::screen()->statusBar->error('Enter a query first.');
      return true;
    }
    $queryId = $state['queryId'];
    $key = bin2hex(random_bytes(8));
    self::$queries->status($id, $queryId, 'running');
    $state['latestKey'] = $key;
    $state['stat'] = 'Running query…';
    $state['queryViews'][$queryId] = self::viewState($state);
    self::render(self::screen(), $state);
    self::showQueries(self::screen(), $id);
    self::layout(self::screen());
    try {
      SessionController::query($id, $query, static function(array $response) use ($id, $queryId, $key): void {
        self::receive($id, $queryId, $key, $response);
      }, $state['connection']['engine'] === 'MySQL' ? $state['connection']['database'] : null);
    } catch (\Throwable $error) {
      self::receive($id, $queryId, $key, ['ok' => false, 'error' => $error->getMessage()]);
    }
    return true;
  }

  /** Run an engine menu request on the reserved app worker. */
  public static function runAppQuery(string $query): bool {
    $id = self::$activeId;
    if ($id === null) {
      return false;
    }
    $state = &self::$states[$id];
    $requestKey = 'app:' . bin2hex(random_bytes(8));
    $state['latestKey'] = $requestKey;
    $state['title'] = self::title($query);
    $state['stat'] = 'Loading ' . $state['title'] . '…';
    self::render(self::screen(), $state);
    try {
      SessionController::appQuery($id, $query, static function(array $response) use ($id, $requestKey): void {
        self::receiveApp($id, $requestKey, $response);
      });
    } catch (\Throwable $error) {
      self::receiveApp($id, $requestKey, ['ok' => false, 'error' => $error->getMessage()]);
    }
    return true;
  }

  /** Select a stored query from the right-hand list. */
  public static function selectQuery(EventContext $event): bool {
    $id = self::$activeId;
    if ($id === null || !$event->widget instanceof ListView) {
      return false;
    }
    return self::openQuery($event->widget->activeValue() ?? '');
  }

  /** Open a saved query and restore its current result pane. */
  public static function openQuery(string $queryId): bool {
    $id = self::$activeId;
    if ($id === null || ($query = self::queryList()->get($id, $queryId)) === null) {
      return false;
    }
    if (self::$states[$id]['queryId'] === $queryId) {
      return true;
    }
    self::rememberQuery();
    $state = &self::$states[$id];
    $state['queryViews'][$state['queryId']] = self::viewState($state);
    self::queryList()->select($id, $queryId);
    $state['queryId'] = $queryId;
    $state['query'] = $query['text'];
    $state['title'] = $query['name'];
    $databaseChanged = $state['connection']['engine'] === 'MySQL'
      && ($state['connection']['database'] ?? '') !== $query['database'];
    if ($state['connection']['engine'] === 'MySQL') {
      $state['connection']['database'] = $query['database'];
    }
    foreach ($state['queryViews'][$queryId] ?? self::emptyView() as $field => $value) {
      $state[$field] = $value;
    }
    $screen = self::screen();
    self::editor($screen)->setValue($state['query']);
    self::showQueries($screen, $id);
    self::render($screen, $state);
    self::layout($screen);
    if ($databaseChanged && $query['database'] !== '') {
      self::syncQueryDatabase($id, $queryId, $query['database']);
    }
    return true;
  }

  /** Expose the saved list to its menu controller. */
  public static function queryList(): QueryList {
    return self::$queries ??= new QueryList();
  }

  /** Return the active connection identifier for list actions. */
  public static function activeConnectionId(): ?string {
    return self::$activeId;
  }

  /** Return the active saved query identifier. */
  public static function activeQueryId(): string {
    return self::activeState()['queryId'];
  }

  /** Save the current draft before list actions or shutdown. */
  public static function saveActiveQuery(): void {
    self::rememberQuery();
  }

  /** Refresh list labels after rename, pin, or reorder. */
  public static function refreshQueries(): void {
    if (self::$activeId !== null) {
      $state = &self::$states[self::$activeId];
      $state['title'] = self::queryList()->active(self::$activeId)['name'];
      self::showQueries(self::screen(), self::$activeId);
      self::render(self::screen(), $state);
      self::layout(self::screen());
    }
  }

  /** Put keyboard focus into the saved query list. */
  public static function focusQueryList(): void {
    self::focusQueryTile('query-list');
  }

  /** Put keyboard focus into the inline query name input. */
  public static function focusQueryName(): void {
    self::focusQueryTile('query-name-input');
  }

  /** Flush the current editor before the application closes. */
  public static function shutdown(EventContext $event): void {
    self::rememberQuery();
  }

  /** Forget UI state and saved queries for a deleted connection. */
  public static function forget(string $id): void {
    unset(self::$states[$id]);
    (self::$queries ??= new QueryList())->deleteConnection($id);
    if (self::$previousId === $id) {
      self::$previousId = null;
    }
  }

  /** Drop cached main-screen settings after the saved connection is edited. */
  public static function invalidateConnection(string $id): void {
    unset(self::$states[$id]);
  }

  /** Return the current saved connection plus its runtime schema selection. */
  public static function currentConnection(): array {
    return self::activeState()['connection'];
  }

  /** Report whether the current connection's menu is showing. */
  public static function menuVisible(): bool {
    return self::activeState()['menuVisible'];
  }

  /** Focus a visible XML tile after changing the engine menu content. */
  public static function focusMenuTile(string $id): void {
    self::layout(self::screen(), $id);
  }

  /** Store a selected schema on the query that requested it. */
  public static function databaseSelected(string $id, string $queryId, string $name): void {
    if (self::queryList()->get($id, $queryId) === null) {
      return;
    }
    self::queryList()->updateDatabase($id, $queryId, $name);
    if (!isset(self::$states[$id]) || self::$states[$id]['queryId'] !== $queryId) {
      return;
    }
    self::$states[$id]['connection']['database'] = $name;
    if (self::$activeId === $id && self::$window->currentScreenId() === 'main-mysql') {
      self::render(self::screen(), self::$states[$id]);
      self::layout(self::screen());
    }
  }

  /** Align the menu worker with a saved query's database after switching tabs. */
  private static function syncQueryDatabase(string $id, string $queryId, string $database): void {
    try {
      SessionController::selectDatabase($id, $database, static function(array $response) use ($id, $queryId, $database): void {
        if (!($response['ok'] ?? false) && self::$activeId === $id
          && self::$states[$id]['queryId'] === $queryId
          && self::$states[$id]['connection']['database'] === $database) {
          self::screen()->statusBar->error((string)($response['error'] ?? 'Could not select database.'));
        }
      });
    } catch (\Throwable $error) {
      self::screen()->statusBar->error($error->getMessage());
    }
  }

  /** Put a selected table query in the editor and run it. */
  public static function selectTable(string $name): void {
    $state = &self::activeState();
    $identifier = '`' . str_replace('`', '``', $name) . '`';
    $state['query'] = 'SELECT * FROM ' . $identifier . ' LIMIT 100';
    $state['editorOpen'] = true;
    self::editor(self::screen())->setValue($state['query']);
    self::runQuery(new EventContext('activate'));
    self::layout(self::screen(), 'query-editor');
  }

  /** Return the screen currently hosting a connection main view. */
  private static function screen(): Screen {
    return self::$window->screen(self::screenId(self::$states[self::$activeId]['connection']['engine']));
  }

  /** Return a mutable reference to the active connection's UI state. */
  private static function &activeState(): array {
    if (self::$activeId === null) {
      throw new \LogicException('No connection main screen is active.');
    }
    return self::$states[self::$activeId];
  }

  /** Keep editor changes when a connection or panel is left. */
  private static function rememberQuery(): void {
    if (self::$activeId !== null) {
      $state = &self::$states[self::$activeId];
      $state['query'] = self::editor(self::screen())->getValue();
      self::queryList()->updateText(self::$activeId, $state['queryId'], $state['query']);
    }
  }

  /** Reveal the right panel and activate one of its widgets. */
  private static function focusQueryTile(string $tileId): void {
    self::closeMenu();
    $state = &self::activeState();
    $state['listVisible'] = true;
    $screen = self::screen();
    self::layout($screen, $tileId);
    $leaf = $screen->layout->findTile($tileId);
    if ($leaf instanceof LayoutLeaf) {
      $screen->activateLeaf($leaf);
      self::$window->refreshLayout();
    }
  }

  /** Pick the XML main screen belonging to an engine. */
  private static function screenId(string $engine): string {
    return match ($engine) {
      'MySQL' => 'main-mysql',
      'SQLite' => 'main-sqlite',
      'MongoDB' => 'main-mongodb',
      default => throw new \InvalidArgumentException('Unsupported main screen engine.'),
    };
  }

  /** Make initial panel settings for one saved connection. */
  private static function initialState(array $connection): array {
    return ['connection' => $connection, 'queryId' => null, 'queryViews' => [], 'query' => '', 'title' => 'NEW',
      'stat' => 'No query run', 'result' => null, 'latestKey' => null,
      'menuVisible' => true, 'listVisible' => true, 'editorOpen' => true, 'resultOpen' => true];
  }

  /** Return the engine's current database label for the one-row header. */
  private static function database(array $connection): string {
    return match ($connection['engine']) {
      'SQLite' => basename($connection['path'] ?? ''),
      'MongoDB' => ($connection['database'] ?? '') ?: 'admin',
      default => ($connection['database'] ?? '') ?: '(none selected)',
    };
  }

  /** Derive a short title from the first nonempty query line. */
  private static function title(string $query): string {
    $first = trim(explode("\n", trim($query), 2)[0]);
    return mb_strimwidth($first, 0, 48, '…');
  }

  /** Return the query widget on an engine's main screen. */
  private static function editor(Screen $screen): TextEditor {
    return $screen->widget('query-editor');
  }

  /** Populate the header, compact query, summary, and engine result widget. */
  private static function render(Screen $screen, array $state): void {
    $connection = $state['connection'];
    $screen->widget('main-header')->setText($connection['name'] . '  |  Database: ' . self::database($connection) . '  |  ' . $state['title'] . '  |  F9 Run');
    $screen->widget('compact-query')->setText(str_replace(["\n", "\r"], ' ', $state['query']) ?: '(empty query)');
    $screen->widget('query-stat')->setText($state['stat']);
    $screen->widget('result-stat')->setText($state['title'] . "\n" . $state['stat']);
    if ($state['result'] !== null) {
      if ($connection['engine'] === 'MongoDB') {
        MongoScreen::render($screen, $state['result']);
      } else {
        MainResult::render($screen, $state['result']);
      }
    }
  }

  /** Resize panels while keeping their XML tiles and engine widgets. */
  private static function layout(Screen $screen, ?string $focusId = null): void {
    $state = self::$states[self::$activeId];
    $root = $screen->layout;
    $root->findNode('menu-panel')->setWidth($state['menuVisible'] ? '22' : '0');
    $root->findNode('query-list-panel')->setWidth($state['listVisible'] ? '26' : '0');
    $root->findNode('query-panel')->setHeight($state['editorOpen'] ? '1*' : '2');
    $root->findNode('result-panel')->setHeight($state['result'] === null ? '0' : ($state['resultOpen'] ? '2*' : '2'));
    $root->findTile('query-editor')->setHeight($state['editorOpen'] ? '1*' : '0');
    $root->findTile('compact-query')->setHeight($state['editorOpen'] ? '0' : '1');
    $root->findTile('query-stat')->setHeight($state['editorOpen'] ? '0' : '1');
    $resultId = $state['connection']['engine'] === 'MongoDB' ? 'result-text' : 'result-table';
    $root->findTile($resultId)->setHeight($state['result'] !== null && $state['resultOpen'] ? '1*' : '0');
    $root->findTile('result-stat')->setHeight($state['result'] !== null && !$state['resultOpen'] ? '2' : '0');
    self::$window->refreshLayout();
    $leaf = $focusId === null ? null : $root->findTile($focusId);
    if ($leaf instanceof LayoutLeaf && $leaf->grid()->width > 0 && $leaf->grid()->height > 0) {
      $screen->selectLeaf($leaf);
      self::$window->refreshLayout();
    }
  }

  /** Show this connection's saved queries and selected tab. */
  private static function showQueries(Screen $screen, string $id): void {
    $list = $screen->widget('query-list');
    $filter = $list->filter();
    $items = [];
    foreach (self::queryList()->entries($id) as $entry) {
      $items[] = ['value' => $entry['id'], 'label' => $entry['name'],
        'selected' => (bool)($entry['pinned'] ?? false)];
    }
    $list->setItems($items);
    $list->setFilter($filter);
    $activeId = self::queryList()->activeId($id);
    if ($activeId !== null) {
      try {
        $list->setCursorValue($activeId);
      } catch (\InvalidArgumentException $error) {
        $list->setFilter('');
        $list->setCursorValue($activeId);
      }
    }
  }

  /** Accept a user query result for the tab that submitted it. */
  private static function receive(string $id, string $queryId, string $key, array $response): void {
    if (!isset(self::$states[$id]) || (self::$states[$id]['queryViews'][$queryId]['latestKey'] ?? null) !== $key) {
      return;
    }
    self::queryList()->status($id, $queryId, ($response['ok'] ?? false) ? 'ok' : 'error');
    if (self::$states[$id]['queryId'] !== $queryId) {
      $view = self::$states[$id]['queryViews'][$queryId];
      self::applyResult($view, self::$states[$id]['connection'], $response);
      self::$states[$id]['queryViews'][$queryId] = $view;
      return;
    }
    self::finish($id, $response);
  }

  /** Accept metadata output from the app worker. */
  private static function receiveApp(string $id, string $key, array $response): void {
    if (isset(self::$states[$id]) && self::$states[$id]['latestKey'] === $key) {
      self::finish($id, $response);
    }
  }

  /** Update one result pane and the active screen when the worker finishes. */
  private static function finish(string $id, array $response): void {
    $state = &self::$states[$id];
    self::applyResult($state, $state['connection'], $response);
    $state['queryViews'][$state['queryId']] = self::viewState($state);
    if (self::$activeId !== $id) {
      return;
    }
    $screen = self::screen();
    self::render($screen, $state);
    self::showQueries($screen, $id);
    self::layout($screen);
    if (!($response['ok'] ?? false)) {
      $screen->statusBar->error((string)($response['error'] ?? 'Query failed.'));
    }
  }

  /** Extract result fields that belong to one query tab. */
  private static function viewState(array $state): array {
    return array_intersect_key($state, array_flip(['stat', 'result', 'latestKey', 'resultOpen']));
  }

  /** Give a new tab an empty result pane. */
  private static function emptyView(): array {
    return ['stat' => 'No query run', 'result' => null, 'latestKey' => null, 'resultOpen' => true];
  }

  /** Convert a worker response into a result and summary. */
  private static function applyResult(array &$state, array $connection, array $response): void {
    if ($response['ok'] ?? false) {
      $state['result'] = $response['result'];
      $state['resultOpen'] = ($response['result']['columns'] ?? []) !== [];
      $rows = count($response['result']['rows'] ?? []);
      $affected = $response['result']['affectedRows'] ?? null;
      $count = $rows . (($response['result']['truncated'] ?? false) ? '+' : '');
      $summary = $connection['engine'] === 'MongoDB' ? $count . ' documents'
        : ($state['resultOpen'] ? $count . ' rows' : ($affected ?? 0) . ' rows affected');
      $state['stat'] = $summary . ' · ' . ($response['result']['elapsedMs'] ?? 0) . ' ms';
    } else {
      $state['stat'] = 'Query failed';
    }
  }

}
