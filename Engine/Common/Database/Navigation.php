<?php

namespace MADB\Engine\Common\Database;

class Navigation implements \MADB\Engine\EngineNavigationInterface {

  private string $primaryLabel;
  private string $secondaryLabel;
  private array $primaryMenuItems;
  private array $secondaryCreateMenuItems;
  private array $secondaryItemMenuItems;

  public function __construct(
    string $primaryLabel,
    string $secondaryLabel,
    array $primaryMenuItems,
    array $secondaryCreateMenuItems,
    array $secondaryItemMenuItems
  ) {
    $this->primaryLabel = $primaryLabel;
    $this->secondaryLabel = $secondaryLabel;
    $this->primaryMenuItems = $primaryMenuItems;
    $this->secondaryCreateMenuItems = $secondaryCreateMenuItems;
    $this->secondaryItemMenuItems = $secondaryItemMenuItems;
  }

  public function primaryLabel(): string {
    return $this->primaryLabel;
  }

  public function secondaryLabel(): string {
    return $this->secondaryLabel;
  }

  public function usesSecondaryMenu(): bool {
    return true;
  }

  public function primaryListCommand(): string {
    return 'schemaList';
  }

  public function secondaryListCommand(): string {
    return 'tableList';
  }

  public function secondaryMenuItems(): array {
    return [];
  }

  public function primaryMenuItems(): array {
    return $this->primaryMenuItems;
  }

  public function secondaryCreateMenuItems(): array {
    return $this->secondaryCreateMenuItems;
  }

  public function secondaryItemMenuItems(): array {
    return $this->secondaryItemMenuItems;
  }

}
