<?php

namespace MADB\Engine;

class EngineRegistry {

  private static string $activeEngine = 'MySQL';

  public static function engines(): array {
    return [
      'MySQL' => \MADB\Engine\MySQL\EngineDefinition::class,
      'SQLite' => \MADB\Engine\SQLite\EngineDefinition::class,
      'MongoDB' => \MADB\Engine\MongoDB\EngineDefinition::class
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

  public static function menuLabels(string $engine = null): array {
    $labels = [
      'primary' => 'Primary',
      'secondary' => 'Secondary'
    ];
    $definition = self::definition($engine);
    return array_merge($labels, $definition::menuLabels());
  }

  public static function language(string $engine = null): EngineLanguageInterface {
    $definition = self::definition($engine);
    return $definition::language();
  }

  public static function primaryMenuItems(string $engine = null): array {
    $definition = self::definition($engine);
    return $definition::primaryMenuItems();
  }

  public static function secondaryCreateMenuItems(string $engine = null): array {
    $definition = self::definition($engine);
    return $definition::secondaryCreateMenuItems();
  }

  public static function secondaryItemMenuItems(string $engine = null): array {
    $definition = self::definition($engine);
    return $definition::secondaryItemMenuItems();
  }

  public static function connectionEngine($connection): string {
    if (is_array($connection) && !empty($connection['engine'])) {
      return (string)$connection['engine'];
    }
    return self::active();
  }

}
