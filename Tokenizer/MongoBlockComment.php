<?php

namespace MADB\Tokenizer;

/** Tokenizes MongoDB shell block comments for query editor highlighting. */
class MongoBlockComment extends \SPTK\Tokenizer {

  protected $stylePrefix = 'mongo-';
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
    ['type' => 'COMMENT', 'regexp' => '/^[^*\/]+/'],
    ['type' => 'COMMENT', 'regexp' => '/^\*\//']
  ];

}

(new MongoBlockComment)->initialize();
