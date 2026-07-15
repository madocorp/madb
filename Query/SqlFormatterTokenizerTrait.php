<?php

namespace MADB\Query;

/** Tokenizes SQL text and merges multi-word keywords before formatter classification. */
trait SqlFormatterTokenizerTrait {

  /** Formats format text for the SQL formatter. */
  public static function format(string $sql): string {
    $formatter = new self($sql);
    return $formatter->run();
  }

  /** Initializes SQL formatter state. */
  private function __construct(string $sql) {
    $this->tokens = $this->mergeKeywords($this->tokenize($sql));
    $this->classifyFunctions();
    $this->quoteIdentifiers();
  }

  /** Runs the SQL formatter operation. */
  private function run(): string {
    $statements = $this->splitStatements($this->tokens);
    $formatted = [];
    foreach ($statements as $statement) {
      if (empty($statement)) {
        continue;
      }
      $formatted[] = rtrim($this->formatTokens($statement));
    }
    return implode("\n\n", $formatted);
  }

  /** Coordinates tokenize work in the SQL formatter. */
  private function tokenize(string $sql): array {
    $tokens = [];
    $length = strlen($sql);
    $offset = 0;
    while ($offset < $length) {
      $remaining = substr($sql, $offset);
      if (preg_match('/^\s+/', $remaining, $match)) {
        $offset += strlen($match[0]);
        continue;
      }
      if (str_starts_with($remaining, '--') || str_starts_with($remaining, '#')) {
        $end = strpos($remaining, "\n");
        $value = $end === false ? $remaining : substr($remaining, 0, $end);
        $tokens[] = $this->token(self::TYPE_LINE_COMMENT, $value);
        $tokens[count($tokens) - 1]['line-start-comment'] = $this->isLineStartComment($sql, $offset);
        $offset += strlen($value);
        continue;
      }
      if (str_starts_with($remaining, '/*')) {
        $end = strpos($remaining, '*/', 2);
        $value = $end === false ? $remaining : substr($remaining, 0, $end + 2);
        $tokens[] = $this->token(self::TYPE_BLOCK_COMMENT, $value);
        $offset += strlen($value);
        continue;
      }
      if (preg_match('/^`(?:``|[^`])*`/', $remaining, $match)) {
        $tokens[] = $this->token(self::TYPE_IDENTIFIER, $match[0]);
        $offset += strlen($match[0]);
        continue;
      }
      if (preg_match('/^\'(?:\\\\.|\'\'|[^\'])*\'/', $remaining, $match) || preg_match('/^"(?:\\\\.|""|[^"])*"/', $remaining, $match)) {
        $tokens[] = $this->token(self::TYPE_STRING, $match[0]);
        $offset += strlen($match[0]);
        continue;
      }
      if (preg_match('/^\[[A-Z_][A-Z0-9_]*\]/i', $remaining, $match) || preg_match('/^\?/', $remaining, $match) || preg_match('/^:[A-Za-z_][A-Za-z0-9_]*/', $remaining, $match)) {
        $tokens[] = $this->token(self::TYPE_PLACEHOLDER, $match[0]);
        $offset += strlen($match[0]);
        continue;
      }
      if (preg_match('/^@@?[A-Za-z0-9_.$]+/', $remaining, $match)) {
        $tokens[] = $this->token(self::TYPE_VARIABLE, $match[0]);
        $offset += strlen($match[0]);
        continue;
      }
      if (preg_match('/^(0x[0-9a-fA-F]+|0b[01]+|[0-9]+(?:\.[0-9]+)?)/', $remaining, $match)) {
        $tokens[] = $this->token(self::TYPE_NUMBER, $match[0]);
        $offset += strlen($match[0]);
        continue;
      }
      if (preg_match('/^(<>|>=|<=|!=|:=|->>|->|&&|\|\|)/', $remaining, $match)) {
        $tokens[] = $this->token(self::TYPE_OPERATOR, $match[0]);
        $offset += strlen($match[0]);
        continue;
      }
      if (preg_match('/^[(),;.]/', $remaining, $match)) {
        $tokens[] = $this->token(self::TYPE_BOUNDARY, $match[0]);
        $offset++;
        continue;
      }
      if (preg_match('/^[=<>+\-*\/%^|&!@]/', $remaining, $match)) {
        $tokens[] = $this->token(self::TYPE_OPERATOR, $match[0]);
        $offset++;
        continue;
      }
      if (preg_match('/^[A-Za-z_][A-Za-z0-9_$]*/', $remaining, $match)) {
        $upper = strtoupper($match[0]);
        $type = in_array($upper, SqlLexicon::KEYWORDS, true) || in_array($upper, SqlLexicon::DATA_TYPES, true) || in_array($upper, SqlLexicon::CONSTANTS, true)
          ? self::TYPE_KEYWORD
          : self::TYPE_WORD;
        $tokens[] = $this->token($type, $type === self::TYPE_KEYWORD ? $upper : $match[0]);
        $offset += strlen($match[0]);
        continue;
      }
      $tokens[] = $this->token(self::TYPE_WORD, $remaining[0]);
      $offset++;
    }
    return $tokens;
  }

  /** Checks is line start comment for SQL formatter decisions. */
  private function isLineStartComment(string $sql, int $offset): bool {
    for ($i = $offset - 1; $i >= 0; $i--) {
      if ($sql[$i] === "\n" || $sql[$i] === "\r") {
        return true;
      }
      if ($sql[$i] !== ' ' && $sql[$i] !== "\t") {
        return false;
      }
    }
    return true;
  }

  /** Coordinates token work in the SQL formatter. */
  private function token(string $type, string $value): array {
    return [
      'type' => $type,
      'value' => $value,
      'upper' => strtoupper($value)
    ];
  }

  /** Coordinates merge keywords work in the SQL formatter. */
  private function mergeKeywords(array $tokens): array {
    $merged = [];
    $pairs = [
      'GROUP' => ['BY'],
      'ORDER' => ['BY'],
      'UNION' => ['ALL'],
      'LEFT' => ['JOIN', 'OUTER JOIN'],
      'RIGHT' => ['JOIN', 'OUTER JOIN'],
      'INNER' => ['JOIN'],
      'OUTER' => ['JOIN'],
      'CROSS' => ['JOIN'],
      'DELETE' => ['FROM'],
      'INSERT' => ['INTO'],
      'CREATE' => ['TABLE', 'VIEW'],
      'ALTER' => ['TABLE'],
      'DROP' => ['TABLE'],
      'RENAME' => ['TABLE'],
      'DEFAULT' => ['CHARSET'],
      'CHARACTER' => ['SET'],
      'SQL' => ['SECURITY'],
      'ON' => ['DELETE', 'UPDATE'],
      'NO' => ['ACTION'],
      'PRIMARY' => ['KEY'],
      'UNIQUE' => ['KEY'],
      'FOREIGN' => ['KEY']
    ];
    for ($i = 0; $i < count($tokens); $i++) {
      $current = $tokens[$i];
      $next = $tokens[$i + 1] ?? false;
      $third = $tokens[$i + 2] ?? false;
      if ($current['type'] === self::TYPE_KEYWORD && $next !== false && $next['type'] === self::TYPE_KEYWORD) {
        $phrase = $current['upper'] . ' ' . $next['upper'];
        if ($third !== false && $third['type'] === self::TYPE_KEYWORD) {
          $three = $phrase . ' ' . $third['upper'];
          if (in_array($three, ['LEFT OUTER JOIN', 'RIGHT OUTER JOIN', 'ON DUPLICATE KEY', 'DEFAULT CHARACTER SET'], true)) {
            $merged[] = $this->token(self::TYPE_KEYWORD, $three);
            $i += 2;
            continue;
          }
        }
        if (isset($pairs[$current['upper']]) && in_array($next['upper'], $pairs[$current['upper']], true)) {
          $merged[] = $this->token(self::TYPE_KEYWORD, $phrase);
          $i++;
          continue;
        }
      }
      $merged[] = $current;
    }
    return $merged;
  }

}
