<?php

namespace MADB\Tokenizer;

/** Tokenizes sql block comment fragments for SQL editor highlighting. */
class SqlBlockComment extends \SPTK\Tokenizer {

  protected $stylePrefix = 'sql-';
  protected $styleMap = [
    'COMMENT' => 'comment',
    'ERROR' => 'error'
  ];
  protected $contextSwitchers = [];
  protected $charRules = [
    '*' => 'COMMENT',
    '/' => 'COMMENT'
  ];
  protected $regexpRules = [
    ['type' => 'COMMENT', 'regexp' => '/^[^*\/]+/']
  ];

}

(new SqlBlockComment)->initialize();
