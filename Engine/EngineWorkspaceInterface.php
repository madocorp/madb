<?php

namespace MADB\Engine;

interface EngineWorkspaceInterface {

  public function mode(): string;
  public function paneLabels(): array;
  public function editorMode(): string;
  public function editorTokenizer($connectionName);
  public function resultTableOnChange();
  public function listOnChange();
  public function defaultFocus(): string;
  public function queryMenuItems($connectionName): array;
  public function primarySelected($primary): bool;
  public function handleKeyPress($element, array $event): bool;

}
