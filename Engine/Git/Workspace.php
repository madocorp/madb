<?php

namespace MADB\Engine\Git;

class Workspace implements \MADB\Engine\EngineWorkspaceInterface {

  public function mode(): string {
    return 'git-commit-browser';
  }

  public function paneLabels(): array {
    return [
      'editor' => 'Diff',
      'table' => 'Commit grid',
      'list' => 'Changed files'
    ];
  }

  public function editorMode(): string {
    return 'readonly-diff';
  }

  public function editorTokenizer($connectionName) {
    return '\MADB\Tokenizer\GitDiff';
  }

  public function resultTableOnChange() {
    return '\MADB\Engine\Git\WorkspaceController::selectCommitFromResult';
  }

  public function listOnChange() {
    return '\MADB\Engine\Git\WorkspaceController::selectFile';
  }

  public function defaultFocus(): string {
    return 'result';
  }

  public function queryMenuItems($connectionName): array {
    return [
      ['text' => 'Search', 'onOpen' => 'MADB\Engine\Git\WorkspaceController::searchDiff'],
      ['text' => 'Export', 'onOpen' => 'MADB\Engine\Git\WorkspaceController::exportDiff'],
      ['text' => 'Context', 'onOpen' => 'MADB\Engine\Git\WorkspaceController::contextPanel']
    ];
  }

  public function primarySelected($primary): bool {
    \MADB\Engine\Git\WorkspaceController::showBranchCommits();
    return true;
  }

  public function handleKeyPress($element, array $event): bool {
    return false;
  }

}
