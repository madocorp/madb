<?php

namespace MADB\Engine;

class SqlLanguage implements EngineLanguageInterface {

  private string $engine;
  private array $templates;

  public function __construct(string $engine, array $templates) {
    $this->engine = $engine;
    $this->templates = $templates;
  }

  public function split(string $text): array {
    return \MADB\Query\SqlSplitter::split($text);
  }

  public function statementAt(string $text, int $offset) {
    return \MADB\Query\SqlSplitter::statementAt($text, $offset);
  }

  public function format(string $text): string {
    return \MADB\Query\SqlFormatter\SqlFormatter::format($text);
  }

  public function editorTextForExecution(string $text, array $statements, $selectedIndexes = false): string {
    return \MADB\Query\SqlSelectLimiter::editorSql($text, $statements, \MADB\App\Settings::defaultSelectLimit(), $selectedIndexes);
  }

  public function executionStatements(array $statements): array {
    foreach ($statements as $index => $statement) {
      $statements[$index]['sql'] = \MADB\Query\SqlSelectLimiter::executionSql((string)($statement['sql'] ?? ''));
    }
    return $statements;
  }

  public function safetyIssues(array $statements): array {
    return \MADB\Main\ScreenController::sqlSafetyIssues($statements);
  }

  public function safetyRequiresPin(array $issues): bool {
    return \MADB\Main\ScreenController::sqlSafetyRequiresPin($issues);
  }

  public function template(string $name) {
    return $this->templates[$name] ?? false;
  }

  public function fillTemplate(string $template, $primary = null, $secondary = null, $fields = null): string {
    $pkey = $this->primaryKeyTemplateCondition($fields);
    if ($fields === null) {
      $fields = '[FIELDS]';
    } else {
      $fields = $this->formatFieldList($fields);
    }
    return str_replace(
      ['[DB]', '[TABLE]', '[FIELDS]', '[PKEY]'],
      [
        $primary === '' || $primary === null ? '[DB]' : $this->quoteIdentifier((string)$primary),
        $secondary === '' || $secondary === null ? '[TABLE]' : $this->quoteIdentifier((string)$secondary),
        $fields,
        $pkey
      ],
      $template
    );
  }

  private function quoteIdentifier(string $identifier): string {
    if ($this->engine === 'SQLite') {
      return '"' . str_replace('"', '""', $identifier) . '"';
    }
    return '`' . str_replace('`', '``', $identifier) . '`';
  }

  private function formatFieldList($fields): string {
    if (!is_array($fields) || empty($fields)) {
      return '*';
    }
    $quoted = [];
    foreach ($fields as $field) {
      if (is_array($field)) {
        $field = $field['COLUMN_NAME'] ?? '';
      }
      if ($field !== '') {
        $quoted[] = $this->quoteIdentifier((string)$field);
      }
    }
    return empty($quoted) ? '*' : implode(",\n       ", $quoted);
  }

  private function primaryKeyTemplateCondition($fields): string {
    if (!is_array($fields) || empty($fields)) {
      return '[PKEY]';
    }
    $conditions = [];
    foreach ($fields as $field) {
      if (!is_array($field) || ($field['COLUMN_KEY'] ?? '') !== 'PRI') {
        continue;
      }
      $name = (string)($field['COLUMN_NAME'] ?? '');
      if ($name !== '') {
        $conditions[] = $this->quoteIdentifier($name) . ' = -1';
      }
    }
    return empty($conditions) ? '[PKEY]' : implode(' AND ', $conditions);
  }

}
