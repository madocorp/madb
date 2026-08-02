<?php

namespace MADB\Engine;

interface EngineNavigationInterface {

  public function primaryLabel(): string;
  public function secondaryLabel(): string;
  public function usesSecondaryMenu(): bool;
  public function primaryListCommand(): string;
  public function secondaryListCommand(): string;
  public function secondaryMenuItems(): array;
  public function primaryMenuItems(): array;
  public function secondaryCreateMenuItems(): array;
  public function secondaryItemMenuItems(): array;

}
