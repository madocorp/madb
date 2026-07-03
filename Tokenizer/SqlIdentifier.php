<?php

namespace MADB\Tokenizer;

class SqlIdentifier extends \SPTK\Tokenizer {

  protected $stylePrefix = 'sql-';
  protected $styleMap = [
    'IDENTIFIER' => 'identifier',
    'ERROR' => 'error'
  ];
  protected $contextSwitchers = [];
  protected $charRules = [
    '`' => 'IDENTIFIER'
  ];
  protected $regexpRules = [
    ['type' => 'IDENTIFIER', 'regexp' => '/^[^`]+/']
  ];

}

(new SqlIdentifier)->initialize();
