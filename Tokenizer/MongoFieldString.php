<?php

namespace MADB\Tokenizer;

/** Tokenizes quoted MongoDB object field names for query editor highlighting. */
class MongoFieldString extends \SPTK\Tokenizer {

  protected $stylePrefix = 'mongo-';
  protected $styleMap = [
    'FIELD' => 'field',
    'ERROR' => 'error'
  ];
  protected $contextSwitchers = [];
  protected $charRules = [];
  protected $regexpRules = [
    ['type' => 'FIELD', 'regexp' => '/^[^\\\\\'"`]+/'],
    ['type' => 'FIELD', 'regexp' => '/^\\\\./'],
    ['type' => 'FIELD', 'regexp' => '/^[\'"`]{2}/'],
    ['type' => 'FIELD', 'regexp' => '/^[\'"`]/']
  ];

}

(new MongoFieldString)->initialize();
