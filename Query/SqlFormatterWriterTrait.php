<?php

namespace MADB\Query;

/** Marks aliases, conditions, table references, and spacing rules before SQL tokens are written. */
trait SqlFormatterWriterTrait {

  /** Coordinates inline no space before paren work in the SQL formatter. */
  private function inlineNoSpaceBeforeParen(array $tokens, int|false $prev): bool {
    if ($prev === false) {
      return false;
    }
    return $tokens[$prev]['type'] === self::TYPE_FUNCTION ||
      ($tokens[$prev]['type'] === self::TYPE_KEYWORD && in_array($tokens[$prev]['upper'], SqlLexicon::DATA_TYPES, true)) ||
      $tokens[$prev]['upper'] === 'IN';
  }

  /** Coordinates first alter action work in the SQL formatter. */
  private function firstAlterAction(array $tokens, int $start, int $end): int|false {
    $seenTable = false;
    for ($i = $start; $i < $end; $i++) {
      if (!$seenTable && in_array($tokens[$i]['type'], [self::TYPE_IDENTIFIER, self::TYPE_WORD], true)) {
        $seenTable = true;
        continue;
      }
      if ($seenTable && $tokens[$i]['value'] === '.') {
        $i++;
        continue;
      }
      if ($seenTable && $tokens[$i]['type'] === self::TYPE_KEYWORD) {
        return $i;
      }
    }
    return false;
  }

  /** Checks has top level condition for SQL formatter decisions. */
  private function hasTopLevelCondition(array $tokens, int $start, int $end): bool {
    $depth = 0;
    for ($i = $start; $i < $end; $i++) {
      if ($tokens[$i]['value'] === '(') {
        $depth++;
      } else if ($tokens[$i]['value'] === ')') {
        $depth = max(0, $depth - 1);
      } else if ($depth === 0 && in_array($tokens[$i]['upper'], ['AND', 'OR'], true)) {
        return true;
      }
    }
    return false;
  }

  /** Marks top level condition metadata used by the SQL formatter. */
  private function markTopLevelCondition(array &$tokens, int $start, int $end): void {
    $depth = 0;
    for ($i = $start; $i < $end; $i++) {
      if ($tokens[$i]['value'] === '(') {
        $depth++;
      } else if ($tokens[$i]['value'] === ')') {
        $depth = max(0, $depth - 1);
      } else if ($depth === 0 && in_array($tokens[$i]['upper'], ['AND', 'OR'], true)) {
        $tokens[$i]['condition-line-end'] = true;
      }
    }
  }

  /** Marks table alias metadata used by the SQL formatter. */
  private function markTableAlias(array &$tokens, int $start): void {
    $tableEnd = $this->tableReferenceEnd($tokens, $start);
    if ($tableEnd === false) {
      return;
    }
    $alias = $this->nextIdentifierLike($tokens, $tableEnd + 1);
    if ($alias === false) {
      return;
    }
    if ($tokens[$alias]['upper'] === 'AS') {
      return;
    }
    $next = $this->nextMeaningfulIn($tokens, $alias);
    if ($next !== false && in_array($tokens[$next]['upper'], array_merge(self::JOIN_KEYWORDS, self::CLAUSE_KEYWORDS, ['ON']), true)) {
      $tokens[$alias]['insert-as-before'] = true;
    }
  }

  /** Coordinates table reference end work in the SQL formatter. */
  private function tableReferenceEnd(array $tokens, int $start): int|false {
    $current = $this->nextIdentifierLike($tokens, $start);
    if ($current === false) {
      return false;
    }
    while (($next = $this->nextMeaningfulIn($tokens, $current)) !== false && $tokens[$next]['value'] === '.') {
      $part = $this->nextIdentifierLike($tokens, $next + 1);
      if ($part === false) {
        break;
      }
      $current = $part;
    }
    return $current;
  }

  /** Marks select aliases metadata used by the SQL formatter. */
  private function markSelectAliases(array &$tokens, int $start, int $end): void {
    $ranges = $this->topLevelItemRanges($tokens, $start, $end);
    foreach ($ranges as $range) {
      $alias = $this->implicitSelectAlias($tokens, $range[0], $range[1]);
      if ($alias !== false) {
        $tokens[$alias]['insert-as-before'] = true;
      }
    }
  }

  /** Coordinates top level item ranges work in the SQL formatter. */
  private function topLevelItemRanges(array $tokens, int $start, int $end): array {
    $ranges = [];
    $rangeStart = $start;
    $depth = 0;
    for ($i = $start; $i < $end; $i++) {
      if ($tokens[$i]['value'] === '(') {
        $depth++;
      } else if ($tokens[$i]['value'] === ')') {
        $depth = max(0, $depth - 1);
      } else if ($depth === 0 && $tokens[$i]['value'] === ',') {
        $ranges[] = [$rangeStart, $i];
        $rangeStart = $i + 1;
      }
    }
    if ($rangeStart < $end) {
      $ranges[] = [$rangeStart, $end];
    }
    return $ranges;
  }

  /** Coordinates implicit select alias work in the SQL formatter. */
  private function implicitSelectAlias(array $tokens, int $start, int $end): int|false {
    $meaningful = [];
    for ($i = $start; $i < $end; $i++) {
      if (in_array($tokens[$i]['type'], [self::TYPE_LINE_COMMENT, self::TYPE_BLOCK_COMMENT], true)) {
        return false;
      }
      if ($tokens[$i]['value'] !== ',') {
        $meaningful[] = $i;
      }
    }
    if (count($meaningful) < 2) {
      return false;
    }
    $alias = end($meaningful);
    $beforeAlias = prev($meaningful);
    if (
      !in_array($tokens[$alias]['type'], [self::TYPE_IDENTIFIER, self::TYPE_WORD], true) ||
      $tokens[$beforeAlias]['upper'] === 'AS' ||
      $tokens[$beforeAlias]['value'] === '.'
    ) {
      return false;
    }
    if ($this->rangeHasTopLevelKeyword($tokens, $start, $end, ['CASE', 'WHEN', 'THEN', 'ELSE', 'END'])) {
      return false;
    }
    return $alias;
  }

  /** Coordinates range has top level keyword work in the SQL formatter. */
  private function rangeHasTopLevelKeyword(array $tokens, int $start, int $end, array $keywords): bool {
    $depth = 0;
    for ($i = $start; $i < $end; $i++) {
      if ($tokens[$i]['value'] === '(') {
        $depth++;
      } else if ($tokens[$i]['value'] === ')') {
        $depth = max(0, $depth - 1);
      } else if ($depth === 0 && in_array($tokens[$i]['upper'], $keywords, true)) {
        return true;
      }
    }
    return false;
  }

  /** Coordinates next identifier like work in the SQL formatter. */
  private function nextIdentifierLike(array $tokens, int $start): int|false {
    for ($i = $start; $i < count($tokens); $i++) {
      if (in_array($tokens[$i]['type'], [self::TYPE_IDENTIFIER, self::TYPE_WORD], true)) {
        return $i;
      }
      if ($tokens[$i]['value'] !== '.') {
        return false;
      }
    }
    return false;
  }

  /** Coordinates next meaningful in work in the SQL formatter. */
  private function nextMeaningfulIn(array $tokens, int $i): int|false {
    for ($j = $i + 1; $j < count($tokens); $j++) {
      return $j;
    }
    return false;
  }

}
