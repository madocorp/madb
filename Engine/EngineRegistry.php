<?php

namespace MADB\Engine;

class EngineRegistry {

  private static string $activeEngine = 'MySQL';

  public static function engines(): array {
    return [
      'MySQL' => \MADB\Engine\MySQL\EngineDefinition::class,
      'SQLite' => \MADB\Engine\SQLite\EngineDefinition::class,
      'MongoDB' => \MADB\Engine\MongoDB\EngineDefinition::class,
      'Git' => \MADB\Engine\Git\EngineDefinition::class
    ];
  }

  public static function ids(): array {
    return array_keys(self::engines());
  }

  public static function exists(string $engine): bool {
    return isset(self::engines()[$engine]);
  }

  public static function active(): string {
    if (!self::exists(self::$activeEngine)) {
      self::$activeEngine = 'MySQL';
    }
    return self::$activeEngine;
  }

  public static function setActive(string $engine): void {
    if (!self::exists($engine)) {
      throw new \Exception("Unknown engine: {$engine}");
    }
    self::$activeEngine = $engine;
  }

  public static function definition(string $engine = null): string {
    $engine = $engine ?? self::active();
    $engines = self::engines();
    if (!isset($engines[$engine])) {
      throw new \Exception("Unknown engine: {$engine}");
    }
    return $engines[$engine];
  }

  public static function label(string $engine = null): string {
    $definition = self::definition($engine);
    return $definition::label();
  }

  public static function connectionClass(string $engine = null): string {
    $definition = self::definition($engine);
    return $definition::connectionClass();
  }

  public static function connectionPanel(string $engine = null): string {
    $definition = self::definition($engine);
    return $definition::connectionPanel();
  }

  public static function navigation(string $engine = null): EngineNavigationInterface {
    $definition = self::definition($engine);
    return $definition::navigation();
  }

  public static function workspace(string $engine = null): EngineWorkspaceInterface {
    $definition = self::definition($engine);
    return $definition::workspace();
  }

  public static function panels(string $engine = null): array {
    $definition = self::definition($engine);
    return $definition::panels();
  }

  public static function menuLabels(string $engine = null): array {
    $navigation = self::navigation($engine);
    return [
      'primary' => $navigation->primaryLabel(),
      'secondary' => $navigation->secondaryLabel()
    ];
  }

  public static function language(string $engine = null): EngineLanguageInterface {
    $definition = self::definition($engine);
    return $definition::language();
  }

  public static function primaryMenuItems(string $engine = null): array {
    return self::navigation($engine)->primaryMenuItems();
  }

  public static function usesSecondaryMenu(string $engine = null): bool {
    return self::navigation($engine)->usesSecondaryMenu();
  }

  public static function primaryListCommand(string $engine = null): string {
    return self::navigation($engine)->primaryListCommand();
  }

  public static function secondaryListCommand(string $engine = null): string {
    return self::navigation($engine)->secondaryListCommand();
  }

  public static function secondaryMenuItems(string $engine = null): array {
    return self::navigation($engine)->secondaryMenuItems();
  }

  public static function secondaryCreateMenuItems(string $engine = null): array {
    return self::navigation($engine)->secondaryCreateMenuItems();
  }

  public static function secondaryItemMenuItems(string $engine = null): array {
    return self::navigation($engine)->secondaryItemMenuItems();
  }

  public static function connectionEngine($connection): string {
    if (is_array($connection) && !empty($connection['engine'])) {
      return (string)$connection['engine'];
    }
    return self::active();
  }

}
