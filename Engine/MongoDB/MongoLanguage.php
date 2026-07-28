<?php

namespace MADB\Engine\MongoDB;

class MongoLanguage extends \MADB\Engine\TextLanguage {

  private const INLINE_JSON_LIMIT = 90;

  private const TEMPLATES = [
    'FIND',
    'FIND filter',
    'FIND by _id',
    'REPLACE ONE',
    'PART filter ObjectId',
    'PART filter $in',
    'PART filter exists',
    'PART filter date range',
    'PART filter regex',
    'PART projection include',
    'PART sort descending',
    'PART document',
    'PART update $set',
    'PART update $inc',
    'PART update $unset'
  ];

  public function format(string $text): string {
    $text = trim($text);
    if ($text === '') {
      return '';
    }
    $shell = $this->formatShellQuery($text);
    if ($shell !== false) {
      return $shell;
    }
    $fragment = $this->formatJsonFragment($text);
    if ($fragment !== false) {
      return $fragment;
    }
    $decoded = json_decode($text, true);
    if (is_array($decoded) && isset($decoded['collection'])) {
      $json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      return $json === false ? $text : $json;
    }
    return $text;
  }

  public function templates(): array {
    return self::TEMPLATES;
  }

  public function template(string $name) {
    $limit = $this->defaultSelectLimit();
    return match ($name) {
      'FIND' => 'db.getSiblingDB([DB]).getCollection([TABLE]).find({}).limit(' . $limit . ');',
      'FIND filter' => 'db.getSiblingDB([DB]).getCollection([TABLE]).find({
  "field": "value"
}).limit(' . $limit . ');',
      'FIND by _id' => 'db.getSiblingDB([DB]).getCollection([TABLE]).find({
  "_id": {"$oid": "000000000000000000000000"}
}).limit(' . $limit . ');',
      'REPLACE ONE' => 'db.getSiblingDB([DB]).getCollection([TABLE]).replaceOne(
  {"_id": {"$oid": "000000000000000000000000"}},
  {
    "_id": {"$oid": "000000000000000000000000"},
    "field": "value"
  }
);',
      'PART filter ObjectId' => '"_id": {"$oid": "000000000000000000000000"}',
      'PART filter $in' => '"field": {"$in": ["value1", "value2"]}',
      'PART filter exists' => '"field": {"$exists": true}',
      'PART filter date range' => '"createdAt": {
  "$gte": {"$date": "2026-01-01T00:00:00Z"},
  "$lt": {"$date": "2026-02-01T00:00:00Z"}
}',
      'PART filter regex' => '"field": {"$regex": "text", "$options": "i"}',
      'PART projection include' => '{"field": 1, "_id": 0}',
      'PART sort descending' => '{"createdAt": -1}',
      'PART document' => '{
  "field": "value",
  "enabled": true
}',
      'PART update $set' => '{"$set": {"field": "value"}}',
      'PART update $inc' => '{"$inc": {"counter": 1}}',
      'PART update $unset' => '{"$unset": {"field": ""}}',
      default => false
    };
  }

  public function fillTemplate(string $template, $primary = null, $secondary = null, $fields = null): string {
    return str_replace(
      ['[DB]', '[TABLE]', '[LIMIT]'],
      [
        $this->jsonString((string)($primary ?? '')),
        $this->jsonString((string)($secondary ?? '')),
        (string)$this->defaultSelectLimit()
      ],
      $template
    );
  }

  private function jsonString(string $value): string {
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? '""' : $json;
  }

  private function formatShellQuery(string $text) {
    $semicolon = str_ends_with(rtrim($text), ';');
    $text = rtrim($text, " \t\r\n;");
    $pattern = '/^db(?:\.getSiblingDB\(\s*((?:"(?:\\\\.|[^"\\\\])*")|(?:\'(?:\\\\.|[^\'\\\\])*\'))\s*\))?\.getCollection\(\s*((?:"(?:\\\\.|[^"\\\\])*")|(?:\'(?:\\\\.|[^\'\\\\])*\'))\s*\)\.(find|replaceOne)\(/s';
    if (!preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE)) {
      return false;
    }
    $database = isset($match[1][0]) && $match[1][0] !== '' ? $match[1][0] : false;
    $collection = $match[2][0];
    $operation = $match[3][0];
    $argsStart = $match[0][1] + strlen($match[0][0]);
    $argsEnd = $this->matchingParenthesisOffset($text, $argsStart - 1);
    if ($argsEnd === false) {
      return false;
    }
    $tail = trim(substr($text, $argsEnd + 1));
    $prefix = 'db';
    if ($database !== false) {
      $prefix .= "\n  .getSiblingDB(" . $database . ')';
    }
    $prefix .= "\n  .getCollection(" . $collection . ')';
    if ($operation === 'find') {
      $filter = $this->formatJsonArgument(trim(substr($text, $argsStart, $argsEnd - $argsStart)));
      if ($filter === false) {
        return false;
      }
      $formatted = $prefix . $this->formatMethodCall('find', [$filter]);
      $tail = $this->formatMethodTail($tail);
      if ($tail === false) {
        return false;
      }
      return $formatted . $tail . ($semicolon ? ';' : '');
    }
    $arguments = $this->topLevelArguments(substr($text, $argsStart, $argsEnd - $argsStart));
    if (count($arguments) !== 2) {
      return false;
    }
    $filter = $this->formatJsonArgument($arguments[0]);
    $replacement = $this->formatJsonArgument($arguments[1]);
    if ($filter === false || $replacement === false || $tail !== '') {
      return false;
    }
    return $prefix . $this->formatMethodCall('replaceOne', [$filter, $replacement]) . ($semicolon ? ';' : '');
  }

  private function formatMethodTail(string $tail) {
    if ($tail === '') {
      return '';
    }
    $formatted = '';
    while ($tail !== '') {
      if (!preg_match('/^\.([A-Za-z_][A-Za-z0-9_]*)\(/', $tail, $match, PREG_OFFSET_CAPTURE)) {
        return false;
      }
      $method = $match[1][0];
      $argsStart = strlen($match[0][0]);
      $argsEnd = $this->matchingParenthesisOffset($tail, $argsStart - 1);
      if ($argsEnd === false) {
        return false;
      }
      $args = trim(substr($tail, $argsStart, $argsEnd - $argsStart));
      $formattedArgs = $this->formatJsonArgument($args);
      if ($formattedArgs === false) {
        $formattedArgs = $args;
      }
      $formatted .= $this->formatMethodCall($method, [$formattedArgs]);
      $tail = trim(substr($tail, $argsEnd + 1));
    }
    return $formatted;
  }

  private function formatMethodCall(string $method, array $arguments): string {
    $singleLine = '.' . $method . '(' . implode(', ', $arguments) . ')';
    $hasMultiline = count(array_filter($arguments, fn($argument) => str_contains($argument, "\n"))) > 0;
    if (!$hasMultiline && strlen($singleLine) <= self::INLINE_JSON_LIMIT) {
      return "\n  " . $singleLine;
    }
    return "\n  ." . $method . "(\n" .
      $this->indent(implode(",\n", $arguments), 4) . "\n" .
      '  )';
  }

  private function formatJsonArgument(string $text) {
    $decoded = $this->decodeJsonFragment($text);
    if ($decoded === false) {
      return false;
    }
    $inline = $this->inlineJson($decoded);
    if ($inline !== false && strlen($inline) <= self::INLINE_JSON_LIMIT) {
      return $inline;
    }
    $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $pretty === false ? false : $pretty;
  }

  private function formatJsonFragment(string $text) {
    $decoded = $this->decodeJsonFragment($text);
    if ($decoded === false) {
      return false;
    }
    $json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? false : $json;
  }

  private function decodeJsonFragment(string $text) {
    $text = trim($text);
    if ($text === '') {
      return [];
    }
    try {
      if (function_exists('MongoDB\BSON\fromJSON') && function_exists('MongoDB\BSON\toRelaxedExtendedJSON')) {
        $json = \MongoDB\BSON\toRelaxedExtendedJSON(\MongoDB\BSON\fromJSON($text));
        $decoded = json_decode($json, true);
      } else {
        $decoded = json_decode($text, true);
      }
    } catch (\Throwable $e) {
      return false;
    }
    return is_array($decoded) ? $decoded : false;
  }

  private function inlineJson($value) {
    if (is_array($value)) {
      $parts = [];
      if (array_is_list($value)) {
        foreach ($value as $item) {
          $json = $this->inlineJson($item);
          if ($json === false) {
            return false;
          }
          $parts[] = $json;
        }
        return '[' . implode(', ', $parts) . ']';
      }
      foreach ($value as $key => $item) {
        $json = $this->inlineJson($item);
        $name = json_encode((string)$key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || $name === false) {
          return false;
        }
        $parts[] = $name . ': ' . $json;
      }
      return '{' . implode(', ', $parts) . '}';
    }
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? false : $json;
  }

  private function topLevelArguments(string $text): array {
    $arguments = [];
    $start = 0;
    $depth = 0;
    $length = strlen($text);
    for ($i = 0; $i < $length; $i++) {
      $char = $text[$i];
      if ($char === '"' || $char === "'" || $char === '`') {
        $i = $this->skipQuotedString($text, $i, $char);
      } else if ($char === '{' || $char === '[' || $char === '(') {
        $depth++;
      } else if ($char === '}' || $char === ']' || $char === ')') {
        $depth = max(0, $depth - 1);
      } else if ($char === ',' && $depth === 0) {
        $arguments[] = trim(substr($text, $start, $i - $start));
        $start = $i + 1;
      }
    }
    $arguments[] = trim(substr($text, $start));
    return array_values(array_filter($arguments, fn($argument) => $argument !== ''));
  }

  private function matchingParenthesisOffset(string $text, int $openOffset) {
    $depth = 0;
    $length = strlen($text);
    for ($i = $openOffset; $i < $length; $i++) {
      $char = $text[$i];
      if ($char === '"' || $char === "'" || $char === '`') {
        $i = $this->skipQuotedString($text, $i, $char);
        continue;
      }
      if ($char === '(') {
        $depth++;
      } else if ($char === ')') {
        $depth--;
        if ($depth === 0) {
          return $i;
        }
      }
    }
    return false;
  }

  private function skipQuotedString(string $text, int $offset, string $quote): int {
    $length = strlen($text);
    for ($i = $offset + 1; $i < $length; $i++) {
      if ($text[$i] === '\\') {
        $i++;
      } else if ($text[$i] === $quote) {
        return $i;
      }
    }
    return $length - 1;
  }

  private function indent(string $text, int $spaces): string {
    $prefix = str_repeat(' ', $spaces);
    return $prefix . str_replace("\n", "\n" . $prefix, $text);
  }

  private function defaultSelectLimit(): int {
    try {
      return \MADB\App\Settings::defaultSelectLimit();
    } catch (\Throwable $e) {
      return 1000;
    }
  }

}
