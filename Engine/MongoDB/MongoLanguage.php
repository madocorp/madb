<?php

namespace MADB\Engine\MongoDB;

class MongoLanguage extends \MADB\Engine\TextLanguage {

  private string|false $lastFormatError = false;

  private const TEMPLATES = [
    'FIND',
    'FIND filter',
    'FIND by _id',
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
    $this->lastFormatError = false;
    $text = trim($text);
    if ($text === '') {
      return '';
    }
    $text = self::stripComments($text);
    $statements = $this->split($text);
    if (count($statements) > 1) {
      $formatted = [];
      foreach ($statements as $statement) {
        $fragment = $this->formatJsonFragment((string)($statement['sql'] ?? ''));
        if ($fragment === false) {
          return $text;
        }
        $formatted[] = $fragment;
      }
      return implode("\n\n", $formatted);
    }
    $fragment = $this->formatJsonFragment($text);
    if ($fragment !== false) {
      return $fragment;
    }
    $decoded = json_decode($text);
    if (is_object($decoded) && isset($decoded->collection)) {
      $json = self::prettyJson($decoded);
      return $json === false ? $text : $json;
    }
    return $text;
  }

  public function lastFormatError() {
    return $this->lastFormatError;
  }

  public function split(string $text): array {
    $text = self::stripComments($text);
    $length = strlen($text);
    $offset = 0;
    $statements = [];
    while ($offset < $length) {
      while ($offset < $length && ctype_space($text[$offset])) {
        $offset++;
      }
      if ($offset >= $length) {
        break;
      }
      if ($text[$offset] !== '{') {
        return parent::split($text);
      }
      $end = $this->topLevelDocumentEnd($text, $offset);
      if ($end === false) {
        return parent::split($text);
      }
      $sql = substr($text, $offset, $end - $offset + 1);
      $statements[] = [
        'sql' => $sql,
        'start' => $offset,
        'end' => $end + 1
      ];
      $offset = $end + 1;
    }
    return $statements;
  }

  public function templates(): array {
    return self::TEMPLATES;
  }

  public function template(string $name) {
    $limit = $this->defaultSelectLimit();
    return match ($name) {
      'FIND' => '{
  "find": [TABLE],
  "filter": {},
  "limit": ' . $limit . '
}',
      'FIND filter' => '{
  "find": [TABLE],
  "filter": {
    "field": "value"
  },
  "limit": ' . $limit . '
}',
      'FIND by _id' => '{
  "find": [TABLE],
  "filter": {
    "_id": {"$oid": "000000000000000000000000"}
  },
  "limit": 1
}',
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

  private function formatJsonFragment(string $text) {
    $decoded = $this->decodeJsonFragment($text);
    if ($decoded === false) {
      return false;
    }
    return self::prettyJson($decoded);
  }

  public static function prettyJson($value) {
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
      return false;
    }
    return preg_replace_callback('/^( +)/m', fn($match) => str_repeat(' ', intdiv(strlen($match[1]), 2)), $json);
  }

  public static function quoteBareKeys(string $text): string {
    $result = '';
    $length = strlen($text);
    $expectKey = false;
    for ($i = 0; $i < $length; $i++) {
      $char = $text[$i];
      if ($char === '"' || $char === "'") {
        [$quoted, $i] = self::readQuotedText($text, $i, $char);
        $result .= $quoted;
        $expectKey = false;
        continue;
      }
      if ($char === '{' || $char === ',') {
        $result .= $char;
        $expectKey = true;
        continue;
      }
      if ($expectKey && ctype_space($char)) {
        $result .= $char;
        continue;
      }
      if ($expectKey && preg_match('/[A-Za-z_$]/', $char) === 1) {
        $start = $i;
        while ($i + 1 < $length && preg_match('/[A-Za-z0-9_$]/', $text[$i + 1]) === 1) {
          $i++;
        }
        $key = substr($text, $start, $i - $start + 1);
        $j = $i + 1;
        while ($j < $length && ctype_space($text[$j])) {
          $j++;
        }
        if (($text[$j] ?? '') === ':') {
          $quoted = json_encode($key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
          $result .= $quoted === false ? $key : $quoted;
          $expectKey = false;
          continue;
        }
        $result .= $key;
        $expectKey = false;
        continue;
      }
      $result .= $char;
      if (!ctype_space($char)) {
        $expectKey = false;
      }
    }
    return $result;
  }

  public static function stripComments(string $text): string {
    $result = '';
    $quote = false;
    $length = strlen($text);
    for ($i = 0; $i < $length; $i++) {
      $char = $text[$i];
      if ($quote !== false) {
        $result .= $char;
        if ($char === '\\') {
          if ($i + 1 < $length) {
            $i++;
            $result .= $text[$i];
          }
          continue;
        }
        if ($char === $quote) {
          $quote = false;
        }
        continue;
      }
      if ($char === '"' || $char === "'" || $char === '`') {
        $quote = $char;
        $result .= $char;
        continue;
      }
      if ($char === '/' && ($text[$i + 1] ?? '') === '/') {
        $i += 2;
        while ($i < $length && $text[$i] !== "\n") {
          $i++;
        }
        if ($i < $length) {
          $result .= "\n";
        }
        continue;
      }
      if ($char === '/' && ($text[$i + 1] ?? '') === '*') {
        $i += 2;
        $lineBreaks = '';
        while ($i < $length && !($text[$i] === '*' && ($text[$i + 1] ?? '') === '/')) {
          if ($text[$i] === "\n") {
            $lineBreaks .= "\n";
          }
          $i++;
        }
        if ($i < $length) {
          $i++;
        }
        $result .= $lineBreaks;
        continue;
      }
      $result .= $char;
    }
    return $result;
  }

  private function decodeJsonFragment(string $text) {
    $text = trim($text);
    if ($text === '') {
      return [];
    }
    $text = self::quoteBareKeys($text);
    try {
      if (class_exists('\MongoDB\BSON\Document', false) && method_exists('\MongoDB\BSON\Document', 'fromJSON') && method_exists('\MongoDB\BSON\Document', 'toRelaxedExtendedJSON')) {
        $json = \MongoDB\BSON\Document::fromJSON($text)->toRelaxedExtendedJSON();
        $decoded = json_decode($json);
      } else if (function_exists('MongoDB\BSON\fromJSON') && function_exists('MongoDB\BSON\toRelaxedExtendedJSON')) {
        $json = \MongoDB\BSON\toRelaxedExtendedJSON(\MongoDB\BSON\fromJSON($text));
        $decoded = json_decode($json);
      } else {
        $decoded = json_decode($text);
      }
    } catch (\Throwable $e) {
      $this->lastFormatError = $e->getMessage();
      return false;
    }
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
      $this->lastFormatError = json_last_error_msg();
      return false;
    }
    return (is_array($decoded) || is_object($decoded)) ? $decoded : false;
  }

  private static function readQuotedText(string $text, int $offset, string $quote): array {
    $length = strlen($text);
    for ($i = $offset + 1; $i < $length; $i++) {
      if ($text[$i] === '\\') {
        $i++;
        continue;
      }
      if ($text[$i] === $quote) {
        return [substr($text, $offset, $i - $offset + 1), $i];
      }
    }
    return [substr($text, $offset), $length - 1];
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

  private function defaultSelectLimit(): int {
    try {
      return \MADB\App\Settings::defaultSelectLimit();
    } catch (\Throwable $e) {
      return 1000;
    }
  }

  private function topLevelDocumentEnd(string $text, int $start) {
    $depth = 0;
    $quote = false;
    $length = strlen($text);
    for ($i = $start; $i < $length; $i++) {
      $char = $text[$i];
      if ($quote !== false) {
        if ($char === '\\') {
          $i++;
          continue;
        }
        if ($char === $quote) {
          $quote = false;
        }
        continue;
      }
      if ($char === '"' || $char === "'") {
        $quote = $char;
        continue;
      }
      if ($char === '{' || $char === '[') {
        $depth++;
        continue;
      }
      if ($char === '}' || $char === ']') {
        $depth--;
        if ($depth === 0) {
          return $i;
        }
        if ($depth < 0) {
          return false;
        }
      }
    }
    return false;
  }

}
