<?php

namespace MADB\Engine\MongoDB;

class MongoLanguage extends \MADB\Engine\TextLanguage {

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
    $text = trim($text);
    if ($text === '') {
      return '';
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
    $json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? false : $json;
  }

  private function decodeJsonFragment(string $text) {
    $text = trim($text);
    if ($text === '') {
      return [];
    }
    try {
      if (class_exists('\MongoDB\BSON\Document', false) && method_exists('\MongoDB\BSON\Document', 'fromJSON') && method_exists('\MongoDB\BSON\Document', 'toRelaxedExtendedJSON')) {
        $json = \MongoDB\BSON\Document::fromJSON($text)->toRelaxedExtendedJSON();
        $decoded = json_decode($json, true);
      } else if (function_exists('MongoDB\BSON\fromJSON') && function_exists('MongoDB\BSON\toRelaxedExtendedJSON')) {
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

  private function defaultSelectLimit(): int {
    try {
      return \MADB\App\Settings::defaultSelectLimit();
    } catch (\Throwable $e) {
      return 1000;
    }
  }

}
