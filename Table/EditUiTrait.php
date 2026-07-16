<?php

namespace MADB\Table;

/**
 * Locates table editor panel widgets and provides shared UI helpers for list items, placeholders, and context validation.
 */
trait EditUiTrait {

  /** Coordinates schema label work in the table editor. */
  private static function schemaLabel() {
    $labels = \MADB\Connection\MenuController::getMenuLabels();
    return strtolower($labels['schema']);
  }

  /** Coordinates table label work in the table editor. */
  private static function tableLabel() {
    $labels = \MADB\Connection\MenuController::getMenuLabels();
    return strtolower($labels['table']);
  }

  /** Coordinates panel work in the table editor. */
  private static function panel() {
    return \SPTK\Element::byName('table-editor');
  }

  /** Coordinates tabs work in the table editor. */
  private static function tabs() {
    return \SPTK\Element::byName('table-editor-tabs');
  }

  /** Coordinates add button work in the table editor. */
  private static function addButton() {
    return \SPTK\Element::byName('table-editor-add', self::panel());
  }

  /** Removes button from the table editor. */
  private static function deleteButton() {
    return \SPTK\Element::byName('table-editor-delete', self::panel());
  }

  /** Coordinates add space work in the table editor. */
  private static function addSpace() {
    return \SPTK\Element::byName('table-editor-add-space', self::panel());
  }

  /** Removes space from the table editor. */
  private static function deleteSpace() {
    return \SPTK\Element::byName('table-editor-delete-space', self::panel());
  }

  /** Coordinates list element work in the table editor. */
  private static function listElement($name) {
    return \SPTK\Element::byName($name, self::panel());
  }

  /** Coordinates item panel work in the table editor. */
  private static function itemPanel($name) {
    return \SPTK\Element::byName($name);
  }

  /** Coordinates show item panel work in the table editor. */
  private static function showItemPanel($panel, $inputName) {
    $panel->show();
    $panel->activateInput($inputName);
    \SPTK\Element::refresh();
  }

  /** Selects schema and refreshes related table editor state. */
  private static function selectedSchema() {
    $schema = \MADB\Table\MenuController::getCurrentSchema();
    return $schema === '' ? false : $schema;
  }

  /** Selects table and refreshes related table editor state. */
  private static function selectedTable() {
    $table = \MADB\Table\MenuController::getCurrentTable();
    return $table === '' ? false : $table;
  }

  /** Coordinates current connection work in the table editor. */
  private static function currentConnection() {
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    return $connectionList->current;
  }

  /** Coordinates validate context work in the table editor. */
  private static function validateContext($requiresTable) {
    if (self::currentConnection() === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return false;
    }
    if (self::selectedSchema() === false) {
      \SPTK\Elements\WarningPanel::forge('No ' . self::schemaLabel() . ' selected!', 'Please select a ' . self::schemaLabel() . ' before preforming this operation.');
      return false;
    }
    if ($requiresTable && self::selectedTable() === false) {
      \SPTK\Elements\WarningPanel::forge('No ' . self::tableLabel() . ' selected!', 'Please select a ' . self::tableLabel() . ' before preforming this operation.');
      return false;
    }
    return true;
  }

  /** Coordinates reset lists work in the table editor. */
  private static function resetLists($message = 'No metadata loaded') {
    foreach ([
      'table-editor-columns',
      'table-editor-indexes',
      'table-editor-foreign-keys',
      'table-editor-triggers'
    ] as $name) {
      self::setPlaceholder($name, $message === null ? self::emptyListMessage($name) : $message);
    }
  }

  /** Coordinates empty list message work in the table editor. */
  private static function emptyListMessage($listName) {
    $messages = [
      'table-editor-columns' => 'No fields defined yet.',
      'table-editor-indexes' => 'No indices defined yet.',
      'table-editor-foreign-keys' => 'No foreign keys defined yet.',
      'table-editor-triggers' => 'No triggers defined yet.'
    ];
    return $messages[$listName] ?? 'No items defined yet.';
  }

  /** Applies empty list placeholder values to table editor state or controls. */
  private static function setEmptyListPlaceholder($listName) {
    self::setPlaceholder($listName, self::emptyListMessage($listName));
  }

  /** Applies placeholder values to table editor state or controls. */
  private static function setPlaceholder($listName, $message) {
    $list = self::listElement($listName);
    if ($list === false) {
      return;
    }
    $list->clear();
    $list->addItem(['text' => $message]);
  }

  /** Coordinates make item key work in the table editor. */
  private static function makeItemKey($parts) {
    return implode("\t", array_map('strval', $parts));
  }

  /** Splits item key data for the table editor. */
  private static function splitItemKey($key) {
    return explode("\t", (string) $key);
  }

  /** Coordinates add list item work in the table editor. */
  private static function addListItem($list, $text, $right = false) {
    $list->addItem([
      'text' => $text,
      'right' => ($right !== false && $right !== '') ? $right : ''
    ]);
  }

  /** Formats compact list columns for grid-backed list rows. */
  private static function listColumns(array $values) {
    return array_map(fn($value) => self::textValue($value), $values);
  }

  /** Checks has named columns for table editor decisions. */
  private static function hasNamedColumns() {
    return self::firstNamedColumn() !== '';
  }

  /** Coordinates first named column work in the table editor. */
  private static function firstNamedColumn() {
    foreach (self::$columns as $column) {
      $name = trim((string) ($column['COLUMN_NAME'] ?? ''));
      if ($name !== '') {
        return $name;
      }
    }
    return '';
  }

  /** Coordinates warn no columns for work in the table editor. */
  private static function warnNoColumnsFor($itemType) {
    \SPTK\Elements\WarningPanel::forge(
      'No fields defined',
      'Please add at least one field before adding ' . $itemType . '.'
    );
  }

}
