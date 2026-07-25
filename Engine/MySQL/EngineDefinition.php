<?php

namespace MADB\Engine\MySQL;

class EngineDefinition implements \MADB\Engine\EngineDefinitionInterface {

  public static function id(): string {
    return 'MySQL';
  }

  public static function label(): string {
    return 'MySQL';
  }

  public static function connectionClass(): string {
    return \MADB\Engine\MySQL\Connection::class;
  }

  public static function connectionPanel(): string {
    return 'connection-editor-mysql';
  }

  public static function menuLabels(): array {
    return [
      'primary' => 'Schema',
      'secondary' => 'Table'
    ];
  }

  public static function primaryMenuItems(): array {
    return [
      ['text' => 'Create', 'onOpen' => 'MADB\Schema\CreateController::create'],
      ['text' => 'Rename', 'onOpen' => 'MADB\Schema\RenameController::rename'],
      ['text' => 'Drop', 'onOpen' => 'MADB\Schema\DropController::drop']
    ];
  }

  public static function secondaryCreateMenuItems(): array {
    return [
      ['text' => 'Table', 'onOpen' => 'MADB\Table\CreateController::openTableCreate'],
      ['text' => 'View', 'onOpen' => 'MADB\Table\CreateController::openViewCreate']
    ];
  }

  public static function secondaryItemMenuItems(): array {
    return [
      ['text' => 'Select', 'onOpen' => 'MADB\Table\RowsController::selectRows'],
      ['text' => 'ShowCreate', 'onOpen' => 'MADB\Table\ShowCreateController::showCreate'],
      ['text' => 'Modify', 'onOpen' => 'MADB\Table\MenuController::modify'],
      ['text' => 'Copy', 'onOpen' => 'MADB\Table\CopyController::copy'],
      ['text' => 'Drop', 'onOpen' => 'MADB\Table\MenuController::drop']
    ];
  }

  public static function language() {
    return new \MADB\Engine\SqlLanguage('MySQL', [
      'SELECT current' => 'SELECT [FIELDS] FROM [DB].[TABLE] WHERE 1 LIMIT [LIMIT];',
      'SELECT all' => 'SELECT * FROM [DB].[TABLE] WHERE 1 LIMIT [LIMIT];',
      'INSERT' => 'INSERT INTO [DB].[TABLE] ([FIELDS]) VALUES();',
      'UPDATE' => "UPDATE [DB].[TABLE] SET `field` = '' WHERE [PKEY];",
      'ON DUPLICATE' => "ON DUPLICATE KEY UPDATE `field` = '';",
      'JOIN' => 'INNER JOIN [DB].[TABLE] AS `T` ON [PKEY] = `T`.`Id`',
      'DELETE' => 'DELETE FROM [DB].[TABLE] WHERE [PKEY];',
      'GROUP CONCAT MAX LENGTH' => 'SET SESSION group_concat_max_len = 1000000;'
    ]);
  }

}
