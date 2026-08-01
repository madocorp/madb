<?php

namespace MADB\Tokenizer;

/** Tokenizes quoted MongoDB $ operation keys for query editor highlighting. */
class MongoOperationString extends \SPTK\Tokenizer {

  protected $stylePrefix = 'mongo-';
  protected $styleMap = [
    'OPERATION' => 'operation',
    'ERROR' => 'error'
  ];
  protected $contextSwitchers = [];
  protected $charRules = [];
  protected $regexpRules = [
    ['type' => 'OPERATION', 'regexp' => '/^[^\\\\\'"`]+/'],
    ['type' => 'OPERATION', 'regexp' => '/^\\\\./'],
    ['type' => 'OPERATION', 'regexp' => '/^[\'"`]{2}/'],
    ['type' => 'OPERATION', 'regexp' => '/^[\'"`]/']
  ];

}

(new MongoOperationString)->initialize();
