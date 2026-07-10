<?php

namespace MADB\Tokenizer;

/** Tokenizes sql fragments for SQL editor highlighting. */
class Sql extends \SPTK\Tokenizer {

  protected $stylePrefix = 'sql-';
  protected $styleMap = [
    'KEYWORD' => 'keyword',
    'FUNCTION' => 'function',
    'IDENTIFIER' => 'identifier',
    'STRING' => 'string',
    'NUMBER' => 'number',
    'VARIABLE' => 'variable',
    'PLACEHOLDER' => 'placeholder',
    'OPERATOR' => 'operator',
    'BOUNDARY' => 'boundary',
    'COMMENT' => 'comment',
    'ERROR' => 'error'
  ];
  protected $contextSwitchers = [];
  protected $charRules = [];
  protected $regexpRules = [];

  /** Initializes SQL editor tokenizer state. */
  public function __construct() {
    $keyword = \MADB\Query\SqlLexicon::keywordPattern();
    $function = \MADB\Query\SqlLexicon::functionPattern();
    $operator = \MADB\Query\SqlLexicon::operatorPattern();
    $boundary = \MADB\Query\SqlLexicon::boundaryPattern();
    $this->contextSwitchers = [
      [
        'start' => "'",
        'end' => "'",
        'tokenizer' => '\MADB\Tokenizer\SqlString',
        'type' => 'STRING'
      ],
      [
        'start' => '"',
        'end' => '"',
        'tokenizer' => '\MADB\Tokenizer\SqlString',
        'type' => 'STRING'
      ],
      [
        'start' => '`',
        'end' => '`',
        'tokenizer' => '\MADB\Tokenizer\SqlIdentifier',
        'type' => 'IDENTIFIER'
      ],
      [
        'start' => '/*',
        'end' => '*/',
        'tokenizer' => '\MADB\Tokenizer\SqlBlockComment',
        'type' => 'COMMENT'
      ]
    ];
    $this->regexpRules = [
      ['type' => 'COMMENT', 'regexp' => '/^(?:--|#).*/'],
      ['type' => 'FUNCTION', 'regexp' => '/^(' . $function . ')(?=\s*\()/i'],
      ['type' => 'KEYWORD', 'regexp' => '/^(' . $keyword . ')(?=$|\s|[' . preg_quote('(),;.:=<>+-*/%^|&!', '/') . '])/i'],
      ['type' => 'VARIABLE', 'regexp' => '/^@@?[A-Za-z0-9_.$]+/'],
      ['type' => 'PLACEHOLDER', 'regexp' => '/^(\?|\[[A-Za-z_][A-Za-z0-9_]*\]|:[A-Za-z_][A-Za-z0-9_]*)/'],
      ['type' => 'NUMBER', 'regexp' => '/^(0x[0-9a-fA-F]+|0b[01]+|[0-9]+(?:\.[0-9]+)?)/'],
      ['type' => 'OPERATOR', 'regexp' => '/^(' . $operator . ')/'],
      ['type' => 'BOUNDARY', 'regexp' => '/^(' . $boundary . ')/'],
      ['type' => 'IDENTIFIER', 'regexp' => '/^[A-Za-z_][A-Za-z0-9_$]*/'],
      ['type' => 'WHITESPACE', 'regexp' => '/^\s+/']
    ];
  }

}

(new Sql)->initialize();
