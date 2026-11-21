<?php

namespace MADB\App;

use MADB\Connection\Store;
use SPTK\App;
use SPTK\Core\{Style, Window};
use SPTK\Events\{EventContext, EventDefinition, KeyNormalizer};
use SPTK\Layout\{LayoutLeaf, LayoutNode, LayoutSeparator};
use SPTK\SDLWrapper\SDL;
use SPTK\Widgets\Button\Button;
use SPTK\Widgets\FileSelector\FileSelector;
use SPTK\Widgets\Input\Input;
use SPTK\Widgets\Text\Text;

/** Connect the persisted connection board to SPTK screens and shortcuts. */
final class Controller {

  private const FIELDS = [
    'MySQL' => ['name', 'hotkey', 'host', 'port', 'database', 'username', 'password', 'timeout', 'sslCA', 'sslCert', 'sslKey', 'sslCipher', 'initCommand'],
    'SQLite' => ['name', 'hotkey', 'path', 'timeout', 'initCommand'],
    'MongoDB' => ['name', 'hotkey', 'host', 'port', 'database', 'authDatabase', 'username', 'password', 'timeout', 'tls', 'options'],
  ];
  private const DEFAULTS = [
    'MySQL' => ['port' => '3306', 'timeout' => '10'],
    'SQLite' => ['timeout' => '60'],
    'MongoDB' => ['host' => 'localhost', 'port' => '27017', 'timeout' => '10', 'tls' => 'no'],
  ];
  private const PATH_SELECTORS = [
    'sqlite-path' => 'sqlite-files',
    'mysql-sslCA' => 'mysql-files',
    'mysql-sslCert' => 'mysql-files',
    'mysql-sslKey' => 'mysql-files',
  ];

  private static Window $window;
  private static Store $store;
  private static LayoutNode $boardBody;
  private static array $cardLeaves = [];
  private static array $headerLeaves = [];
  private static bool $editingColumns = false;
  private static int $editingColumnIndex = 0;
  private static ?string $editingId = null;
  private static ?string $openedId = null;
  private static string $editingEngine = '';
  private static ?string $browsingInputId = null;
  private static string $browsingOriginalValue = '';

  /** Load the board after SPTK creates the window. */
  public static function initialize(EventContext $event): void {
    self::$window = array_values(App::eventLoop()->windows())[0];
    self::collapseEmptyStatusBars();
    self::$store = new Store();
    self::$boardBody = self::$window->screen('connections')->layout->findNode('board-body');
    self::renderBoard();
  }

  /** Give screen content the status row until a message needs it. */
  private static function collapseEmptyStatusBars(): void {
    foreach (self::$window->screens() as $screen) {
      $status = $screen->statusBar;
      $leaf = $screen->layout->findTile('status');
      if ($status === null || !$leaf instanceof LayoutLeaf || $leaf->instance() !== $status) {
        continue;
      }
      $leaf->setHeight($status->text() === '' ? '0' : '1');
      $status->on('change', static function() use ($screen, $status, $leaf): void {
        $height = $status->text() === '' ? '0' : '1';
        if ($leaf->height() === $height) {
          return;
        }
        $leaf->setHeight($height);
        if (self::$window->currentScreenId() === $screen->id) {
          self::$window->refreshLayout();
        }
      });
    }
  }

  /** Open the engine picker for a new connection. */
  public static function addConnection(EventContext $event): bool {
    self::$editingId = null;
    self::$window->setCurrentScreenId('engine');
    $screen = self::$window->screen('engine');
    $firstEngine = $screen->layout->findTile('engine-mysql');
    if ($firstEngine instanceof LayoutLeaf) {
      $screen->selectLeaf($firstEngine);
      self::$window->refreshLayout();
    }
    return true;
  }

  /** Show the column list input above the selected column title. */
  public static function editColumns(EventContext $event): bool {
    if (!$event->widget instanceof Button || !str_starts_with($event->widget->id() ?? '', 'board-column-')) {
      return false;
    }
    self::$editingColumnIndex = (int)substr($event->widget->id(), strlen('board-column-'));
    self::$editingColumns = true;
    self::renderBoard();
    return true;
  }

  /** Validate and persist comma-separated column labels. */
  private static function saveColumns(): void {
    $names = array_map('trim', explode(',', self::input('connections', 'column-names')->getValue()));
    if (count($names) > 12 || in_array('', $names, true) || count(array_unique(array_map('mb_strtolower', $names))) !== count($names)) {
      self::error('Enter 1–12 distinct, nonempty column names.');
      return;
    }
    self::$window->screen('connections')->release('cancel');
    self::$store->setColumns($names);
    self::$editingColumns = false;
    self::renderBoard(null, self::$editingColumnIndex);
  }

  /** Discard the inline column edit. */
  private static function cancelColumns(): void {
    self::$window->screen('connections')->release('cancel');
    self::$editingColumns = false;
    self::renderBoard(null, self::$editingColumnIndex);
  }

  /** Open the MySQL form. */
  public static function chooseMySQL(EventContext $event): bool {
    self::showEditor('MySQL');
    return true;
  }

  /** Open the SQLite form. */
  public static function chooseSQLite(EventContext $event): bool {
    self::showEditor('SQLite');
    return true;
  }

  /** Open the MongoDB form. */
  public static function chooseMongoDB(EventContext $event): bool {
    self::showEditor('MongoDB');
    return true;
  }

  /** Explain that an engine's editor has not been built yet. */
  public static function choosePlannedEngine(EventContext $event): bool {
    $engine = match ($event->widget?->id()) {
      'engine-postgresql' => 'PostgreSQL',
      'engine-redis' => 'Redis',
      'engine-memcache' => 'Memcache',
      'engine-solr' => 'Solr',
      default => null,
    };
    if ($engine === null) {
      return false;
    }
    self::$window->screen('engine')->statusBar->notice($engine . ' support is not available yet.', 'background');
    return true;
  }

  /** Fill the engine-specific form from an existing or new connection. */
  private static function showEditor(string $engine): void {
    SessionController::leaveEditor();
    self::$editingEngine = $engine;
    self::$browsingInputId = null;
    $data = self::$editingId === null ? [] : (self::$store->get(self::$editingId) ?? []);
    $screen = strtolower($engine);
    foreach (self::FIELDS[$engine] as $field) {
      $value = $data[$field] ?? self::DEFAULTS[$engine][$field] ?? '';
      self::input($screen, $screen . '-' . $field)->setValue((string)$value);
    }
    self::$window->setCurrentScreenId($screen);
    $nameLeaf = self::$window->screen($screen)->layout->findTile($screen . '-name');
    if ($nameLeaf instanceof LayoutLeaf) {
      self::$window->screen($screen)->selectLeaf($nameLeaf);
      self::$window->refreshLayout();
    }
  }

  /** Replace a path field's form area with a file selector. */
  public static function browsePath(EventContext $event): bool {
    if (!$event->widget instanceof Input) {
      return false;
    }
    $inputId = $event->widget->id();
    $selectorId = self::PATH_SELECTORS[$inputId] ?? null;
    if ($selectorId === null) {
      return false;
    }
    $screen = self::$window->screen(self::$window->currentScreenId());
    $selector = $screen->widget($selectorId);
    if (!$selector instanceof FileSelector) {
      return false;
    }
    $original = $event->widget->getValue();
    $expanded = str_starts_with($original, '~/') ? (getenv('HOME') ?: '') . substr($original, 1) : $original;
    $resolved = $expanded === '' ? false : realpath($expanded);
    $directory = $resolved !== false
      ? (is_dir($resolved) ? $resolved : dirname($resolved))
      : (realpath(dirname($expanded === '' ? '.' : $expanded)) ?: (getcwd() ?: '/'));
    try {
      $selector->setPath($directory);
    } catch (\RuntimeException $error) {
      self::error($error->getMessage());
      return true;
    }
    if ($resolved !== false && is_file($resolved) && in_array($resolved, $selector->values(), true)) {
      $selector->setValue($resolved);
    }
    self::$browsingInputId = $inputId;
    self::$browsingOriginalValue = $original;
    $screen->show($selectorId);
    return true;
  }

  /** Accept the file under the selector cursor on Return. */
  public static function acceptPath(EventContext $event): bool {
    $selector = self::$window->screen(self::$window->currentScreenId())->activeLeaf()?->instance();
    if (!$selector instanceof FileSelector || self::$browsingInputId === null) {
      return false;
    }
    $path = $selector->activeValue();
    if ($path !== null && is_file($path)) {
      if (!mb_check_encoding($path, 'UTF-8')) {
        self::error('Selected file path is not valid UTF-8.');
        return true;
      }
      self::restorePath($path);
    }
    return true;
  }

  /** Put back the path input and optionally replace its value. */
  private static function restorePath(string $value): void {
    $screen = self::$window->screen(self::$window->currentScreenId());
    $selector = $screen->activeLeaf()?->instance();
    $inputId = self::$browsingInputId;
    if (!$selector instanceof FileSelector || $inputId === null) {
      return;
    }
    $screen->restore($selector->id());
    self::$browsingInputId = null;
    self::input($screen->id, $inputId)->setValue($value);
  }

  /** Clear the selected form input with Delete. */
  public static function clearInput(EventContext $event): bool {
    if (!$event->widget instanceof Input) {
      return false;
    }
    $event->widget->setValue('');
    return true;
  }

  /** Leave an active input on Escape; otherwise close the connection editor. */
  public static function cancelEditor(EventContext $event): bool {
    $active = self::$window->screen(self::$window->currentScreenId())->activeLeaf()?->instance();
    if ($active instanceof FileSelector) {
      self::restorePath(self::$browsingOriginalValue);
      return true;
    }
    if ($active instanceof Input) {
      return false;
    }
    return self::cancel($event);
  }

  /** Save the current engine form after checking required fields. */
  public static function saveConnection(EventContext $event): bool {
    if (self::$window->screen(self::$window->currentScreenId())->activeLeaf()?->instance() instanceof FileSelector) {
      return true;
    }
    $data = self::editorData();
    $data['hotkey'] = strtolower($data['hotkey']);
    if ($data['name'] === '' || preg_match('/[\x00-\x1f\x7f]/', $data['name'])) {
      self::error('Enter a connection name.');
      return true;
    }
    if ($data['hotkey'] !== '' && !preg_match('/^[a-z0-9]$/D', $data['hotkey'])) {
      self::error('Hotkey must be one letter or number.');
      return true;
    }
    foreach (self::$store->connections() as $connection) {
      if ($data['hotkey'] !== '' && ($connection['hotkey'] ?? '') === $data['hotkey'] && $connection['id'] !== self::$editingId) {
        self::error('That hotkey is already assigned to another connection.');
        return true;
      }
    }
    $error = self::engineError($data);
    if ($error !== null) {
      self::error($error);
      return true;
    }
    $id = self::$store->put($data, self::$editingId);
    if ($id === null) {
      self::error('A connection with that name already exists.');
      return true;
    }
    SessionController::close($id);
    MainController::invalidateConnection($id);
    self::showBoard($id);
    return true;
  }

  /** Test the current editor fields without saving them. */
  public static function testConnection(EventContext $event): bool {
    $screen = self::$window->currentScreenId();
    if (self::$window->screen($screen)->activeLeaf()?->instance() instanceof FileSelector) {
      return true;
    }
    $data = self::editorData();
    $error = self::engineError($data);
    if ($error !== null) {
      self::error($error);
      return true;
    }
    SessionController::testDraft($data, $screen);
    return true;
  }

  /** Read the current editor values without persisting a connection. */
  private static function editorData(): array {
    $engine = self::$editingEngine;
    $screen = strtolower($engine);
    $data = ['engine' => $engine];
    foreach (self::FIELDS[$engine] as $field) {
      $data[$field] = trim(self::input($screen, $screen . '-' . $field)->getValue());
    }
    return $data;
  }

  /** Validate fields needed to establish an engine connection. */
  private static function engineError(array $data): ?string {
    $engine = $data['engine'];
    if (($engine === 'MySQL' || $engine === 'MongoDB') && $data['host'] === ''
      || $engine === 'SQLite' && $data['path'] === '') {
      return 'Enter a host or path.';
    }
    if ($engine === 'MongoDB' && !in_array(strtolower($data['tls']), ['yes', 'no'], true)) {
      return 'TLS must be yes or no.';
    }
    if ($engine === 'MongoDB' && $data['options'] !== '' && (!str_starts_with($data['options'], '{') || !is_array(json_decode($data['options'], true)))) {
      return 'Driver options must be a JSON object.';
    }
    return null;
  }

  /** Open the selected card's engine-specific settings. */
  public static function editConnection(EventContext $event): bool {
    $id = self::selectedId();
    if ($id === null) {
      return false;
    }
    $data = self::$store->get($id);
    self::$editingId = $id;
    self::showEditor($data['engine']);
    return true;
  }

  /** Request confirmation before removing the selected card. */
  public static function deleteConnection(EventContext $event): bool {
    $id = self::selectedId();
    if ($id === null) {
      return false;
    }
    $name = self::$store->get($id)['name'];
    self::$window->screen('connections')->statusBar->confirm('Delete ' . $name . '?', static function() use ($id): void {
      SessionController::close($id);
      MainController::forget($id);
      self::$store->delete($id);
      self::renderBoard();
    }, static function(): void {
    });
    return true;
  }

  /** Move the selected card one column left. */
  public static function moveLeft(EventContext $event): bool {
    return self::move(-1, 0);
  }

  /** Move the selected card one column right. */
  public static function moveRight(EventContext $event): bool {
    return self::move(1, 0);
  }

  /** Move the selected card one position up. */
  public static function moveUp(EventContext $event): bool {
    return self::move(0, -1);
  }

  /** Move the selected card one position down. */
  public static function moveDown(EventContext $event): bool {
    return self::move(0, 1);
  }

  /** Persist a card move and keep the moved card selected. */
  private static function move(int $horizontal, int $vertical): bool {
    $id = self::selectedId();
    if ($id === null) {
      return false;
    }
    if (self::$store->move($id, $horizontal, $vertical)) {
      self::renderBoard($id);
    }
    return true;
  }

  /** Open the selected connection's engine main screen. */
  public static function openConnection(EventContext $event): void {
    if (!$event->widget instanceof ConnectionCard) {
      return;
    }
    $data = self::$store->get($event->widget->connectionId());
    if ($data === null) {
      return;
    }
    self::showOpenedConnection($data);
  }

  /** Open the connection assigned to a typed board hotkey. */
  public static function openHotkey(EventContext $event): bool {
    if (self::$editingColumns || $event->input?->type !== SDL::SDL_EVENT_TEXT_INPUT) {
      return false;
    }
    $key = strtolower(\FFI::string($event->input->text->text));
    if (!preg_match('/^[a-z0-9]$/D', $key)) {
      return false;
    }
    foreach (self::$store->connections() as $connection) {
      if (($connection['hotkey'] ?? '') !== $key) {
        continue;
      }
      $leaf = self::$cardLeaves[$connection['id']] ?? null;
      if ($leaf instanceof LayoutLeaf) {
        self::$window->screen('connections')->selectLeaf($leaf);
      }
      self::showOpenedConnection($connection);
      return true;
    }
    return false;
  }

  /** Open the engine main screen for one saved connection. */
  private static function showOpenedConnection(array $data): void {
    self::$openedId = $data['id'];
    self::$editingId = null;
    MainController::open($data);
  }

  /** Let the shared main screen return to the board and focus its card. */
  public static function returnToBoard(?string $id): void {
    self::showBoard($id);
  }

  /** Return to the connection board. */
  public static function cancel(EventContext $event): bool {
    self::showBoard(self::$editingId ?? self::$openedId);
    return true;
  }

  /** Render the board and switch to it. */
  private static function showBoard(?string $id = null): void {
    SessionController::leaveEditor();
    self::renderBoard($id);
    self::$window->setCurrentScreenId('connections');
  }

  /** Replace the board columns and restore focus to a card or column title. */
  private static function renderBoard(?string $focusId = null, ?int $focusHeaderIndex = null): void {
    $screen = self::$window->screen('connections');
    $focusId ??= self::selectedId();
    $board = new LayoutNode('vertical', '1*', '1*');
    if (self::$editingColumns) {
      $row = new LayoutNode('horizontal', '1*', '1');
      $row->addLeaf(new LayoutLeaf('Text', '10', '1', new Text('Columns: '), [], false));
      $input = new Input(implode(', ', array_column(self::$store->columns(), 'name')));
      $input->setId('column-names');
      $input->setInputInterceptor(static function(mixed $event): bool {
        if ($event->type !== SDL::SDL_EVENT_KEY_DOWN) {
          return false;
        }
        $key = KeyNormalizer::normalize((int)$event->key->key, (int)$event->key->mod);
        if ($key === SDL::KEY_ESCAPE) {
          self::cancelColumns();
          return true;
        }
        if ($key === SDL::KEY_RETURN) {
          self::saveColumns();
          return true;
        }
        return false;
      });
      $row->addLeaf(new LayoutLeaf('Input', '1*', '1', $input));
      $board->addNode($row);
    }
    $columns = new LayoutNode('horizontal', '1*', '1*');
    self::$cardLeaves = [];
    self::$headerLeaves = [];
    foreach (self::$store->columns() as $index => $column) {
      $stack = new LayoutNode('vertical', '1*', '1*');
      $stack->setMinSize(24);
      $cards = array_values(array_filter(self::$store->connections(), fn(array $connection): bool => ($connection['column'] ?? '') === $column['id']));
      $header = new Button($column['name'] . ' (' . count($cards) . ')', null, self::class . '::editColumns', new Style());
      $header->setId('board-column-' . $index);
      $headerLeaf = new LayoutLeaf('Button', '1*', '1', $header);
      $stack->addLeaf($headerLeaf);
      self::$headerLeaves[$index] = $headerLeaf;
      $stack->addSeparator(new LayoutSeparator());
      foreach ($cards as $connection) {
        $card = new ConnectionCard($connection);
        $leaf = new LayoutLeaf('ConnectionCard', '1*', '3', $card, [new EventDefinition('activate', null, self::class . '::openConnection')]);
        $stack->addLeaf($leaf);
        $stack->addSeparator(new LayoutSeparator());
        self::$cardLeaves[$connection['id']] = $leaf;
      }
      $empty = new Text($cards === [] ? 'No connections' : '');
      $fill = new LayoutLeaf('Text', '1*', '1*', $empty, [], false);
      $fill->setMinSize(0);
      $stack->addLeaf($fill);
      if ($index > 0) {
        $columns->addSeparator(new LayoutSeparator());
      }
      $columns->addNode($stack);
    }
    $board->addNode($columns);
    if (!$screen->layout->replaceChild(self::$boardBody, $board)) {
      throw new \LogicException('Connection board layout is missing.');
    }
    self::$boardBody = $board;
    $screen->setLayout($screen->layout);
    if (self::$window->currentScreenId() === 'connections') {
      self::$window->refreshLayout();
    }
    if (self::$editingColumns) {
      $leaf = $screen->layout->findTile('column-names');
      if ($leaf instanceof LayoutLeaf) {
        $screen->activateLeaf($leaf);
        if (self::$window->currentScreenId() === 'connections') {
          self::$window->refreshLayout();
        }
      }
      return;
    }
    $leaf = $focusHeaderIndex === null ? (self::$cardLeaves[$focusId] ?? reset(self::$cardLeaves)) : (self::$headerLeaves[$focusHeaderIndex] ?? reset(self::$headerLeaves));
    if (!$leaf instanceof LayoutLeaf) {
      $leaf = reset(self::$headerLeaves);
    }
    if ($leaf instanceof LayoutLeaf) {
      $screen->selectLeaf($leaf);
      if (self::$window->currentScreenId() === 'connections') {
        self::$window->refreshLayout();
      }
    }
  }

  /** Return the selected board card's ID, if any. */
  private static function selectedId(): ?string {
    $widget = self::$window->screen('connections')->selectedLeaf()?->instance();
    return $widget instanceof ConnectionCard ? $widget->connectionId() : null;
  }

  /** Look up one form input by screen and widget ID. */
  private static function input(string $screen, string $id): Input {
    return self::$window->screen($screen)->widget($id);
  }

  /** Show a form or connection error in the current screen. */
  private static function error(string $message): void {
    self::$window->screen(self::$window->currentScreenId())->statusBar->error($message);
  }

}
