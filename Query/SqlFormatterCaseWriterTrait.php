<?php

namespace MADB\Query;

/** Handles CASE expression structure and low-level token spacing/newline decisions for the SQL formatter. */
trait SqlFormatterCaseWriterTrait {

  /** Coordinates case end work in the SQL formatter. */
  private function caseEnd(array $tokens, int $start): int|false {
    $depth = 1;
    for ($i = $start; $i < count($tokens); $i++) {
      if ($tokens[$i]['upper'] === 'CASE') {
        $depth++;
      } else if ($tokens[$i]['upper'] === 'END') {
        $depth--;
        if ($depth === 0) {
          return $i;
        }
      }
    }
    return false;
  }

  /** Marks case expression metadata used by the SQL formatter. */
  private function markCaseExpression(array &$tokens, int $start, int $end): void {
    $depth = 0;
    for ($i = $start; $i <= $end; $i++) {
      if ($tokens[$i]['upper'] === 'CASE') {
        $depth++;
      } else if ($tokens[$i]['upper'] === 'END') {
        if ($depth === 0) {
          $tokens[$i]['line-before-indent'] = 1;
        } else {
          $depth--;
        }
      } else if ($depth === 0 && in_array($tokens[$i]['upper'], ['WHEN', 'ELSE'], true)) {
        $tokens[$i]['line-before-indent'] = 2;
      }
    }
  }

  /** Checks is numeric list for SQL formatter decisions. */
  private function isNumericList(array $tokens, int $open): bool {
    $close = $this->matchingParen($tokens, $open);
    if ($close === false) {
      return false;
    }
    for ($i = $open + 1; $i < $close; $i++) {
      if (in_array($tokens[$i]['value'], [',', '+', '-'], true)) {
        continue;
      }
      if ($tokens[$i]['type'] !== self::TYPE_NUMBER) {
        return false;
      }
    }
    return true;
  }

  /** Coordinates starts new line work in the SQL formatter. */
  private function startsNewLine(array $token, int $depth, string $clause, array|false $prev): bool {
    if ($prev === false || $token['type'] !== self::TYPE_KEYWORD) {
      return false;
    }
    if ($depth === 1 && in_array($token['upper'], self::JOIN_KEYWORDS, true)) {
      return true;
    }
    if ($depth !== 0) {
      return false;
    }
    if ($token['upper'] === 'SET' && in_array($prev['upper'], ['ON DELETE', 'ON UPDATE'], true)) {
      return false;
    }
    if (in_array($token['upper'], array_merge(self::CLAUSE_KEYWORDS, self::JOIN_KEYWORDS), true)) {
      return true;
    }
    return false;
  }

  /** Checks is clause keyword for SQL formatter decisions. */
  private function isClauseKeyword(string $upper): bool {
    return in_array($upper, array_merge(self::CLAUSE_KEYWORDS, self::JOIN_KEYWORDS, ['ON']), true);
  }

  /** Checks is multiline comma for SQL formatter decisions. */
  private function isMultilineComma(array $token, int $depth, string $clause): bool {
    return ($token['multiline-list'] ?? false) === true;
  }

  /** Appends append output for the SQL formatter. */
  private function append(string &$out, string $value, bool &$lineStart, array|false $prev, array $token): void {
    if (!$lineStart && $this->needsSpace($prev, $token) && !$this->endsWithAny($out, [' ', "\n"])) {
      $out .= ' ';
    }
    $out .= $value;
    $lineStart = false;
  }

  /** Coordinates needs space work in the SQL formatter. */
  private function needsSpace(array|false $prev, array $token): bool {
    if ($prev === false) {
      return false;
    }
    if ($token['value'] === '.' || $prev['value'] === '.' || $token['value'] === '@' || $prev['value'] === '@') {
      return false;
    }
    if ($token['value'] === ')' || $token['value'] === ',' || $token['value'] === ';') {
      return false;
    }
    if ($prev['value'] === '(') {
      return false;
    }
    if ($token['value'] === '(' && $prev['type'] === self::TYPE_FUNCTION) {
      return false;
    }
    if ($token['value'] === '(' && $prev['type'] === self::TYPE_KEYWORD && in_array($prev['upper'], SqlLexicon::DATA_TYPES, true)) {
      return false;
    }
    if ($prev['type'] === self::TYPE_OPERATOR || $token['type'] === self::TYPE_OPERATOR) {
      return true;
    }
    return true;
  }

  /** Coordinates newline work in the SQL formatter. */
  private function newline(string &$out, int $indent, bool &$lineStart): void {
    $out = rtrim($out);
    if ($out !== '') {
      $out .= "\n";
    }
    $out .= str_repeat(self::INDENT, max(0, $indent));
    $lineStart = true;
  }

  /** Coordinates ends with any work in the SQL formatter. */
  private function endsWithAny(string $text, array $needles): bool {
    foreach ($needles as $needle) {
      if ($needle !== '' && str_ends_with($text, $needle)) {
        return true;
      }
    }
    return false;
  }

}
