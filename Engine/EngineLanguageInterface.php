<?php

namespace MADB\Engine;

interface EngineLanguageInterface {

  public function split(string $text): array;
  public function statementAt(string $text, int $offset);
  public function format(string $text): string;
  public function editorTextForExecution(string $text, array $statements, $selectedIndexes = false): string;
  public function executionStatements(array $statements): array;
  public function safetyIssues(array $statements): array;
  public function safetyRequiresPin(array $issues): bool;
  public function template(string $name);
  public function fillTemplate(string $template, $primary = null, $secondary = null, $fields = null): string;

}
