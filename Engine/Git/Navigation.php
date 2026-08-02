<?php

namespace MADB\Engine\Git;

class Navigation implements \MADB\Engine\EngineNavigationInterface {

  public function primaryLabel(): string {
    return 'Branch';
  }

  public function secondaryLabel(): string {
    return 'Commit';
  }

  public function usesSecondaryMenu(): bool {
    return false;
  }

  public function primaryListCommand(): string {
    return 'schemaList';
  }

  public function secondaryListCommand(): string {
    return 'tableList';
  }

  public function secondaryMenuItems(): array {
    return [
      ['value' => 'refresh-commit-list', 'text' => 'Refresh commit list', 'onOpen' => 'MADB\Engine\Git\WorkspaceController::showBranchCommits']
    ];
  }

  public function primaryMenuItems(): array {
    return \MADB\Engine\Git\EngineDefinition::primaryMenuItems();
  }

  public function secondaryCreateMenuItems(): array {
    return [];
  }

  public function secondaryItemMenuItems(): array {
    return \MADB\Engine\Git\EngineDefinition::secondaryItemMenuItems();
  }

}
