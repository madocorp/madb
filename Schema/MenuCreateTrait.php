<?php

namespace MADB\Schema;

/** Loads, renders, and handles the schema create menu and create-schema panel. */
trait MenuCreateTrait {

  /** Coordinates schema label work in the schema menu. */
  private static function schemaLabel() {
    $labels = \MADB\Connection\MenuController::getMenuLabels();
    return strtolower($labels['schema']);
  }

  /** Clears schema menu selection and placeholder state. */
  public static function reset() {
    self::$currentSchema = false;
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    $menuBox->clear();
    $menuBox->addItem('Select a connection!');
    self::clearOperationsMenu();
    \MADB\Table\MenuController::reset(false);
    \SPTK\Element::refresh();
  }

  /** Shows the schema menu loading placeholder while schema list data is fetched. */
  public static function loading() {
    self::$currentSchema = false;
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    $menuBox->clear();
    $menuBox->addItem('Loading...');
    self::clearOperationsMenu();
    \MADB\Table\MenuController::reset(false);
    \SPTK\Element::refresh();
  }

  /** Shows the schema menu failure placeholder after schema list loading fails. */
  public static function loadFailed() {
    self::$currentSchema = false;
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    $menuBox->clear();
    $menuBox->addItem('Could not get the list.');
    self::clearOperationsMenu();
    \MADB\Table\MenuController::reset(false);
    \SPTK\Element::refresh();
  }

  /** Selects a schema from the schema menu and reloads the table menu. */
  public static function select($item) {
    if (is_string($item)) {
      $schema = $item;
    } else {
      $schema = $item->getValue();
    }
    self::$currentSchema = $schema;
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    if ($connectionList->current === false) {
      return;
    }
    if (!\MADB\Engine\EngineRegistry::usesSecondaryMenu($connectionList->current['engine'] ?? null)) {
      \MADB\Table\MenuController::setCurrentSchema($schema);
      \MADB\Table\MenuController::reset(false);
      $preserveFocus = self::shouldPreserveFocusAfterPrimarySelection($item);
      \MADB\Engine\EngineRegistry::workspace($connectionList->current['engine'] ?? null)->primarySelected($schema, $preserveFocus);
      \MADB\Table\MenuController::renderEngineSecondaryMenu();
      return;
    }
    $job = [
      'connection' => $connectionList->current,
      'command' => \MADB\Engine\EngineRegistry::secondaryListCommand($connectionList->current['engine'] ?? null),
      'arguments' => [$schema],
      'callback' => ['\MADB\Table\MenuController', 'setTables'],
      'schema' => $schema,
      'cache' => "TableList:{$schema}"
    ];
    \MADB\Table\MenuController::setCurrentSchema($schema);
    \MADB\Table\MenuController::loading();
    \MADB\Job\JobHandler::startJob($job);
  }

  /** Selects the given schema automatically after the next schema-list refresh. */
  public static function selectAfterLoad($schema): void {
    self::$selectAfterLoad = $schema;
  }

  /** Creates create data for the schema menu. */
  public static function create() {
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    if ($connectionList->current === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    if (!\MADB\Connection\MenuController::requireOperation('schemaCreate', 'Creating ' . self::schemaLabel(), $connectionList->current)) {
      return;
    }
    $panel = \SPTK\Element::byName('schema-create');
    $title = \SPTK\Element::firstByType('PanelTitle', $panel);
    if ($title !== false) {
      $title->setText('Create ' . self::schemaLabel());
    }
    $panel->setValue(['name' => '']);
    $panel->show();
    \SPTK\Element::refresh();
  }

  /** Closes the create panel in the schema menu. */
  public static function closeCreate($panel) {
    $panel->hide();
    \SPTK\Element::refresh();
  }

  /** Generates create-schema SQL from the schema menu panel. */
  public static function generateCreate($panel) {
    $values = $panel->getValue();
    $schema = trim($values['name'] ?? '');
    if ($schema === '') {
      \SPTK\Elements\WarningPanel::forge('Missing name', 'Please enter a name before creating the ' . self::schemaLabel() . '.');
      return;
    }
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    if ($connectionList->current === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    if (!\MADB\Connection\MenuController::requireOperation('schemaCreate', 'Creating ' . self::schemaLabel(), $connectionList->current)) {
      return;
    }
    $panel->hide();
    if (self::isGitConnection($connectionList->current)) {
      self::openGitBranchCreatePreview($connectionList->current, $schema);
      \SPTK\Element::refresh();
      return;
    }
    if (self::isSQLiteConnection($connectionList->current)) {
      \MADB\Query\GeneratedQueryController::open([
        'title' => 'Create ' . self::schemaLabel(),
        'name' => 'CREATE ' . $schema,
        'sql' => self::sqliteCreateSchemaPreviewSql($connectionList->current, $schema),
        'connection' => $connectionList->current,
        'schema' => $schema,
        'cacheKeys' => ['SchemaList'],
        'refresh' => 'schemas',
        'directCommand' => 'createSchema',
        'directArguments' => [$schema]
      ]);
      \SPTK\Element::refresh();
      return;
    }
    \MADB\Query\GeneratedQueryController::open([
      'title' => 'Create ' . self::schemaLabel(),
      'name' => 'CREATE ' . $schema,
      'sql' => 'CREATE SCHEMA ' . self::quoteIdentifier($schema) . ';',
      'connection' => $connectionList->current,
      'schema' => $schema,
      'cacheKeys' => ['SchemaList'],
      'refresh' => 'schemas'
    ]);
    \SPTK\Element::refresh();
  }

  /** Backward-compatible create callback name. */
  public static function saveCreate($panel) {
    self::generateCreate($panel);
  }

  /** Creates created data for the schema menu. */
  public static function created($response) {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not create ' . self::schemaLabel(), $response['result']);
      return;
    }
    \MADB\Job\Cache::clear($response['connection']['name'], 'SchemaList');
    self::$selectAfterLoad = $response['schema'];
    \MADB\Connection\MenuController::select($response['connection']['name']);
  }

  /** Escapes SQL identifiers used by generated schema SQL. */
  private static function quoteIdentifier($identifier) {
    return '`' . str_replace('`', '``', $identifier) . '`';
  }

  /** Returns whether the selected engine is SQLite. */
  private static function isSQLiteConnection($connection): bool {
    return strcasecmp((string)($connection['engine'] ?? ''), 'SQLite') === 0;
  }

  /** Returns whether the selected engine is Git. */
  private static function isGitConnection($connection): bool {
    return strcasecmp((string)($connection['engine'] ?? ''), 'Git') === 0;
  }

  /** Returns whether selecting a primary item should leave focus on the open menu. */
  private static function shouldPreserveFocusAfterPrimarySelection($item): bool {
    return \MADB\Main\ScreenController::hasOpenMenuBox();
  }

  /** Clears engine-provided primary operation menu items. */
  private static function clearOperationsMenu(): void {
    $menuBox = \SPTK\Element::byName('menu-schema-operations');
    if ($menuBox !== false) {
      $menuBox->clear();
    }
  }

  /** Builds SQLite preview SQL for attached database creation. */
  private static function sqliteCreateSchemaPreviewSql($connection, $schema): string {
    $path = self::sqliteSidecarPreviewPath($connection, $schema);
    return implode("\n", [
      '-- SQLite attached database create preview.',
      '-- MADB will create this sidecar file if it does not exist.',
      'ATTACH DATABASE ' . self::quoteSqlString($path) . ' AS ' . self::quoteSQLiteIdentifier($schema) . ';'
    ]);
  }

  /** Opens a generated command preview for creating a Git branch. */
  private static function openGitBranchCreatePreview(array $connection, string $branch): void {
    try {
      $className = \MADB\Engine\EngineRegistry::connectionClass($connection['engine'] ?? 'Git');
      $git = new $className($connection);
      $git->connect();
      $base = (string)(\MADB\Table\MenuController::getCurrentSchema() ?: '');
      $preview = $git->branchCreatePreview($branch, $base);
    } catch (\Exception $e) {
      \SPTK\Elements\ErrorPanel::forge('Could not inspect Git branch creation', $e->getMessage());
      return;
    }
    if (!empty($preview['localExists'])) {
      \SPTK\Elements\WarningPanel::forge('Branch exists', "Local branch '{$branch}' already exists.");
      return;
    }
    $sql = self::gitBranchCreatePreviewText($preview);
    \MADB\Query\GeneratedQueryController::open([
      'title' => 'Create branch',
      'name' => 'CREATE ' . $branch,
      'sql' => $sql,
      'connection' => $connection,
      'schema' => $branch,
      'cacheKeys' => ['SchemaList'],
      'refresh' => 'schemasThenTables',
      'directCommand' => 'createSchema',
      'directArguments' => [$branch, (string)($preview['base'] ?? '')],
      'confirmation' => [
        'title' => 'Create branch',
        'content' => self::gitBranchCreateConfirmationText($preview)
      ]
    ]);
  }

  /** Formats the generated preview text for Git branch creation. */
  private static function gitBranchCreatePreviewText(array $preview): string {
    $lines = [
      '# Git branch create preview',
      'Target branch: ' . ($preview['branch'] ?? ''),
      'Remote: ' . ($preview['remote'] ?? ''),
      'Remote branch: ' . ($preview['remoteBranch'] ?? ''),
      ''
    ];
    if (!empty($preview['fetchError'])) {
      $lines[] = '# Fetch failed: ' . $preview['fetchError'];
      $lines[] = '# MADB will use existing remote refs, if any, otherwise create from the selected branch.';
      $lines[] = '';
    }
    if (!empty($preview['remoteExists'])) {
      $lines[] = 'git fetch ' . ($preview['remote'] ?? '');
      $lines[] = 'git checkout -b ' . ($preview['branch'] ?? '') . ' --track ' . ($preview['remoteBranch'] ?? '');
      $lines[] = 'git pull --ff-only';
    } else {
      $lines[] = 'git fetch ' . ($preview['remote'] ?? '');
      $lines[] = 'git checkout -b ' . ($preview['branch'] ?? '') . ' ' . ($preview['base'] ?? '');
    }
    if (!empty($preview['dirty'])) {
      $lines[] = '';
      $lines[] = '# Local work is present. Git will decide whether checkout is safe.';
      foreach (array_slice($preview['dirtyLines'] ?? [], 0, 20) as $line) {
        $lines[] = '# ' . $line;
      }
      if (count($preview['dirtyLines'] ?? []) > 20) {
        $lines[] = '# ...';
      }
    }
    return implode("\n", $lines);
  }

  /** Formats the confirmation text for Git branch creation. */
  private static function gitBranchCreateConfirmationText(array $preview): string {
    $content = "The following actions will be performed.\n";
    $content .= "- Fetch remote '" . ($preview['remote'] ?? '') . "'\n";
    if (!empty($preview['fetchError'])) {
      $content .= "- Fetch failed during preview: " . $preview['fetchError'] . "\n";
    }
    if (!empty($preview['remoteExists'])) {
      $content .= "- Create local branch '" . ($preview['branch'] ?? '') . "' tracking '" . ($preview['remoteBranch'] ?? '') . "'\n";
      $content .= "- Pull with --ff-only\n";
    } else {
      $content .= "- Create local branch '" . ($preview['branch'] ?? '') . "' from selected branch '" . ($preview['base'] ?? '') . "'\n";
    }
    if (!empty($preview['dirty'])) {
      $content .= "- Local work exists; Git will decide whether this checkout is safe\n";
      foreach (array_slice($preview['dirtyLines'] ?? [], 0, 10) as $line) {
        $content .= "  " . $line . "\n";
      }
      if (count($preview['dirtyLines'] ?? []) > 10) {
        $content .= "  ...\n";
      }
    }
    $content .= "%CONFIRMATION%";
    return $content;
  }

  /** Returns the sidecar path shown in SQLite generated SQL previews. */
  private static function sqliteSidecarPreviewPath($connection, $schema): string {
    $mainPath = self::expandSQLitePreviewPath((string)($connection['path'] ?? ''));
    $path = pathinfo($mainPath);
    $dir = ($path['dirname'] ?? '') === '.' ? '' : ($path['dirname'] . DIRECTORY_SEPARATOR);
    $filename = $path['filename'] ?? basename($mainPath);
    $extension = $path['extension'] ?? '';
    $suffix = $extension === '' ? '' : '.' . $extension;
    return $dir . $filename . '.' . trim((string)$schema) . $suffix;
  }

  /** Expands a leading tilde for SQLite preview paths. */
  private static function expandSQLitePreviewPath($path): string {
    if ($path === '~' || strpos($path, '~/') === 0) {
      $home = getenv('HOME');
      if ($home !== false && $home !== '') {
        return $home . substr($path, 1);
      }
    }
    return $path;
  }

  /** Quotes SQLite identifiers used by preview SQL. */
  private static function quoteSQLiteIdentifier($identifier): string {
    return '"' . str_replace('"', '""', (string)$identifier) . '"';
  }

  /** Quotes SQL strings used by preview SQL. */
  private static function quoteSqlString($value): string {
    return "'" . str_replace("'", "''", (string)$value) . "'";
  }

}
