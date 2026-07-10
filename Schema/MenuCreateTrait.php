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
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Select a connection!');
    \MADB\Table\MenuController::reset(false);
    \SPTK\Element::refresh();
  }

  /** Shows the schema menu loading placeholder while schema list data is fetched. */
  public static function loading() {
    self::$currentSchema = false;
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Loading...');
    \MADB\Table\MenuController::reset(false);
    \SPTK\Element::refresh();
  }

  /** Shows the schema menu failure placeholder after schema list loading fails. */
  public static function loadFailed() {
    self::$currentSchema = false;
    $menuBox = \SPTK\Element::byName('menu-schema-list');
    $menuBox->clear();
    $menuItem = new \SPTK\Elements\MenuBoxItem($menuBox);
    $menuItem->setValue('Could not get the list.');
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
    $job = [
      'connection' => $connectionList->current,
      'command' => 'tableList',
      'arguments' => [$schema],
      'callback' => ['\MADB\Table\MenuController', 'setTables'],
      'schema' => $schema,
      'cache' => "TableList:{$schema}"
    ];
    \MADB\Table\MenuController::setCurrentSchema($schema);
    \MADB\Table\MenuController::loading();
    \MADB\Job\JobHandler::startJob($job);
  }

  /** Creates create data for the schema menu. */
  public static function create() {
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    if ($connectionList->current === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
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

  /** Saves create values from the schema menu panel or state. */
  public static function saveCreate($panel) {
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
    $panel->hide();
    $job = [
      'connection' => $connectionList->current,
      'command' => 'createSchema',
      'arguments' => [$schema],
      'callback' => ['\MADB\Schema\MenuController', 'created'],
      'schema' => $schema
    ];
    \MADB\Job\JobHandler::startJob($job);
    \SPTK\Element::refresh();
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

}
