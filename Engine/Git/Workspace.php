<?php

namespace MADB\Engine\Git;

use \SPTK\SDLWrapper\Action;
use \SPTK\SDLWrapper\KeyCode;
use \SPTK\SDLWrapper\KeyCombo;
use \SPTK\SDLWrapper\KeyModifier;

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

  public function usesStoredList(): bool {
    return false;
  }

  public function queryMenuItems($connectionName): array {
    return [
      ['text' => 'Search', 'onOpen' => 'MADB\Engine\Git\WorkspaceController::searchDiff'],
      ['text' => 'Export', 'onOpen' => 'MADB\Engine\Git\WorkspaceController::exportDiff'],
      ['text' => 'Context', 'onOpen' => 'MADB\Engine\Git\WorkspaceController::contextPanel']
    ];
  }

  public function primarySelected($primary, bool $preserveFocus = false): bool {
    \MADB\Engine\Git\WorkspaceController::showBranchCommits($preserveFocus);
    return true;
  }

  public function handleKeyPress($element, array $event): bool {
    $mod = $event['mod'] ?? 0;
    $key = $event['key'] ?? false;
    $scancode = $event['scancode'] ?? false;
    $action = KeyCombo::resolve($mod, $scancode, $key);
    $plain = ($mod & (KeyModifier::CTRL | KeyModifier::SHIFT | KeyModifier::ALT | KeyModifier::GUI)) === 0;
    if (
      $plain &&
      \MADB\Main\ScreenController::isListActive() &&
      (
        $scancode === \SPTK\SDLWrapper\ScanCode::INSERT ||
        $key === KeyCode::INSERT ||
        in_array($action, [Action::DELETE_FORWARD, Action::DO_IT, Action::SELECT_ITEM], true)
      )
    ) {
      \MADB\Main\ScreenController::suppressShortcutTextInput();
      return true;
    }
    if ($plain && $key === KeyCode::NUM_0) {
      \MADB\Main\ScreenController::suppressShortcutTextInput();
      return WorkspaceController::selectFileByShortcut(0);
    }
    if ($plain && $key >= KeyCode::NUM_1 && $key <= KeyCode::NUM_9) {
      \MADB\Main\ScreenController::suppressShortcutTextInput();
      return WorkspaceController::selectFileByShortcut($key - KeyCode::NUM_0);
    }
    if (
      !\MADB\Main\ScreenController::isListActive() &&
      ($mod & KeyModifier::CTRL) !== 0 &&
      ($mod & (KeyModifier::SHIFT | KeyModifier::ALT | KeyModifier::GUI)) === 0 &&
      in_array($action, [Action::SWITCH_UP, Action::SWITCH_DOWN], true)
    ) {
      \MADB\Main\ScreenController::suppressShortcutTextInput();
      return WorkspaceController::moveFileSelection($action === Action::SWITCH_UP ? -1 : 1);
    }
    return false;
  }

}
