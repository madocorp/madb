<?php

namespace MADB\Tokenizer;

/** Tokenizes MongoDB command documents for editor highlighting. */
class MongoCommand extends \SPTK\Tokenizer {

  protected $stylePrefix = 'mongo-';
  protected $styleMap = [
    'KEYWORD' => 'keyword',
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

  /** Initializes MongoDB command document editor tokenizer state. */
  public function __construct() {
    $keywords = $this->wordPattern([
      'false', 'null', 'true'
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

(new MongoCommand)->initialize();
