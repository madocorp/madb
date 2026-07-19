<?php

namespace MADB\Query;

use \SPTK\SDLWrapper\KeyCombo;
use \SPTK\SDLWrapper\Action;
use \SPTK\SDLWrapper\KeyCode;
use \SPTK\SDLWrapper\KeyModifier;
use \SPTK\SDLWrapper\ScanCode;
use \SPTK\SDLWrapper\SDL;
use \SPTK\Element;
use \MADB\List\QueryList;
use \MADB\Result\ResultStore;
use \MADB\Query\SqlSplitter;

/** Provides text, cursor, regex, match, and highlight helpers used by the query editor search panel. */
trait ScreenSearchSupportTrait {

  /** Returns the current query editor text for search and replace. */
  private static function editorText() {
    $value = self::$editor->getValue();
    if (is_array($value)) {
      return implode("\n", $value);
    }
    return (string) $value;
  }

  /** Normalizes checkbox-like search panel values to booleans. */
  private static function boolValue($value): bool {
    return $value === true || $value === 'true' || $value === 1 || $value === '1';
  }

  /** Builds a regex pattern from search panel values. */
  private static function searchPattern($search, $regexp, $caseSensitive) {
    if ($search === '') {
      return false;
    }
    $body = $regexp ? str_replace('~', '\~', $search) : preg_quote($search, '~');
    return '~' . $body . '~u' . ($caseSensitive ? '' : 'i');
  }

  /** Checks whether a search regex can be compiled. */
  private static function validPattern($pattern): bool {
    set_error_handler(function() {
    });
    $valid = preg_match($pattern, '') !== false;
    restore_error_handler();
    return $valid;
  }

  /** Coordinates byte offset from position work in the query workspace. */
  private static function byteOffsetFromPosition($text, $row, $col): int {
    $lines = explode("\n", $text);
    $offset = 0;
    $maxRow = min(max(0, (int) $row), count($lines) - 1);
    for ($i = 0; $i < $maxRow; $i++) {
      $offset += strlen($lines[$i]) + 1;
    }
    return $offset + strlen(mb_substr($lines[$maxRow] ?? '', 0, max(0, (int) $col)));
  }

  /** Selects end offset from cursor state and refreshes related query workspace state. */
  private static function selectionEndOffsetFromCursorState($text, $state): int {
    $caret = $state['cursor']['caret'] ?? [0, 0];
    $anchor = $state['cursor']['anchor'] ?? $caret;
    $caretOffset = self::byteOffsetFromPosition($text, $caret[0] ?? 0, $caret[1] ?? 0);
    $anchorOffset = self::byteOffsetFromPosition($text, $anchor[0] ?? 0, $anchor[1] ?? 0);
    return max($caretOffset, $anchorOffset);
  }

  /** Selects start offset from cursor state and refreshes related query workspace state. */
  private static function selectionStartOffsetFromCursorState($text, $state): int {
    $caret = $state['cursor']['caret'] ?? [0, 0];
    $anchor = $state['cursor']['anchor'] ?? $caret;
    $caretOffset = self::byteOffsetFromPosition($text, $caret[0] ?? 0, $caret[1] ?? 0);
    $anchorOffset = self::byteOffsetFromPosition($text, $anchor[0] ?? 0, $anchor[1] ?? 0);
    return min($caretOffset, $anchorOffset);
  }

  /** Coordinates search start offset work in the query workspace. */
  private static function searchStartOffset($text, $state): int {
    $caret = $state['cursor']['caret'] ?? [0, 0];
    $anchor = $state['cursor']['anchor'] ?? $caret;
    if ($caret === $anchor) {
      return self::byteOffsetFromPosition($text, $caret[0] ?? 0, $caret[1] ?? 0);
    }
    return self::selectionEndOffsetFromCursorState($text, $state) + 1;
  }

  /** Coordinates position from byte offset work in the query workspace. */
  private static function positionFromByteOffset($text, $offset): array {
    $before = substr($text, 0, max(0, $offset));
    $lines = explode("\n", $before);
    return [count($lines) - 1, mb_strlen(end($lines))];
  }

  /** Coordinates cursor state for match work in the query workspace. */
  private static function cursorStateForMatch($text, $offset, $length, $state): array {
    $start = self::positionFromByteOffset($text, $offset);
    $end = self::positionFromByteOffset($text, max($offset, $offset + $length - 1));
    $state['cursor']['caret'] = $start;
    $state['cursor']['anchor'] = $end;
    $state['cursor']['caretBefore'] = $start;
    $state['cursor']['anchorBefore'] = $end;
    return $state;
  }

  /** Finds the next query editor match from a byte offset. */
  private static function findQueryMatch($text, $search, $regexp, $caseSensitive, $offset = 0) {
    if ($search === '') {
      return false;
    }
    if ($regexp) {
      $pattern = self::searchPattern($search, true, $caseSensitive);
      if ($pattern === false || !self::validPattern($pattern)) {
        return false;
      }
      if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE, $offset)) {
        return [$matches[0][1], strlen($matches[0][0]), $matches[0][0]];
      }
      return false;
    }
    $pos = $caseSensitive ? strpos($text, $search, $offset) : stripos($text, $search, $offset);
    if ($pos === false) {
      return false;
    }
    return [$pos, strlen($search), substr($text, $pos, strlen($search))];
  }

  /** Collects all query editor matches for highlighting. */
  private static function allQueryMatches($text, $search, $regexp, $caseSensitive): array {
    if ($search === '') {
      return [];
    }
    $matches = [];
    if ($regexp) {
      $pattern = self::searchPattern($search, true, $caseSensitive);
      if ($pattern === false || !self::validPattern($pattern)) {
        return [];
      }
      if (!preg_match_all($pattern, $text, $matchData, PREG_OFFSET_CAPTURE)) {
        return [];
      }
      foreach ($matchData[0] as $match) {
        $length = strlen($match[0]);
        if ($length > 0) {
          $matches[] = [$match[1], $length, $match[0]];
        }
      }
      return $matches;
    }
    $offset = 0;
    $length = strlen($search);
    while (($pos = ($caseSensitive ? strpos($text, $search, $offset) : stripos($text, $search, $offset))) !== false) {
      $matches[] = [$pos, $length, substr($text, $pos, $length)];
      $offset = $pos + $length;
    }
    return $matches;
  }

  /** Selects search scope and refreshes related query workspace state. */
  private static function selectedSearchScope($values): string {
    foreach (['All' => 'all', 'Previous' => 'previous', 'After' => 'after', 'Before' => 'before'] as $name => $scope) {
      if (self::boolValue($values['scope' . $name] ?? false)) {
        return $scope;
      }
    }
    return 'next';
  }

  /** Normalizes search panel state data for query workspace comparisons. */
  private static function normalizeSearchPanelState($values): array {
    $scope = self::selectedSearchScope($values);
    return [
      'search' => (string) ($values['search'] ?? ''),
      'replaceEnabled' => self::boolValue($values['replaceEnabled'] ?? false),
      'replace' => (string) ($values['replace'] ?? ''),
      'regexp' => self::boolValue($values['regexp'] ?? false),
      'caseSensitive' => self::boolValue($values['caseSensitive'] ?? false),
      'scopeAll' => $scope === 'all',
      'scopeNext' => $scope === 'next',
      'scopePrevious' => $scope === 'previous',
      'scopeAfter' => $scope === 'after',
      'scopeBefore' => $scope === 'before'
    ];
  }

  /** Coordinates scoped matches work in the query workspace. */
  private static function scopedMatches($text, $matches, $scope, $state): array {
    if ($scope === 'all' || $scope === 'next' || $scope === 'previous') {
      return $matches;
    }
    $cursorOffset = self::byteOffsetFromCursorState($text, $state);
    return array_values(array_filter($matches, function($match) use ($scope, $cursorOffset) {
      if ($scope === 'after') {
        return $match[0] >= $cursorOffset;
      }
      if ($scope === 'before') {
        return $match[0] + $match[1] <= $cursorOffset;
      }
      return true;
    }));
  }

  /** Coordinates byte offset from cursor state work in the query workspace. */
  private static function byteOffsetFromCursorState($text, $state): int {
    if (!is_array($state)) {
      return 0;
    }
    $cursor = $state['cursor']['caret'] ?? [0, 0];
    return self::byteOffsetFromPosition($text, $cursor[0] ?? 0, $cursor[1] ?? 0);
  }

  /** Coordinates pick match work in the query workspace. */
  private static function pickMatch($text, $matches, $scope, $state) {
    if (empty($matches)) {
      return [false, false];
    }
    if ($scope === 'previous') {
      $offset = self::selectionStartOffsetFromCursorState($text, $state);
      for ($i = count($matches) - 1; $i >= 0; $i--) {
        if ($matches[$i][0] < $offset) {
          return [$matches[$i], $i];
        }
      }
      return [$matches[count($matches) - 1], count($matches) - 1];
    }
    $offset = self::searchStartOffset($text, $state);
    foreach ($matches as $i => $match) {
      if ($match[0] >= $offset) {
        return [$match, $i];
      }
    }
    return [$matches[0], 0];
  }

  /** Converts search matches into editor highlight ranges. */
  private static function highlightRanges($text, $matches): array {
    $ranges = [];
    foreach ($matches as $match) {
      $start = self::positionFromByteOffset($text, $match[0]);
      $end = self::positionFromByteOffset($text, $match[0] + $match[1]);
      $ranges[] = [$start[0], $start[1], $end[0], $end[1]];
    }
    return $ranges;
  }

  /** Applies search highlights values to query workspace controls. */
  private static function applySearchHighlights($text, $matches): void {
    if (method_exists(self::$editor, 'setHighlightRanges')) {
      self::$editor->setHighlightRanges(self::highlightRanges($text, $matches));
    }
  }

  /** Coordinates search highlight matches work in the query workspace. */
  private static function searchHighlightMatches($matches, $scope, $index): array {
    if ($scope === 'next' || $scope === 'previous') {
      return [];
    }
    return $matches;
  }

  /** Clears search session state from the query workspace. */
  private static function clearSearchSession(): void {
    self::$searchSession = false;
    if (self::$editor !== null && method_exists(self::$editor, 'clearHighlightRanges')) {
      self::$editor->clearHighlightRanges();
    }
  }

  /** Replaces query editor text while preserving cursor state where possible. */
  private static function replaceEditorText($text): void {
    if (method_exists(self::$editor, 'replaceText')) {
      self::$editor->replaceText($text);
      return;
    }
    self::$editor->setValue($text);
  }

}
