<?php

namespace MADB\Query;

class SqlFormatter {

  private const INDENT = '  ';

  private const TYPE_WORD = 'word';
  private const TYPE_KEYWORD = 'keyword';
  private const TYPE_FUNCTION = 'function';
  private const TYPE_IDENTIFIER = 'identifier';
  private const TYPE_STRING = 'string';
  private const TYPE_NUMBER = 'number';
  private const TYPE_VARIABLE = 'variable';
  private const TYPE_PLACEHOLDER = 'placeholder';
  private const TYPE_OPERATOR = 'operator';
  private const TYPE_BOUNDARY = 'boundary';
  private const TYPE_LINE_COMMENT = 'line-comment';
  private const TYPE_BLOCK_COMMENT = 'block-comment';

  private const CLAUSE_KEYWORDS = [
    'SELECT', 'FROM', 'WHERE', 'GROUP BY', 'ORDER BY', 'HAVING', 'LIMIT',
    'INSERT INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE FROM', 'CREATE TABLE',
    'ALTER TABLE', 'DROP TABLE', 'UNION', 'UNION ALL', 'ENGINE',
    'DEFAULT CHARSET', 'DEFAULT CHARACTER SET', 'COLLATE', 'COMMENT'
  ];

  private const JOIN_KEYWORDS = [
    'JOIN', 'INNER JOIN', 'LEFT JOIN', 'LEFT OUTER JOIN', 'RIGHT JOIN',
    'RIGHT OUTER JOIN', 'OUTER JOIN', 'CROSS JOIN'
  ];

  private const LIST_CLAUSES = [
    'SELECT', 'GROUP BY', 'ORDER BY', 'SET', 'VALUES'
  ];

  private const CONDITION_CLAUSES = [
    'WHERE', 'HAVING'
  ];

  private array $tokens = [];

  public static function format(string $sql): string {
    $formatter = new self($sql);
    return $formatter->run();
  }

  private function __construct(string $sql) {
    $this->tokens = $this->mergeKeywords($this->tokenize($sql));
    $this->classifyFunctions();
    $this->quoteIdentifiers();
  }

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
      if (preg_match('/^[=<>+\-*\/%^|&!]/', $remaining, $match)) {
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

  private function token(string $type, string $value): array {
    return [
      'type' => $type,
      'value' => $value,
      'upper' => strtoupper($value)
    ];
  }

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
      'CREATE' => ['TABLE'],
      'ALTER' => ['TABLE'],
      'DROP' => ['TABLE'],
      'DEFAULT' => ['CHARSET'],
      'CHARACTER' => ['SET'],
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

  private function classifyFunctions(): void {
    foreach ($this->tokens as $i => &$token) {
      if (!in_array($token['type'], [self::TYPE_WORD, self::TYPE_KEYWORD], true)) {
        continue;
      }
      $next = $this->nextMeaningful($i);
      if (
        $next !== false &&
        $this->tokens[$next]['value'] === '(' &&
        !in_array($token['upper'], SqlLexicon::DATA_TYPES, true) &&
        !$this->isQualifiedIdentifierPart($i) &&
        !$this->isIdentifierBeforeParenthesis($i) &&
        ($token['type'] === self::TYPE_WORD || in_array($token['upper'], SqlLexicon::FUNCTIONS, true))
      ) {
        $token['type'] = self::TYPE_FUNCTION;
        $token['value'] = strtoupper($token['value']);
        $token['upper'] = $token['value'];
      } else if (in_array($token['upper'], SqlLexicon::FUNCTIONS, true)) {
        $token['type'] = self::TYPE_FUNCTION;
        $token['value'] = strtoupper($token['value']);
      }
    }
  }

  private function isIdentifierBeforeParenthesis(int $i): bool {
    $prev = $this->previousMeaningful($this->tokens, $i);
    if ($prev === false) {
      return false;
    }
    $previous = $this->tokens[$prev]['upper'];
    return in_array($previous, [
      'INSERT INTO', 'CREATE TABLE', 'ALTER TABLE', 'DROP TABLE',
      'KEY', 'PRIMARY KEY', 'UNIQUE KEY', 'FOREIGN KEY', 'REFERENCES'
    ], true);
  }

  private function isQualifiedIdentifierPart(int $i): bool {
    $prev = $this->previousMeaningful($this->tokens, $i);
    return $prev !== false && $this->tokens[$prev]['value'] === '.';
  }

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

  private function shouldQuoteWord(int $i): bool {
    $word = strtoupper($this->tokens[$i]['value']);
    if (in_array($word, SqlLexicon::CONSTANTS, true)) {
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

  private function quoteIdentifier(string $identifier): string {
    return '`' . str_replace('`', '``', $identifier) . '`';
  }

  private function nextMeaningful(int $i): int|false {
    for ($j = $i + 1; $j < count($this->tokens); $j++) {
      return $j;
    }
    return false;
  }

  private function previousMeaningful(array $tokens, int $i): int|false {
    for ($j = $i - 1; $j >= 0; $j--) {
      return $j;
    }
    return false;
  }

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

  private function formatTokens(array $tokens, int $indent = 0): string {
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

      if (isset($token['line-before-indent'])) {
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

      if ($token['type'] === self::TYPE_LINE_COMMENT) {
        $this->append($out, $value, $lineStart, $tokens[$prev] ?? false, $token);
        $this->newline($out, $indent + $depth, $lineStart);
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
      if ($tokens[$i]['type'] === self::TYPE_KEYWORD && in_array($tokens[$i]['upper'], self::CONDITION_CLAUSES, true)) {
        $end = $this->clauseEnd($tokens, $i + 1);
        if ($this->hasTopLevelCondition($tokens, $i + 1, $end)) {
          $tokens[$i]['condition-list-start'] = true;
          $this->markTopLevelCondition($tokens, $i + 1, $end);
        }
      }
      if ($tokens[$i]['value'] === '(') {
        $end = $this->matchingParen($tokens, $i);
        if ($end !== false && !$this->isCompactParenthesis($tokens, $i) && $this->hasTopLevelComma($tokens, $i + 1, $end)) {
          $tokens[$i]['multiline-paren'] = true;
          $tokens[$end]['multiline-paren-close'] = true;
          $this->markTopLevelList($tokens, $i + 1, $end, true);
        }
      }
    }
    return $tokens;
  }

  private function isCompactDataTypeKeyword(array $tokens, int $i): bool {
    if (!in_array($tokens[$i]['upper'], SqlLexicon::DATA_TYPES, true)) {
      return false;
    }
    $next = $i + 1;
    return isset($tokens[$next]) && $tokens[$next]['value'] === '(';
  }

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

  private function startsNewLine(array $token, int $depth, string $clause, array|false $prev): bool {
    if ($depth !== 0 || $prev === false || $token['type'] !== self::TYPE_KEYWORD) {
      return false;
    }
    if (in_array($token['upper'], array_merge(self::CLAUSE_KEYWORDS, self::JOIN_KEYWORDS), true)) {
      return true;
    }
    return false;
  }

  private function isClauseKeyword(string $upper): bool {
    return in_array($upper, array_merge(self::CLAUSE_KEYWORDS, self::JOIN_KEYWORDS), true);
  }

  private function isMultilineComma(array $token, int $depth, string $clause): bool {
    return ($token['multiline-list'] ?? false) === true;
  }

  private function append(string &$out, string $value, bool &$lineStart, array|false $prev, array $token): void {
    if (!$lineStart && $this->needsSpace($prev, $token) && !$this->endsWithAny($out, [' ', "\n"])) {
      $out .= ' ';
    }
    $out .= $value;
    $lineStart = false;
  }

  private function needsSpace(array|false $prev, array $token): bool {
    if ($prev === false) {
      return false;
    }
    if ($token['value'] === '.' || $prev['value'] === '.') {
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

  private function newline(string &$out, int $indent, bool &$lineStart): void {
    $out = rtrim($out);
    if ($out !== '') {
      $out .= "\n";
    }
    $out .= str_repeat(self::INDENT, max(0, $indent));
    $lineStart = true;
  }

  private function endsWithAny(string $text, array $needles): bool {
    foreach ($needles as $needle) {
      if ($needle !== '' && str_ends_with($text, $needle)) {
        return true;
      }
    }
    return false;
  }

}
