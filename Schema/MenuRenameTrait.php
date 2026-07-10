<?php

namespace MADB\Schema;

/** Handles the schema rename panel and confirmation flow, including MySQL migration-style rename details. */
trait MenuRenameTrait {

  /** Coordinates rename work in the schema menu. */
  public static function rename() {
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    if ($connectionList->current === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    if (self::$currentSchema === false) {
      \SPTK\Elements\WarningPanel::forge('No ' . self::schemaLabel() . ' selected!', 'Please select a ' . self::schemaLabel() . ' before preforming this operation.');
      return;
    }
    $panel = \SPTK\Element::byName('schema-rename');
    $title = \SPTK\Element::firstByType('PanelTitle', $panel);
    if ($title !== false) {
      $title->setText('Rename ' . self::schemaLabel());
    }
    $panel->setValue(['name' => self::$currentSchema]);
    $panel->show();
    \SPTK\Element::refresh();
  }

  /** Closes the rename panel in the schema menu. */
  public static function closeRename($panel) {
    $panel->hide();
    \SPTK\Element::refresh();
  }

  /** Saves rename values from the schema menu panel or state. */
  public static function saveRename($panel) {
    $values = $panel->getValue();
    $targetSchema = trim($values['name'] ?? '');
    if ($targetSchema === '') {
      \SPTK\Elements\WarningPanel::forge('Missing name', 'Please enter the new name before renaming the ' . self::schemaLabel() . '.');
      return;
    }
    if (self::$currentSchema === false) {
      \SPTK\Elements\WarningPanel::forge('No ' . self::schemaLabel() . ' selected!', 'Please select a ' . self::schemaLabel() . ' before preforming this operation.');
      return;
    }
    if ($targetSchema === self::$currentSchema) {
      $panel->hide();
      \SPTK\Element::refresh();
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
      'command' => 'renameSchemaInfo',
      'arguments' => [self::$currentSchema, $targetSchema],
      'callback' => ['\MADB\Schema\MenuController', 'confirmRename'],
      'schema' => self::$currentSchema,
      'targetSchema' => $targetSchema
    ];
    \MADB\Job\JobHandler::startJob($job);
    \SPTK\Element::refresh();
  }

  /** Opens or handles the rename confirmation step in the schema menu. */
  public static function confirmRename($response) {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not inspect ' . self::schemaLabel(), $response['result']);
      return;
    }
    $schema = $response['schema'];
    $targetSchema = $response['targetSchema'];
    $info = $response['result'];
    if (!empty($info['targetExists'])) {
      \SPTK\Elements\WarningPanel::forge('Target exists', "The target " . self::schemaLabel() . " '{$targetSchema}' already exists.");
      return;
    }
    self::$renameSchema = $schema;
    self::$renameTargetSchema = $targetSchema;
    $tables = $info['tables'] ?? 0;
    $views = $info['views'] ?? 0;
    $bytes = $info['bytes'] ?? 0;
    $foreignKeys = $info['foreignKeys'] ?? 0;
    $routines = $info['routines'] ?? 0;
    $events = $info['events'] ?? 0;
    $content = "MySQL does not have a native schema rename operation.\n";
    $content .= "The following migration-style actions will be performed.\n";
    $content .= "- New " . self::schemaLabel() . " '{$targetSchema}' will be created using the source charset and collation\n";
    $content .= "- {$tables} tables will be moved from '{$schema}' to '{$targetSchema}' with RENAME TABLE\n";
    $content .= "- {$views} views will be copied into '{$targetSchema}' and dropped later with '{$schema}'\n";
    $content .= "- Table triggers will be dropped before the move and recreated on the new " . self::schemaLabel() . "\n";
    $content .= "- {$foreignKeys} foreign keys should move with their tables\n";
    $content .= "- " . self::formatSize($bytes) . " table data and indexes will be moved\n";
    $content .= "- {$routines} procedures/functions will be recreated in the new " . self::schemaLabel() . "\n";
    $content .= "- The old " . self::schemaLabel() . " '{$schema}' will be dropped after the move\n";
    $content .= "- {$events} events are not moved separately and may be dropped with the old " . self::schemaLabel() . "\n";
    $content .= "- If any step fails part way through, MaDB will not roll this back automatically; you will need to repair the schemas manually\n";
    $content .= "- Cached schema and table lists for this connection will be cleared\n";
    $content .= "%CONFIRMATION%";
    \SPTK\Elements\WarningPanel::forge(
      'Rename ' . self::schemaLabel(),
      $content,
      [
        ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close'],
        ['text' => 'Rename', 'hotKey' => 'RETURN', 'onPress' => '\MADB\Schema\RenameController::doRename']
      ]
    );
  }

  /** Coordinates do rename work in the schema menu. */
  public static function doRename($confirmationPanel) {
    $values = $confirmationPanel->getValue();
    if (!isset($values['confirmed']) || $values['confirmed'] !== true) {
      return;
    }
    if (self::$renameSchema === false || self::$renameTargetSchema === false) {
      return;
    }
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    if ($connectionList->current === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    $schema = self::$renameSchema;
    $targetSchema = self::$renameTargetSchema;
    $confirmationPanel->remove();
    \MADB\Job\JobHandler::startJob([
      'connection' => $connectionList->current,
      'command' => 'renameSchema',
      'arguments' => [$schema, $targetSchema],
      'callback' => ['\MADB\Schema\MenuController', 'renamed'],
      'schema' => $schema,
      'targetSchema' => $targetSchema
    ]);
    \SPTK\Element::refresh();
  }

  /** Coordinates renamed work in the schema menu. */
  public static function renamed($response) {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not rename ' . self::schemaLabel(), $response['result'] . "\nManual cleanup may be required.");
      return;
    }
    \MADB\Job\Cache::clear($response['connection']['name'], 'SchemaList');
    \MADB\Job\Cache::clear($response['connection']['name'], 'TableList:' . $response['schema']);
    \MADB\Job\Cache::clear($response['connection']['name'], 'TableList:' . $response['targetSchema']);
    self::$currentSchema = false;
    self::$renameSchema = false;
    self::$renameTargetSchema = false;
    self::$selectAfterLoad = $response['targetSchema'];
    \MADB\Connection\MenuController::select($response['connection']['name']);
  }

  /** Formats size text for the schema menu. */
  private static function formatSize($bytes) {
    return sprintf('%.3f GB', $bytes / 1024 / 1024 / 1024);
  }

}
