<?php

namespace MADB\Query;

/** Formats complete SQL statements and special UNION statement layouts. */
trait SqlFormatterStatementTrait {

  /** Formats tokens text for the SQL formatter. */
  private function formatTokens(array $tokens, int $indent = 0): string {
    if ($this->isUnionStatement($tokens)) {
      return $this->formatUnion($tokens, $indent);
    }
    if ($this->isInsertValuesStatement($tokens)) {
      return $this->formatInsertValues($tokens, $indent);
    }
    $tokens = $this->markMultilineLists($tokens);
    $out = '';
    $lineStart = true;
    $depth = 0;
    $clause = '';
    foreach ($tokens as $i => $token) {
      $value = $token['value'];
      $upper = $token['upper'];
      $prev = $this->previousMeaningful($tokens, $i);

      if ($this->startsNewLine($token, $depth, $clause, $prev === false ? false : $tokens[$prev])) {
        $this->newline($out, $indent + $depth, $lineStart);
      }

      if (isset($token['line-before-indent']) && !$lineStart) {
        $this->newline($out, $indent + $depth + $token['line-before-indent'], $lineStart);
      }

      if ($token['type'] === self::TYPE_KEYWORD && $this->isClauseKeyword($upper)) {
        $clause = $upper;
      }

      if (($token['condition-list-start'] ?? false) === true) {
        $this->append($out, $value, $lineStart, $tokens[$prev] ?? false, $token);
        $this->newline($out, $indent + $depth + 1, $lineStart);
        continue;
      }

      if (($token['case-list-start'] ?? false) === true) {
        $this->append($out, $value, $lineStart, $tokens[$prev] ?? false, $token);
        $this->newline($out, $indent + $depth + 2, $lineStart);
        continue;
      }

      if (($token['multiline-list-start'] ?? false) === true) {
        $this->append($out, $value, $lineStart, $tokens[$prev] ?? false, $token);
        $this->newline($out, $indent + $depth + 1, $lineStart);
        continue;
      }

      if ($value === '(') {
        $noSpaceBefore = $prev !== false && in_array($tokens[$prev]['type'], [self::TYPE_FUNCTION], true);
        if ($prev !== false && $tokens[$prev]['type'] === self::TYPE_KEYWORD && in_array($tokens[$prev]['upper'], SqlLexicon::DATA_TYPES, true)) {
          $noSpaceBefore = true;
        }
        if ($prev !== false && $tokens[$prev]['upper'] === 'IN' && $this->isNumericList($tokens, $i)) {
          $noSpaceBefore = true;
        }
        if (!$lineStart && !$noSpaceBefore && !$this->endsWithAny($out, [' ', "\n"])) {
          $out .= ' ';
        }
        $out .= '(';
        $lineStart = false;
        $depth++;
        if (($token['multiline-paren'] ?? false) === true) {
          $this->newline($out, $indent + $depth, $lineStart);
        }
        continue;
      }

      if ($value === ')') {
        $depth = max(0, $depth - 1);
        if (($token['multiline-paren-close'] ?? false) === true) {
          $this->newline($out, $indent + $depth, $lineStart);
          $out = rtrim($out, " \t");
        } else {
          $out = rtrim($out);
        }
        $out .= ')';
        $lineStart = false;
        continue;
      }

      if ($value === ',') {
        $out = rtrim($out) . ',';
        $next = $this->nextMeaningfulIn($tokens, $i);
        if ($next !== false && $tokens[$next]['type'] === self::TYPE_LINE_COMMENT && ($tokens[$next]['line-start-comment'] ?? false) === false) {
          $out .= ' ';
          $lineStart = false;
          continue;
        }
        if ($this->isMultilineComma($token, $depth, $clause)) {
          $commaIndent = ($token['multiline-paren-list'] ?? false) === true ? $depth : $depth + 1;
          $this->newline($out, $indent + $commaIndent, $lineStart);
        } else {
          $out .= ' ';
          $lineStart = false;
        }
        continue;
      }

      if ($value === ';') {
        $out = rtrim($out) . ';';
        $lineStart = false;
        continue;
      }

      if (($token['insert-as-before'] ?? false) === true && !$this->endsWithAny($out, [' ', "\n"])) {
        $out .= ' AS';
      } else if (($token['insert-as-before'] ?? false) === true) {
        $out .= 'AS';
      }

      if ($token['type'] === self::TYPE_LINE_COMMENT) {
        if (($token['line-start-comment'] ?? false) === true && !$lineStart) {
          $this->newline($out, $indent + $depth, $lineStart);
        }
        $commentIndent = $depth;
        if ($prev !== false && $tokens[$prev]['value'] === ',' && ($tokens[$prev]['multiline-list'] ?? false) === true) {
          $commentIndent++;
        }
        $this->append($out, $value, $lineStart, $tokens[$prev] ?? false, $token);
        $this->newline($out, $indent + $commentIndent, $lineStart);
        continue;
      }

      if ($token['type'] === self::TYPE_BLOCK_COMMENT) {
        $this->newline($out, $indent + $depth, $lineStart);
        $out .= $value;
        $lineStart = false;
        $this->newline($out, $indent + $depth, $lineStart);
        continue;
      }

      if (!$lineStart && in_array($upper, ['AND', 'OR'], true) && in_array($clause, self::CONDITION_CLAUSES, true)) {
        if (($token['condition-line-end'] ?? false) === true) {
          $this->append($out, $value, $lineStart, $tokens[$prev] ?? false, $token);
          $this->newline($out, $indent + $depth + 1, $lineStart);
          continue;
        }
        $this->newline($out, $indent + $depth, $lineStart);
      }

      $this->append($out, $value, $lineStart, $tokens[$prev] ?? false, $token);
    }
    return rtrim($out);
  }

  /** Checks is union statement for SQL formatter decisions. */
  private function isUnionStatement(array $tokens): bool {
    return $this->findTopLevelKeyword($tokens, 'UNION') !== false || $this->findTopLevelKeyword($tokens, 'UNION ALL') !== false;
  }

  /** Formats union text for the SQL formatter. */
  private function formatUnion(array $tokens, int $indent): string {
    $tail = $this->unionTailStart($tokens);
    $bodyEnd = $tail === false ? count($tokens) : $tail;
    $branches = [];
    $operators = [];
    $start = 0;
    $depth = 0;
    for ($i = 0; $i < $bodyEnd; $i++) {
      if ($tokens[$i]['value'] === '(') {
        $depth++;
      } else if ($tokens[$i]['value'] === ')') {
        $depth = max(0, $depth - 1);
      } else if ($depth === 0 && in_array($tokens[$i]['upper'], ['UNION', 'UNION ALL'], true)) {
        $branches[] = $this->trimStatementTokens(array_slice($tokens, $start, $i - $start));
        $operators[] = $tokens[$i]['upper'];
        $start = $i + 1;
      }
    }
    $branches[] = $this->trimStatementTokens(array_slice($tokens, $start, $bodyEnd - $start));

    $out = '';
    foreach ($branches as $i => $branch) {
      if ($i > 0) {
        $out .= "\n" . $operators[$i - 1] . "\n";
      }
      $out .= "(\n";
      $out .= $this->indentText($this->formatTokens($branch), $indent + 1) . "\n";
      $out .= str_repeat(self::INDENT, $indent) . ')';
    }

    if ($tail !== false) {
      $tailTokens = array_slice($tokens, $tail);
      if (!empty($tailTokens)) {
        $out .= "\n" . $this->formatTokens($tailTokens, $indent);
      }
    }

    return $out;
  }

  /** Coordinates union tail start work in the SQL formatter. */
  private function unionTailStart(array $tokens): int|false {
    $lastUnion = false;
    $depth = 0;
    for ($i = 0; $i < count($tokens); $i++) {
      if ($tokens[$i]['value'] === '(') {
        $depth++;
      } else if ($tokens[$i]['value'] === ')') {
        $depth = max(0, $depth - 1);
      } else if ($depth === 0 && in_array($tokens[$i]['upper'], ['UNION', 'UNION ALL'], true)) {
        $lastUnion = $i;
      }
    }
    if ($lastUnion === false) {
      return false;
    }

    $depth = 0;
    for ($i = $lastUnion + 1; $i < count($tokens); $i++) {
      if ($tokens[$i]['value'] === '(') {
        $depth++;
      } else if ($tokens[$i]['value'] === ')') {
        $depth = max(0, $depth - 1);
      } else if ($depth === 0 && in_array($tokens[$i]['upper'], ['ORDER BY', 'LIMIT'], true)) {
        return $i;
      }
    }
    return false;
  }

  /** Coordinates trim statement tokens work in the SQL formatter. */
  private function trimStatementTokens(array $tokens): array {
    while (!empty($tokens) && end($tokens)['value'] === ';') {
      array_pop($tokens);
    }
    return $tokens;
  }

}
