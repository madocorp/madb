<?php

namespace MADB\Engine\MongoDB;

use SPTK\Core\SyntaxHighlighter;
use SPTK\Rendering\TextMetrics;

/** Color JSON command keys, strings, numbers, and literals. */
final class Highlighter implements SyntaxHighlighter {

  /** Return nonoverlapping grapheme ranges for one source line; no state is carried. */
  public function highlightLine(string $line, mixed $state): array {
    $tokens = [];
    $pattern = '/"(?:\\\\.|[^"\\\\])*"(?=\s*:)|"(?:\\\\.|[^"\\\\])*"|\b(?:true|false|null)\b|-?\d+(?:\.\d+)?/u';
    preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[0] as [$token, $byte]) {
      $after = substr($line, $byte + strlen($token));
      $style = $token[0] === '"' ? (preg_match('/^\s*:/', $after) ? 'key' : 'string') : 'literal';
      $tokens[] = [
        'start' => TextMetrics::length(substr($line, 0, $byte)),
        'length' => TextMetrics::length($token),
        'style' => $style,
      ];
    }
    return [$tokens, null];
  }

  /** Supply MongoDB command colors. */
  public function styleColors(): array {
    return [
      'key' => ['fg' => '#80cbc4'],
      'string' => ['fg' => '#ffd180'],
      'literal' => ['fg' => '#ce93d8'],
    ];
  }

}
