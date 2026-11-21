<?php

namespace MADB\Engine;

/** Owns one database session inside a worker process. */
interface WorkerBackend {

  public function connect(): string;
  public function test(): string;
  public function query(string $text): array;
  public function selectDatabase(string $name): string;

}
