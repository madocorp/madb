<?php

namespace MADB\Engine\Git;

class EngineDefinition implements \MADB\Engine\EngineDefinitionInterface {

  public static function id(): string {
    return 'Git';
  }

  public static function label(): string {
    return 'Git';
  }

  public static function connectionClass(): string {
    return \MADB\Engine\Git\Connection::class;
  }

  public static function connectionPanel(): string {
    return 'connection-editor-git';
  }

  public static function navigation(): \MADB\Engine\EngineNavigationInterface {
    return new Navigation();
  }

  public static function workspace(): \MADB\Engine\EngineWorkspaceInterface {
    return new Workspace();
  }

  public static function panels(): array {
    return [];
  }

  public static function menuLabels(): array {
    return [
      'primary' => 'Branch',
      'secondary' => 'Commit'
    ];
  }

  public static function primaryMenuItems(): array {
    return [
      ['text' => 'Show commits', 'onOpen' => 'MADB\Engine\Git\WorkspaceController::showBranchCommits'],
      ['text' => 'Fetch', 'onOpen' => 'MADB\Engine\Git\WorkspaceController::fetch']
    ];
  }

  public static function secondaryCreateMenuItems(): array {
    return [];
  }

  public static function secondaryItemMenuItems(): array {
    return [];
  }

  public static function language() {
    return new \MADB\Engine\TextLanguage();
  }

}
