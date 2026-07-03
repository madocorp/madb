<?php

namespace MADB\Tokenizer;

class SqlString extends \SPTK\Tokenizer {

  protected $stylePrefix = 'sql-';
  protected $styleMap = [
    'STRING' => 'string',
    'ERROR' => 'error'
  ];
  protected $contextSwitchers = [];
  protected $charRules = [
    '\\' => 'STRING'
  ];
  protected $regexpRules = [
    ['type' => 'STRING', 'regexp' => '/^[^\\\\\'"]+/'],
    ['type' => 'STRING', 'regexp' => '/^\\\\./'],
    ['type' => 'STRING', 'regexp' => '/^[\'"]{2}/'],
    ['type' => 'STRING', 'regexp' => '/^[\'"]/']
  ];

}

(new SqlString)->initialize();
