<?php

namespace MADB\Query\SqlFormatter;

/** Provides token navigation helpers for clause boundaries, parentheses, and top-level keyword searches. */
trait SqlFormatterTokenNavigationTrait {

  /** Coordinates clause end work in the SQL formatter. */
  private function clauseEnd(array $tokens, int $start): int {
    $depth = 0;
    for ($i = $start; $i < count($tokens); $i++) {
      if ($tokens[$i]['value'] === '(') {
        $depth++;
      } else if ($tokens[$i]['value'] === ')') {
        $depth = max(0, $depth - 1);
      }
      if ($depth === 0 && $tokens[$i]['type'] === self::TYPE_KEYWORD && in_array($tokens[$i]['upper'], array_merge(self::CLAUSE_KEYWORDS, self::JOIN_KEYWORDS, ['ON']), true)) {
        return $i;
      }
    }
    return count($tokens);
  }

  /** Coordinates matching paren work in the SQL formatter. */
  private function matchingParen(array $tokens, int $open): int|false {
    $depth = 0;
    for ($i = $open; $i < count($tokens); $i++) {
      if ($tokens[$i]['value'] === '(') {
        $depth++;
      } else if ($tokens[$i]['value'] === ')') {
        $depth--;
        if ($depth === 0) {
          return $i;
        }
      }
    }
    return false;
  }

  /** Finds top level keyword data inside the SQL formatter. */
  private function findTopLevelKeyword(array $tokens, string $keyword, int $start = 0): int|false {
    $depth = 0;
    for ($i = $start; $i < count($tokens); $i++) {
      if ($tokens[$i]['value'] === '(') {
        $depth++;
      } else if ($tokens[$i]['value'] === ')') {
        $depth = max(0, $depth - 1);
      } else if ($depth === 0 && $tokens[$i]['upper'] === $keyword) {
        return $i;
      }
    }
    return false;
  }

  /** Finds token value data inside the SQL formatter. */
  private function findTokenValue(array $tokens, string $value, int $start, int|false $end = false): int|false {
    $limit = $end === false ? count($tokens) : $end;
    for ($i = $start; $i < $limit; $i++) {
      if ($tokens[$i]['value'] === $value) {
        return $i;
      }
    }
    return false;
  }

  /** Coordinates next index work in the SQL formatter. */
  private function nextIndex(array $tokens, int $i): int|false {
    for ($j = $i + 1; $j < count($tokens); $j++) {
      return $j;
    }
    return false;
  }

}
