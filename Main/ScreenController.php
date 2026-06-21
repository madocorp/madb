<?php

namespace MADB\Main;

use \SPTK\SDLWrapper\KeyCombo;
use \SPTK\SDLWrapper\Action;
use \SPTK\Element;

class ScreenController {

  const EDITOR = 0;
  const RESULT = 1;
  const LIST = 2;

  private static $activeBox = self::EDITOR;
  private static $editor;
  private static $title;
  private static $result;
  private static $list;
  private static $connectionInfo;
  private static $queryName;
  private static $queries = [];
  private static $currentQuery = false;

  public static function init() {
    self::$editor = Element::byName('query-editor');
    self::$title = Element::byName('query-title');
    self::$result = Element::byName('query-result');
    self::$list = Element::byName('query-list');
    self::$connectionInfo = Element::byName('connection-info');
    self::$queryName = Element::byName('query-name');
    self::$list->clear();
    self::$list->setOnChange('\MADB\Main\ScreenController::selectQueryFromList');
  }

  public static function addQuery($name, $sql, $connection, $schema, $table) {
    $index = count(self::$queries);
    self::$queries[] = [
      'name' => $name,
      'sql' => $sql,
      'connection' => $connection,
      'schema' => $schema,
      'table' => $table
    ];
    $menuItem = new \SPTK\Elements\ListItem(self::$list);
    $menuItem->setValue($index);
    $menuItem->setSelectable('queries');
    $menuItem->setText('#' . ($index + 1) . ' - ' . $name);
    self::$list->moveCursor($index);
    self::showQuery($index);
    self::deactivateList();
    self::activateEditor();
    Element::refresh();
  }

  public static function selectQueryFromList($list) {
    $index = $list->getValue();
    if ($index === false || !isset(self::$queries[$index])) {
      return;
    }
    self::showQuery($index);
  }

  private static function showQuery($index) {
    if (!isset(self::$queries[$index])) {
      return;
    }
    self::$currentQuery = $index;
    $query = self::$queries[$index];
    self::$connectionInfo->setText($query['connection'] . ' : ' . $query['schema'] . ' . ' . $query['table']);
    self::$queryName->setText('#' . ($index + 1) . ' - ' . $query['name']);
    self::$editor->setText($query['sql']);
  }

  public static function activateEditor() {
    self::$activeBox = self::EDITOR;
    self::$editor->addClass('active-box');
    self::$title->addClass('active-title');
  }

  public static function deactivateEditor() {
    self::$editor->removeClass('active-box');
    self::$title->removeClass('active-title');
  }

  public static function activateResult() {
    self::$activeBox = self::RESULT;
    self::$result->addClass('active-box');
  }

  public static function deactivateResult() {
    self::$result->removeClass('active-box');
  }

  public static function activateList() {
    self::$activeBox = self::LIST;
    self::$list->addClass('active-box');
  }

  public static function deactivateList() {
    self::$list->removeClass('active-box');
  }

  public static function keyPressHandler($element, $event) {
    switch (KeyCombo::resolve($event['mod'], $event['scancode'], $event['key'])) {
      case Action::SWITCH_NEXT:
      case Action::SWITCH_PREVIOUS:
      case Action::SWITCH_LEFT:
      case Action::SWITCH_RIGHT:
        if (self::$activeBox === self::LIST) {
          self::deactivateList();
          self::activateEditor();
        } else {
          self::deactivateEditor();
          self::activateList();
        }
        Element::refresh();
        return true;
    }
    return false;
  }

}
