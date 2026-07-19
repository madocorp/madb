<?php

namespace MADB\Query\SqlFormatter;

/** Classifies formatter tokens as functions, identifiers, statement groups, and INSERT VALUES structures. */
trait SqlFormatterClassifierTrait {

  /** Coordinates classify functions work in the SQL formatter. */
  private function classifyFunctions(): void {
    foreach ($this->tokens as $i => &$token) {
      if (!in_array($token['type'], [self::TYPE_WORD, self::TYPE_KEYWORD], true)) {
        continue;
      }
      $next = $this->nextMeaningful($i);
      if (
        $next !== false &&
        $this->tokens[$next]['value'] === '(' &&
        !in_array($token['upper'], \MADB\Query\SqlLexicon::DATA_TYPES, true) &&
        !$this->isQualifiedIdentifierPart($i) &&
        !$this->isIdentifierBeforeParenthesis($i) &&
        ($token['type'] === self::TYPE_WORD || in_array($token['upper'], \MADB\Query\SqlLexicon::FUNCTIONS, true))
      ) {
        $token['type'] = self::TYPE_FUNCTION;
        $token['value'] = strtoupper($token['value']);
        $token['upper'] = $token['value'];
      } else if (in_array($token['upper'], \MADB\Query\SqlLexicon::FUNCTIONS, true)) {
        $token['type'] = self::TYPE_FUNCTION;
        $token['value'] = strtoupper($token['value']);
      }
    }
  }

  /** Checks is identifier before parenthesis for SQL formatter decisions. */
  private function isIdentifierBeforeParenthesis(int $i): bool {
    $prev = $this->previousMeaningful($this->tokens, $i);
    if ($prev === false) {
      return false;
    }
    $previous = $this->tokens[$prev]['upper'];
    return in_array($previous, [
      'INSERT INTO', 'CREATE TABLE', 'ALTER TABLE', 'DROP TABLE',
      'INDEX', 'KEY', 'PRIMARY KEY', 'UNIQUE KEY', 'FOREIGN KEY', 'REFERENCES'
    ], true);
  }

  /** Checks is qualified identifier part for SQL formatter decisions. */
  private function isQualifiedIdentifierPart(int $i): bool {
    $prev = $this->previousMeaningful($this->tokens, $i);
    return $prev !== false && $this->tokens[$prev]['value'] === '.';
  }

  /** Escapes identifiers for SQL built by the SQL formatter. */
  private function quoteIdentifiers(): void {
    foreach ($this->tokens as $i => &$token) {
      if ($token['type'] !== self::TYPE_WORD) {
        continue;
      }
      if ($this->shouldQuoteWord($i)) {
        $token['type'] = self::TYPE_IDENTIFIER;
        $token['value'] = $this->quoteIdentifier($token['value']);
      }
    }
  }

  /** Checks should quote word for SQL formatter decisions. */
  private function shouldQuoteWord(int $i): bool {
    $word = strtoupper($this->tokens[$i]['value']);
    if (in_array($word, \MADB\Query\SqlLexicon::CONSTANTS, true)) {
      return false;
    }
    $prev = $this->previousMeaningful($this->tokens, $i);
    $prevPrev = $prev === false ? false : $this->previousMeaningful($this->tokens, $prev);
    if (
      $prev !== false &&
      $prevPrev !== false &&
      $this->tokens[$prev]['value'] === '=' &&
      in_array($this->tokens[$prevPrev]['upper'], ['ENGINE', 'CHARSET', 'CHARACTER SET', 'DEFAULT CHARSET', 'DEFAULT CHARACTER SET', 'COLLATE', 'COMMENT'], true)
    ) {
      return false;
    }
    if ($prev !== false && in_array($this->tokens[$prev]['upper'], ['ENGINE', 'CHARSET', 'CHARACTER SET', 'DEFAULT CHARSET', 'DEFAULT CHARACTER SET', 'COLLATE'], true)) {
      return false;
    }
    if ($this->isIdentifierBeforeParenthesis($i)) {
      return true;
    }
    if ($this->isQualifiedIdentifierPart($i)) {
      return true;
    }
    $next = $this->nextMeaningful($i);
    if ($next !== false && $this->tokens[$next]['value'] === '(') {
      return false;
    }
    return true;
  }

  /** Escapes identifier for SQL built by the SQL formatter. */
  private function quoteIdentifier(string $identifier): string {
    return '`' . str_replace('`', '``', $identifier) . '`';
  }

  /** Coordinates next meaningful work in the SQL formatter. */
  private function nextMeaningful(int $i): int|false {
    for ($j = $i + 1; $j < count($this->tokens); $j++) {
      return $j;
    }
    return false;
  }

  /** Coordinates previous meaningful work in the SQL formatter. */
  private function previousMeaningful(array $tokens, int $i): int|false {
    for ($j = $i - 1; $j >= 0; $j--) {
      return $j;
    }
    return false;
  }

  /** Splits statements data for the SQL formatter. */
  private function splitStatements(array $tokens): array {
    $statements = [];
    $statement = [];
    $depth = 0;
    foreach ($tokens as $token) {
      if ($token['value'] === '(') {
        $depth++;
      } else if ($token['value'] === ')') {
        $depth = max(0, $depth - 1);
      }
      $statement[] = $token;
      if ($depth === 0 && $token['value'] === ';') {
        $statements[] = $statement;
        $statement = [];
      }
    }
    if (!empty($statement)) {
      $statements[] = $statement;
    }
    return $statements;
  }

  /** Checks is insert values statement for SQL formatter decisions. */
  private function isInsertValuesStatement(array $tokens): bool {
    return isset($tokens[0]) && $tokens[0]['upper'] === 'INSERT INTO' && $this->findTopLevelKeyword($tokens, 'VALUES') !== false;
  }

  /** Formats insert values text for the SQL formatter. */
  private function formatInsertValues(array $tokens, int $indent): string {
    $values = $this->findTopLevelKeyword($tokens, 'VALUES');
    $fieldsOpen = $this->findTokenValue($tokens, '(', 1, $values);
    if ($values === false || $fieldsOpen === false) {
      return $this->formatTokensWithoutInsertSpecial($tokens, $indent);
    }
    $fieldsClose = $this->matchingParen($tokens, $fieldsOpen);
    if ($fieldsClose === false || $fieldsClose > $values) {
      return $this->formatTokensWithoutInsertSpecial($tokens, $indent);
    }

    $table = array_slice($tokens, 1, $fieldsOpen - 1);
    $fields = $this->splitTopLevelItems(array_slice($tokens, $fieldsOpen + 1, $fieldsClose - $fieldsOpen - 1));
    $rows = $this->insertValueRows($tokens, $values + 1);
    if (empty($fields) || empty($rows)) {
      return $this->formatTokensWithoutInsertSpecial($tokens, $indent);
    }

    $fieldGroup = $this->formatGroupedItems($fields, $indent + 1, $indent);
    $out = 'INSERT INTO ' . $this->formatInlineTokens($table);
    $out .= count($fields) <= 4 ? ' ' . $fieldGroup . "\n" : ' ' . $fieldGroup . "\n";

    if (count($rows) === 1 && count($rows[0]['items']) <= 4) {
      return $out . 'VALUES ' . $this->formatGroupedItems($rows[0]['items'], $indent + 1, $indent) . ';';
    }

    if ($this->hasLongInsertRow($rows)) {
      $groups = [];
      foreach ($rows as $row) {
        $groups[] = $this->formatGroupedItems($row['items'], $indent + 1, $indent);
      }
      return $out . 'VALUES ' . implode(', ', $groups) . ';';
    }

    $out .= 'VALUES' . "\n";
    foreach ($rows as $row) {
      $out .= str_repeat(self::INDENT, $indent + 1) . $this->formatGroupedItems($row['items'], $indent + 2, $indent + 1) . ($row['comma'] ? ',' : ';') . "\n";
    }
    return rtrim($out);
  }

  /** Formats tokens without insert special text for the SQL formatter. */
  private function formatTokensWithoutInsertSpecial(array $tokens, int $indent): string {
    $copy = $tokens;
    if (isset($copy[0]) && $copy[0]['upper'] === 'INSERT INTO') {
      $copy[0]['upper'] = 'INSERT';
    }
    return $this->formatTokens($copy, $indent);
  }

}
