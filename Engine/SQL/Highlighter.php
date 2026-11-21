<?php

namespace MADB\Engine\SQL;

use SPTK\Core\SyntaxHighlighter;
use SPTK\Rendering\TextMetrics;

/** Color SQL keywords, quoted values, and line comments in query editors. */
final class Highlighter implements SyntaxHighlighter {

  /** Return nonoverlapping grapheme ranges for one source line; no state is carried. */
  public function highlightLine(string $line, mixed $state): array {
    $tokens = [];
    $pattern = '/--[^\n]*|#[^\n]*|\x27(?:\x27\x27|[^\x27])*\x27|"(?:""|[^"])*"|\b(?:SELECT|INSERT|UPDATE|DELETE|FROM|WHERE|JOIN|LEFT|RIGHT|INNER|OUTER|ON|AS|INTO|VALUES|CREATE|ALTER|DROP|TABLE|VIEW|DATABASE|SHOW|USE|ORDER|GROUP|BY|LIMIT|OFFSET|AND|OR|NOT|NULL|IS|IN|SET|BEGIN|COMMIT|ROLLBACK|WITH|DISTINCT|HAVING|PRAGMA)\b/iu';
    preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[0] as [$token, $byte]) {
      $style = str_starts_with($token, '--') || str_starts_with($token, '#') ? 'comment'
        : ($token[0] === "'" || $token[0] === '"' ? 'string' : 'keyword');
      $tokens[] = [
        'start' => TextMetrics::length(substr($line, 0, $byte)),
        'length' => TextMetrics::length($token),
        'style' => $style,
      ];
    }
    return [$tokens, null];
  }

  /** Supply engine-neutral SQL syntax colors. */
  public function styleColors(): array {
    return [
      'keyword' => ['fg' => '#80cbc4'],
      'string' => ['fg' => '#ffd180'],
      'comment' => ['fg' => '#90a4ae'],
    ];
  }

}
