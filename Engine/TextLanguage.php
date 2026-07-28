<?php

namespace MADB\Engine;

class TextLanguage implements EngineLanguageInterface {

  public function split(string $text): array {
    $text = trim($text);
    if ($text === '') {
      return [];
    }
    return [[
      'sql' => $text,
      'start' => 0,
      'end' => strlen($text)
    ]];
  }

  public function statementAt(string $text, int $offset) {
    $statements = $this->split($text);
    return $statements[0] ?? false;
  }

  public function format(string $text): string {
    return $text;
  }

  public function editorTextForExecution(string $text, array $statements, $selectedIndexes = false): string {
    return $text;
  }

  public function executionStatements(array $statements): array {
    return $statements;
  }

  public function safetyIssues(array $statements): array {
    return [];
  }

  public function safetyRequiresPin(array $issues): bool {
    return false;
  }

  public function templates(): array {
    return [];
  }

  public function template(string $name) {
    return false;
  }

  public function fillTemplate(string $template, $primary = null, $secondary = null, $fields = null): string {
    return $template;
  }

}
