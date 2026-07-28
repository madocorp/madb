<?php

namespace MADB\Tokenizer;

/** Tokenizes MongoDB shell string fragments for query editor highlighting. */
class MongoString extends \SPTK\Tokenizer {

  protected $stylePrefix = 'mongo-';
  protected $styleMap = [
    'STRING' => 'string',
    'ERROR' => 'error'
  ];
  protected $contextSwitchers = [];
  protected $charRules = [
    '\\' => 'STRING'
  ];
  protected $regexpRules = [
    ['type' => 'STRING', 'regexp' => '/^[^\\\\\'"`]+/'],
    ['type' => 'STRING', 'regexp' => '/^\\\\./'],
    ['type' => 'STRING', 'regexp' => '/^[\'"`]{2}/'],
    ['type' => 'STRING', 'regexp' => '/^[\'"`]/']
  ];

}

(new MongoString)->initialize();
