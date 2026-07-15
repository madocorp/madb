<?php

namespace MADB\Query;

/** Marks and formats comma-separated SQL lists such as SELECT items, VALUES rows, and parenthesized lists. */
trait SqlFormatterListTrait {

  /** Adds formatter indentation to every line in a nested SQL fragment. */
  private function indentText(string $text, int $indent): string {
    $prefix = str_repeat(self::INDENT, $indent);
    return implode("\n", array_map(fn($line) => $prefix . $line, explode("\n", $text)));
  }

  /** Marks token ranges that should be rendered as multiline SQL lists. */
  private function markMultilineLists(array $tokens): array {
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
      if ($tokens[$i]['type'] === self::TYPE_KEYWORD && in_array($tokens[$i]['upper'], self::LIST_CLAUSES, true) && !$this->isCompactDataTypeKeyword($tokens, $i)) {
        $end = $this->clauseEnd($tokens, $i + 1);
        if ($this->hasTopLevelComma($tokens, $i + 1, $end)) {
          $tokens[$i]['multiline-list-start'] = true;
          $this->markTopLevelList($tokens, $i + 1, $end, false);
        }
      }
      if ($tokens[$i]['type'] === self::TYPE_KEYWORD && $tokens[$i]['upper'] === 'ALTER TABLE') {
        $end = $this->clauseEnd($tokens, $i + 1);
        if ($this->hasTopLevelComma($tokens, $i + 1, $end)) {
          $this->markTopLevelList($tokens, $i + 1, $end, false);
          $action = $this->firstAlterAction($tokens, $i + 1, $end);
          if ($action !== false) {
            $tokens[$action]['line-before-indent'] = 1;
          }
        }
      }
      if ($tokens[$i]['type'] === self::TYPE_KEYWORD && $tokens[$i]['upper'] === 'RENAME TABLE') {
        $end = $this->clauseEnd($tokens, $i + 1);
        if ($this->hasTopLevelComma($tokens, $i + 1, $end)) {
          $this->markTopLevelList($tokens, $i + 1, $end, false);
          $next = $this->nextMeaningfulIn($tokens, $i);
          if ($next !== false && $next < $end) {
            $tokens[$next]['line-before-indent'] = 1;
          }
        }
      }
      if ($tokens[$i]['type'] === self::TYPE_KEYWORD && in_array($tokens[$i]['upper'], self::CONDITION_CLAUSES, true)) {
        $end = $this->clauseEnd($tokens, $i + 1);
        if ($tokens[$i]['upper'] === 'ON' || $this->hasTopLevelCondition($tokens, $i + 1, $end)) {
          $tokens[$i]['condition-list-start'] = true;
          if ($tokens[$i]['upper'] === 'ON') {
            if ($this->markStackedConditionParentheses($tokens, $i + 1)) {
              $tokens[$i]['condition-list-inline-start'] = true;
              $this->markConditionSuffixOperators($tokens, $i + 1, $end);
            }
          }
          $this->markTopLevelCondition($tokens, $i + 1, $end);
        }
      }
      if ($tokens[$i]['type'] === self::TYPE_KEYWORD && in_array($tokens[$i]['upper'], array_merge(['FROM'], self::JOIN_KEYWORDS), true)) {
        $this->markTableAlias($tokens, $i + 1);
      }
      if ($tokens[$i]['type'] === self::TYPE_KEYWORD && $tokens[$i]['upper'] === 'SELECT') {
        $end = $this->clauseEnd($tokens, $i + 1);
        $this->markSelectAliases($tokens, $i + 1, $end);
      }
      if ($tokens[$i]['type'] === self::TYPE_KEYWORD && $tokens[$i]['upper'] === 'CASE') {
        $end = $this->caseEnd($tokens, $i + 1);
        if ($end !== false) {
          $tokens[$i]['case-list-start'] = true;
          $this->markCaseExpression($tokens, $i + 1, $end);
        }
      }
      if ($tokens[$i]['value'] === '(') {
        $end = $this->matchingParen($tokens, $i);
        if ($end !== false && $this->isParenthesizedJoinTable($tokens, $i, $end)) {
          $tokens[$i]['multiline-paren'] = true;
          $tokens[$end]['multiline-paren-close'] = true;
          continue;
        }
        if ($end !== false && !$this->isCompactParenthesis($tokens, $i) && $this->hasTopLevelComma($tokens, $i + 1, $end)) {
          $tokens[$i]['multiline-paren'] = true;
          $tokens[$end]['multiline-paren-close'] = true;
          $this->markTopLevelList($tokens, $i + 1, $end, true);
        }
      }
    }
    return $tokens;
  }

  /** Marks redundant stacked ON-condition parentheses for vertical display. */
  private function markStackedConditionParentheses(array &$tokens, int $start): bool {
    $opens = [];
    for ($i = $start; isset($tokens[$i]) && $tokens[$i]['value'] === '('; $i++) {
      $opens[] = $i;
    }
    if (count($opens) < 2) {
      return false;
    }
    array_pop($opens);
    foreach ($opens as $open) {
      $close = $this->matchingParen($tokens, $open);
      if ($close === false) {
        continue;
      }
      $tokens[$open]['multiline-paren'] = true;
      $tokens[$close]['multiline-paren-close'] = true;
    }
    return true;
  }

  /** Marks AND/OR as line suffixes inside vertically formatted ON wrappers. */
  private function markConditionSuffixOperators(array &$tokens, int $start, int $end): void {
    $depth = 0;
    for ($i = $start; $i < $end; $i++) {
      if ($tokens[$i]['value'] === '(') {
        $depth++;
      } else if ($tokens[$i]['value'] === ')') {
        $depth = max(0, $depth - 1);
      } else if ($depth > 0 && in_array($tokens[$i]['upper'], ['AND', 'OR'], true)) {
        $tokens[$i]['condition-line-suffix'] = true;
      }
    }
  }

  /** Checks if a FROM parenthesis wraps a joined table expression. */
  private function isParenthesizedJoinTable(array $tokens, int $open, int $close): bool {
    $prev = $this->previousMeaningful($tokens, $open);
    if ($prev === false || $tokens[$prev]['upper'] !== 'FROM') {
      return false;
    }
    for ($i = $open + 1; $i < $close; $i++) {
      if (in_array($tokens[$i]['upper'], self::JOIN_KEYWORDS, true)) {
        return true;
      }
    }
    return false;
  }

  /** Detects data-type parameter lists that should stay inline. */
  private function isCompactDataTypeKeyword(array $tokens, int $i): bool {
    if (!in_array($tokens[$i]['upper'], SqlLexicon::DATA_TYPES, true)) {
      return false;
    }
    $next = $i + 1;
    return isset($tokens[$next]) && $tokens[$next]['value'] === '(';
  }

  /** Marks top-level tokens inside a comma-separated list for multiline formatting. */
  private function markTopLevelList(array &$tokens, int $start, int $end, bool $parenList): void {
    $depth = 0;
    for ($i = $start; $i < $end; $i++) {
      if ($tokens[$i]['value'] === '(') {
        $depth++;
      } else if ($tokens[$i]['value'] === ')') {
        $depth = max(0, $depth - 1);
      }
      if ($depth === 0) {
        $tokens[$i]['multiline-list'] = true;
        if ($parenList) {
          $tokens[$i]['multiline-paren-list'] = true;
        }
      }
    }
  }

  /** Detects parenthesized expressions that should remain compact. */
  private function isCompactParenthesis(array $tokens, int $open): bool {
    $prev = $this->previousMeaningful($tokens, $open);
    if ($prev === false) {
      return false;
    }
    $previous = $tokens[$prev];
    if ($previous['type'] === self::TYPE_FUNCTION) {
      return true;
    }
    if ($previous['type'] === self::TYPE_KEYWORD && in_array($previous['upper'], SqlLexicon::DATA_TYPES, true)) {
      return true;
    }
    if ($previous['type'] === self::TYPE_IDENTIFIER) {
      $prevPrev = $this->previousMeaningful($tokens, $prev);
      if ($prevPrev !== false && in_array($tokens[$prevPrev]['upper'], ['KEY', 'UNIQUE KEY'], true)) {
        return true;
      }
    }
    if ($previous['upper'] === 'IN') {
      return true;
    }
    return false;
  }

  /** Checks whether a token range contains a comma outside nested parentheses. */
  private function hasTopLevelComma(array $tokens, int $start, int $end): bool {
    $depth = 0;
    for ($i = $start; $i < $end; $i++) {
      if ($tokens[$i]['value'] === '(') {
        $depth++;
      } else if ($tokens[$i]['value'] === ')') {
        $depth = max(0, $depth - 1);
      } else if ($depth === 0 && $tokens[$i]['value'] === ',') {
        return true;
      }
    }
    return false;
  }

  /** Splits a token range into comma-separated items while respecting parentheses. */
  private function splitTopLevelItems(array $tokens): array {
    $items = [];
    $item = [];
    $depth = 0;
    foreach ($tokens as $token) {
      if ($token['value'] === '(') {
        $depth++;
      } else if ($token['value'] === ')') {
        $depth = max(0, $depth - 1);
      }
      if ($depth === 0 && $token['value'] === ',') {
        $items[] = $item;
        $item = [];
        continue;
      }
      $item[] = $token;
    }
    if (!empty($item)) {
      $items[] = $item;
    }
    return $items;
  }

  /** Extracts INSERT VALUES row groups for grouped formatter output. */
  private function insertValueRows(array $tokens, int $start): array {
    $rows = [];
    for ($i = $start; $i < count($tokens); $i++) {
      if ($tokens[$i]['value'] !== '(') {
        continue;
      }
      $close = $this->matchingParen($tokens, $i);
      if ($close === false) {
        break;
      }
      $next = $this->nextIndex($tokens, $close);
      $rows[] = [
        'items' => $this->splitTopLevelItems(array_slice($tokens, $i + 1, $close - $i - 1)),
        'comma' => $next !== false && $tokens[$next]['value'] === ','
      ];
      $i = $close;
    }
    return $rows;
  }

  /** Checks whether INSERT VALUES rows are wide enough to require multiline layout. */
  private function hasLongInsertRow(array $rows): bool {
    foreach ($rows as $row) {
      if (count($row['items']) > 4) {
        return true;
      }
    }
    return false;
  }

  /** Formats grouped INSERT values with stable row indentation. */
  private function formatGroupedItems(array $items, int $itemIndent, int $closingIndent): string {
    $lines = [];
    $line = [];
    foreach ($items as $item) {
      $line[] = $this->formatInlineTokens($item);
      if (count($line) === 4) {
        $lines[] = $line;
        $line = [];
      }
    }
    if (!empty($line)) {
      $lines[] = $line;
    }
    if (count($lines) === 1) {
      return '(' . implode(', ', $lines[0]) . ')';
    }
    $out = '(' . "\n";
    foreach ($lines as $i => $lineItems) {
      $out .= str_repeat(self::INDENT, $itemIndent) . implode(', ', $lineItems);
      if ($i < count($lines) - 1) {
        $out .= ',';
      }
      $out .= "\n";
    }
    return $out . str_repeat(self::INDENT, $closingIndent) . ')';
  }

  /** Writes a token range as a compact inline SQL fragment. */
  private function formatInlineTokens(array $tokens): string {
    $out = '';
    $lineStart = true;
    foreach ($tokens as $i => $token) {
      if ($token['value'] === ';') {
        continue;
      }
      $prev = $this->previousMeaningful($tokens, $i);
      if ($token['value'] === ',') {
        $out = rtrim($out) . ', ';
        $lineStart = false;
        continue;
      }
      if ($token['value'] === '(') {
        if (!$lineStart && !$this->inlineNoSpaceBeforeParen($tokens, $prev) && !$this->endsWithAny($out, [' ', "\n"])) {
          $out .= ' ';
        }
        $out .= '(';
        $lineStart = false;
        continue;
      }
      if ($token['value'] === ')') {
        $out = rtrim($out) . ')';
        $lineStart = false;
        continue;
      }
      $this->append($out, $token['value'], $lineStart, $prev === false ? false : $tokens[$prev], $token);
    }
    return rtrim($out);
  }

}
