<?php

namespace MADB\Tokenizer;

/** Tokenizes MongoDB command documents for editor highlighting. */
class MongoShell extends \SPTK\Tokenizer {

  protected $stylePrefix = 'mongo-';
  protected $styleMap = [
    'KEYWORD' => 'keyword',
    'METHOD' => 'method',
    'IDENTIFIER' => 'identifier',
    'FIELD' => 'field',
    'OPERATION' => 'operation',
    'STRING' => 'string',
    'NUMBER' => 'number',
    'VARIABLE' => 'variable',
    'OPERATOR' => 'operator',
    'BOUNDARY' => 'boundary',
    'COMMENT' => 'comment',
    'ERROR' => 'error'
  ];
  protected $contextSwitchers = [];
  protected $charRules = [];
  protected $regexpRules = [];

  /** Initializes MongoDB command editor tokenizer state. */
  public function __construct() {
    $methods = $this->wordPattern([
      'aggregate', 'countDocuments', 'deleteMany', 'deleteOne', 'distinct', 'find',
      'findOne', 'getCollection', 'getSiblingDB', 'insertMany', 'insertOne', 'limit',
      'replaceOne', 'sort', 'updateMany', 'updateOne'
    ]);
    $keywords = $this->wordPattern([
      'db', 'false', 'null', 'true'
    ]);
    $constructors = $this->wordPattern([
      'Binary', 'Code', 'Date', 'Decimal128', 'Int32', 'Long', 'MaxKey', 'MinKey',
      'NumberDecimal', 'NumberInt', 'NumberLong', 'ObjectId', 'RegExp', 'Timestamp',
      'UUID'
    ]);
    $operators = $this->symbolPattern([
      '===', '!==', '>=', '<=', '!=', '==', '=>', '&&', '||',
      '=', '<', '>', '+', '-', '*', '/', '%', '!', '?'
    ]);
    $boundaries = $this->symbolPattern([
      ',', ';', ':', '.', '(', ')', '{', '}', '[', ']'
    ]);
    $this->contextSwitchers = [
      [
        'startRegexp' => '/^"(?=\$[A-Za-z_][A-Za-z0-9_]*(?:\\\\.|[^"\\\\])*"\s*:)/',
        'end' => '"',
        'tokenizer' => '\MADB\Tokenizer\MongoOperationString',
        'type' => 'OPERATION'
      ],
      [
        'startRegexp' => "/^'(?=\\$[A-Za-z_][A-Za-z0-9_]*(?:\\\\.|[^'\\\\])*'\s*:)/",
        'end' => "'",
        'tokenizer' => '\MADB\Tokenizer\MongoOperationString',
        'type' => 'OPERATION'
      ],
      [
        'startRegexp' => '/^`(?=\$[A-Za-z_][A-Za-z0-9_]*(?:\\\\.|[^`\\\\])*`\s*:)/',
        'end' => '`',
        'tokenizer' => '\MADB\Tokenizer\MongoOperationString',
        'type' => 'OPERATION'
      ],
      [
        'startRegexp' => '/^"(?=(?:\\\\.|[^"\\\\])*"\s*:)/',
        'end' => '"',
        'tokenizer' => '\MADB\Tokenizer\MongoFieldString',
        'type' => 'FIELD'
      ],
      [
        'startRegexp' => "/^'(?=(?:\\\\.|[^'\\\\])*'\s*:)/",
        'end' => "'",
        'tokenizer' => '\MADB\Tokenizer\MongoFieldString',
        'type' => 'FIELD'
      ],
      [
        'startRegexp' => '/^`(?=(?:\\\\.|[^`\\\\])*`\s*:)/',
        'end' => '`',
        'tokenizer' => '\MADB\Tokenizer\MongoFieldString',
        'type' => 'FIELD'
      ],
      [
        'start' => "'",
        'end' => "'",
        'tokenizer' => '\MADB\Tokenizer\MongoString',
        'type' => 'STRING'
      ],
      [
        'start' => '"',
        'end' => '"',
        'tokenizer' => '\MADB\Tokenizer\MongoString',
        'type' => 'STRING'
      ],
      [
        'start' => '`',
        'end' => '`',
        'tokenizer' => '\MADB\Tokenizer\MongoString',
        'type' => 'STRING'
      ],
      [
        'start' => '/*',
        'end' => '*/',
        'tokenizer' => '\MADB\Tokenizer\MongoBlockComment',
        'type' => 'COMMENT'
      ]
    ];
    $this->regexpRules = [
      ['type' => 'COMMENT', 'regexp' => '/^\/\/.*/'],
      ['type' => 'METHOD', 'regexp' => '/^(' . $methods . ')(?=\s*\()/'],
      ['type' => 'METHOD', 'regexp' => '/^(' . $constructors . ')(?=\s*\()/'],
      ['type' => 'KEYWORD', 'regexp' => '/^(' . $keywords . ')(?=$|\s|[' . preg_quote('.,;:(){}[]=<>+-*/%!&|?', '/') . '])/'],
      ['type' => 'OPERATION', 'regexp' => '/^\$[A-Za-z_][A-Za-z0-9_]*/'],
      ['type' => 'FIELD', 'regexp' => '/^[A-Za-z_][A-Za-z0-9_$]*(?=\s*:)/'],
      ['type' => 'NUMBER', 'regexp' => '/^-?(?:0x[0-9a-fA-F]+|[0-9]+(?:\.[0-9]+)?(?:e[+-]?[0-9]+)?)/i'],
      ['type' => 'OPERATOR', 'regexp' => '/^(' . $operators . ')/'],
      ['type' => 'BOUNDARY', 'regexp' => '/^(' . $boundaries . ')/'],
      ['type' => 'IDENTIFIER', 'regexp' => '/^[A-Za-z_][A-Za-z0-9_$]*/'],
      ['type' => 'WHITESPACE', 'regexp' => '/^\s+/']
    ];
  }

  /** Coordinates word pattern work in the Mongo tokenizer. */
  private function wordPattern(array $words): string {
    usort($words, fn($a, $b) => strlen($b) <=> strlen($a));
    return implode('|', array_map(fn($word) => preg_quote($word, '/'), $words));
  }

  /** Coordinates symbol pattern work in the Mongo tokenizer. */
  private function symbolPattern(array $symbols): string {
    usort($symbols, fn($a, $b) => strlen($b) <=> strlen($a));
    return implode('|', array_map(fn($symbol) => preg_quote($symbol, '/'), $symbols));
  }

}

(new MongoShell)->initialize();
