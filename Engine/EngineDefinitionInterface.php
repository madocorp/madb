<?php

namespace MADB\Engine;

interface EngineDefinitionInterface {

  public static function id(): string;
  public static function label(): string;
  public static function connectionClass(): string;
  public static function connectionPanel(): string;
  public static function menuLabels(): array;
  public static function primaryMenuItems(): array;
  public static function secondaryCreateMenuItems(): array;
  public static function secondaryItemMenuItems(): array;
  public static function language();

}
