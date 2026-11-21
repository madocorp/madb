<?php

namespace MADB\Engine;

/** Build the engine implementation for one saved connection snapshot. */
final class WorkerBackendFactory {

  public static function create(array $connection): WorkerBackend {
    return match ($connection['engine'] ?? null) {
      'MySQL' => new MySQL\WorkerBackend($connection),
      'SQLite' => new SQLite\WorkerBackend($connection),
      'MongoDB' => new MongoDB\WorkerBackend($connection),
      default => throw new \InvalidArgumentException('Unknown connection engine.'),
    };
  }

}
