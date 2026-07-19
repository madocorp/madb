<?php

namespace MADB\Table;

/** Opens table editor tabs and loads table metadata, charset options, and editor list state into the panel. */
trait EditOpenLoadTrait {

  /** Applies title values to table editor state or controls. */
  private static function setTitle($title) {
    $panelTitle = \SPTK\Element::firstByType('PanelTitle', self::panel());
    if ($panelTitle !== false) {
      $panelTitle->setText($title);
    }
  }

  /** Coordinates current tab input name work in the table editor. */
  private static function currentTabInputName($contentName = false) {
    if ($contentName === false) {
      $tabs = self::tabs();
      $contentName = $tabs === false ? false : $tabs->getCurrentTabContentName();
    }
    $inputs = [
      'table-editor-main' => 'table-name',
      'table-editor-column' => 'table-editor-columns',
      'table-editor-index' => 'table-editor-indexes',
      'table-editor-foreign-key' => 'table-editor-foreign-keys',
      'table-editor-trigger' => 'table-editor-triggers'
    ];
    return $inputs[$contentName] ?? false;
  }

  /** Coordinates activate current tab input work in the table editor. */
  private static function activateCurrentTabInput($contentName = false) {
    $panel = self::panel();
    if ($panel === false || !$panel->isDisplayed()) {
      return;
    }
    $inputName = self::currentTabInputName($contentName);
    if ($inputName !== false) {
      $panel->activateInput($inputName);
    }
  }

  /** Opens the open panel or view in the table editor. */
  private static function open($tab, $requiresTable, $mode = null) {
    if (!self::validateContext($requiresTable)) {
      return;
    }
    $operation = $requiresTable ? 'tableModify' : 'tableCreate';
    if (!\MADB\Connection\MenuController::requireOperation($operation, $requiresTable ? 'Modifying tables' : 'Creating tables', self::currentConnection())) {
      return;
    }
    self::$mode = $mode ?? ($requiresTable ? 'edit' : 'create');
    self::$schema = self::selectedSchema();
    self::$table = $requiresTable ? self::selectedTable() : false;
    self::$definition = false;
    self::$columns = [];
    self::$indexes = [];
    self::$foreignKeys = [];
    self::$triggers = [];
    self::$editingItem = false;
    self::$addingItem = false;
    self::loadCharacterOptions();
    $panel = self::panel();
    $tabs = self::tabs();
    if ($panel === false || $tabs === false) {
      return;
    }
    $tabs->selectTab($tab);
    self::updateAddButton($tabs);
    if (self::$mode === 'create') {
      self::setTitle('Create table in ' . self::$schema);
      $panel->setValue([
        'table-name' => '',
        'table-charset' => '',
        'table-collation' => '',
        'table-engine' => '',
        'table-comment' => ''
      ]);
      self::resetLists(null);
      $panel->show();
      $panel->activateInput('table-name');
      \SPTK\Element::refresh();
      return;
    }

    $title = self::$mode === 'modify' ? 'Modify ' : 'Edit ';
    self::setTitle($title . self::$schema . '.' . self::$table);
    $panel->setValue([
      'table-name' => self::$table,
      'table-charset' => '',
      'table-collation' => '',
      'table-engine' => '',
      'table-comment' => ''
    ]);
    self::resetLists('Loading...');
    $panel->show();
    self::activateCurrentTabInput();
    \SPTK\Element::refresh();
    self::loadDefinition();
  }

  /** Loads definition data for the table editor. */
  private static function loadDefinition() {
    $connection = self::currentConnection();
    if ($connection === false || self::$schema === false || self::$table === false) {
      return;
    }
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'tableDefinition',
      'arguments' => [self::$schema, self::$table],
      'callback' => ['\MADB\Table\EditController', 'setDefinition'],
      'schema' => self::$schema,
      'table' => self::$table,
      'cache' => 'TableDefinition:' . self::$schema . ':' . self::$table
    ]);
  }

  /** Opens the create panel or view in the table editor. */
  public static function openCreate() {
    self::open(self::TAB_MAIN, false);
  }

  /** Opens the modify panel or view in the table editor. */
  public static function openModify() {
    self::open(self::TAB_MAIN, true, 'modify');
  }

  /** Opens the columns panel or view in the table editor. */
  public static function openColumns() {
    self::open(self::TAB_COLUMN, true);
  }

  /** Opens the indexes panel or view in the table editor. */
  public static function openIndexes() {
    self::open(self::TAB_INDEX, true);
  }

  /** Opens the foreign keys panel or view in the table editor. */
  public static function openForeignKeys() {
    self::open(self::TAB_FOREIGN_KEY, true);
  }

  /** Opens the triggers panel or view in the table editor. */
  public static function openTriggers() {
    self::open(self::TAB_TRIGGER, true);
  }

  /** Coordinates update add button work in the table editor. */
  public static function updateAddButton($tabs = null) {
    if ($tabs === null || $tabs === false) {
      $tabs = self::tabs();
    }
    $contentName = $tabs === false ? false : $tabs->getCurrentTabContentName();
    $inputName = self::currentTabInputName($contentName);
    $button = self::addButton();
    if ($button === false) {
      return $inputName;
    }
    $space = self::addSpace();
    $deleteButton = self::deleteButton();
    $deleteSpace = self::deleteSpace();
    if (in_array($contentName, [
      'table-editor-column',
      'table-editor-index',
      'table-editor-foreign-key',
      'table-editor-trigger'
    ])) {
      $button->show();
      if ($deleteButton !== false) {
        $deleteButton->show();
      }
      if ($space !== false) {
        $space->show();
      }
      if ($deleteSpace !== false) {
        $deleteSpace->show();
      }
    } else {
      $button->hide();
      if ($deleteButton !== false) {
        $deleteButton->hide();
      }
      if ($space !== false) {
        $space->hide();
      }
      if ($deleteSpace !== false) {
        $deleteSpace->hide();
      }
    }
    $panel = self::panel();
    if ($panel !== false && $panel->isDisplayed()) {
      $panel->refreshInputList($inputName);
      \SPTK\Element::refresh();
    }
    return $inputName;
  }

  /** Applies definition values to table editor state or controls. */
  public static function setDefinition($response) {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not inspect table', $response['result']);
      return;
    }
    $definition = $response['result'];
    self::$definition = $definition;
    self::$columns = $definition['columns'] ?? [];
    self::$indexes = $definition['indexes'] ?? [];
    self::$foreignKeys = $definition['foreignKeys'] ?? [];
    self::$triggers = $definition['triggers'] ?? [];
    self::syncColumnKeysFromIndexes();
    $table = $definition['table'] ?? [];
    self::panel()->setValue([
      'table-name' => $table['name'] ?? ($response['table'] ?? ''),
      'table-charset' => $table['charset'] ?? '',
      'table-collation' => $table['collation'] ?? '',
      'table-engine' => $table['engine'] ?? '',
      'table-comment' => $table['comment'] ?? ''
    ]);
    self::setColumns(self::$columns);
    self::setIndexes(self::$indexes);
    self::setForeignKeys(self::$foreignKeys);
    self::setTriggers(self::$triggers);
    \SPTK\Element::refresh();
  }

  /** Applies character options values to table editor state or controls. */
  public static function setCharacterOptions($response) {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not load character sets', $response['result']);
      return;
    }
    $result = $response['result'];
    self::$charsets = $result['charsets'] ?? [];
    self::$collations = $result['collations'] ?? [];
    self::$engines = $result['engines'] ?? [];
    self::$characterOptionsConnection = $response['connection']['name'] ?? false;
    self::applyCharacterOptions();
    \SPTK\Element::refresh();
  }

}
