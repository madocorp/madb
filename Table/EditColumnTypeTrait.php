<?php

namespace MADB\Table;

/** Parses and rebuilds MySQL column type strings for the column editor controls. */
trait EditColumnTypeTrait {

  /** Coordinates parse column type work in the table editor. */
  private static function parseColumnType($columnType) {
    $columnType = trim((string) $columnType);
    $unsigned = stripos($columnType, ' unsigned') !== false;
    $zerofill = stripos($columnType, ' zerofill') !== false;
    $clean = trim(str_ireplace([' unsigned', ' zerofill'], '', $columnType));
    if (preg_match('/^([a-z0-9]+)(?:\((.*)\))?$/i', $clean, $matches)) {
      return [
        'type' => strtoupper($matches[1]),
        'parameter' => $matches[2] ?? '',
        'unsigned' => $unsigned,
        'zerofill' => $zerofill
      ];
    }
    return [
      'type' => strtoupper($clean),
      'parameter' => '',
      'unsigned' => $unsigned,
      'zerofill' => $zerofill
    ];
  }

  /** Coordinates build column type work in the table editor. */
  private static function buildColumnType($type, $parameter, $unsigned, $zerofill) {
    $type = strtoupper(trim((string) $type));
    $parameter = trim((string) $parameter);
    $columnType = $type;
    if ($parameter !== '') {
      $columnType .= '(' . $parameter . ')';
    }
    if ($unsigned) {
      $columnType .= ' unsigned';
    }
    if ($zerofill) {
      $columnType .= ' zerofill';
    }
    return $columnType;
  }

  /** Selects column type in list and refreshes related table editor state. */
  private static function selectColumnTypeInList($type) {
    $list = \SPTK\Element::byName('column-type', self::itemPanel('table-column-editor'));
    if ($list === false) {
      return;
    }
    foreach ($list->getItems() as $index => $item) {
      if ($item->getValue() === $type) {
        $list->moveCursor($index);
        return;
      }
    }
  }

}
