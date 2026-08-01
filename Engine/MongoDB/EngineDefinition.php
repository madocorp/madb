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

  public static function menuLabels(): array {
    return [
      'primary' => 'Database',
      'secondary' => 'Collection'
    ];
  }

  public static function primaryMenuItems(): array {
    return [
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
