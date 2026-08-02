<?php

namespace MADB\Engine\MongoDB;

class EngineDefinition implements \MADB\Engine\EngineDefinitionInterface {

  public static function id(): string {
    return 'MongoDB';
  }

  public static function label(): string {
    return 'MongoDB';
  }

  public static function connectionClass(): string {
    return \MADB\Engine\MongoDB\Connection::class;
  }

  public static function connectionPanel(): string {
    return 'connection-editor-mongodb';
  }

  public static function navigation(): \MADB\Engine\EngineNavigationInterface {
    return new \MADB\Engine\Common\Database\Navigation(
      'Database',
      'Collection',
      self::primaryMenuItems(),
      self::secondaryCreateMenuItems(),
      self::secondaryItemMenuItems()
    );
  }

  public static function workspace(): \MADB\Engine\EngineWorkspaceInterface {
    return new \MADB\Engine\Common\Database\Workspace();
  }

  public static function panels(): array {
    return [];
  }

  public static function menuLabels(): array {
    return [
      'primary' => 'Database',
      'secondary' => 'Collection'
    ];
  }

  public static function primaryMenuItems(): array {
    return [
      ['text' => 'Rename', 'onOpen' => 'MADB\Schema\RenameController::rename'],
      ['text' => 'Drop', 'onOpen' => 'MADB\Schema\DropController::drop']
    ];
  }

  public static function secondaryCreateMenuItems(): array {
    return [
      ['text' => 'Collection', 'onOpen' => 'MADB\Table\CreateController::openCollectionCreate']
    ];
  }

  public static function secondaryItemMenuItems(): array {
    return [
      ['text' => 'Find', 'onOpen' => 'MADB\Table\RowsController::findRows'],
      ['text' => 'Index', 'onOpen' => 'MADB\Table\MongoIndexController::openIndexList']
    ];
  }

  public static function language() {
    return new \MADB\Engine\MongoDB\MongoLanguage();
  }

}
