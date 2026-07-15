<?php

namespace MADB\Table;

/** Handles the selected-table copy panel and builds generated copy SQL. */
trait MenuCopyTrait {

  /** Coordinates copy work in the table menu. */
  public static function copy() {
    if (self::$currentSchema === false) {
      \SPTK\Elements\WarningPanel::forge('No ' . self::schemaLabel() . ' selected!', 'Please select a ' . self::schemaLabel() . ' before preforming this operation.');
      return;
    }
    if (self::$currentTable === false) {
      \SPTK\Elements\WarningPanel::forge('No table selected!', 'Please select a table before preforming this operation.');
      return;
    }
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    if ($connectionList->current === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    $panel = \SPTK\Element::byName('table-copy');
    if ($panel === false) {
      return;
    }
    $schema = \SPTK\Element::byName('table-copy-schema', $panel);
    if ($schema !== false) {
      $schema->setOptions(self::schemaOptions());
    }
    $panel->setValue([
      'table-copy-schema' => self::$currentSchema,
      'table-copy-table' => self::$currentTable
    ]);
    $panel->show();
    $panel->activateInput('table-copy-generate');
    \SPTK\Element::refresh();
  }

  /** Saves copy values from the table menu panel or state. */
  public static function saveCopy($panel) {
    $values = $panel->getValue();
    $targetSchema = trim(self::textValue($values['table-copy-schema'] ?? ''));
    $targetTable = trim(self::textValue($values['table-copy-table'] ?? ''));
    if ($targetSchema === '') {
      \SPTK\Elements\WarningPanel::forge('Missing target ' . self::schemaLabel(), 'Please select the target ' . self::schemaLabel() . '.');
      return;
    }
    if ($targetTable === '') {
      \SPTK\Elements\WarningPanel::forge('Missing target table', 'Please enter the target table name.');
      return;
    }
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    $connection = $connectionList->current;
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    $panel->hide();
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'tableFields',
      'arguments' => [self::$currentSchema, self::$currentTable],
      'callback' => ['\MADB\Table\MenuController', 'copied'],
      'schema' => self::$currentSchema,
      'table' => self::$currentTable,
      'targetSchema' => $targetSchema,
      'targetTable' => $targetTable,
      'cache' => 'TableFields:' . self::$currentSchema . ':' . self::$currentTable
    ]);
    \SPTK\Element::refresh();
  }

  /** Coordinates copied work in the table menu. */
  public static function copied($response) {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not inspect table', $response['result']);
      return;
    }
    $sourceSchema = $response['schema'];
    $sourceTable = $response['table'];
    $targetSchema = $response['targetSchema'];
    $targetTable = $response['targetTable'] ?? $sourceTable;
    $fields = $response['result'];
    $fieldList = self::formatFieldList($fields);
    $target = self::quoteQualifiedTable($targetSchema, $targetTable);
    $source = self::quoteQualifiedTable($sourceSchema, $sourceTable);
    $statements = ["CREATE TABLE {$target} LIKE {$source};"];
    if ($fieldList === '*') {
      $statements[] = "INSERT INTO {$target}\nSELECT *\nFROM {$source};";
    } else {
      $statements[] = "INSERT INTO {$target}\n  ({$fieldList})\nSELECT {$fieldList}\nFROM {$source};";
    }
    $sql = implode("\n\n", $statements);
    $name = 'COPY ' . $sourceSchema . '.' . $sourceTable . ' -> ' . $targetSchema . '.' . $targetTable;
    self::closeCopyPanel();
    \MADB\Main\GeneratedQueryController::open([
      'title' => 'Copy table',
      'name' => $name,
      'sql' => \MADB\Query\SqlFormatter::format($sql),
      'connection' => $response['connection'],
      'schema' => $targetSchema,
      'table' => $targetTable,
      'cacheKeys' => self::tableCacheKeys($targetSchema, [$targetTable]),
      'refresh' => 'tables'
    ]);
  }

  /** Hides the copy panel before showing generated SQL. */
  private static function closeCopyPanel(): void {
    $panel = \SPTK\Element::byName('table-copy');
    if ($panel !== false) {
      $panel->hide();
    }
  }

}
