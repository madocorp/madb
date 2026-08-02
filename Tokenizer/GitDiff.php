<?php

namespace MADB\Tokenizer;

/** Tokenizes Git diff output for the Git workspace diff viewer. */
class GitDiff extends \SPTK\Tokenizer {

  protected $stylePrefix = 'git-diff-';
  protected $styleMap = [
    'HEADER' => 'header',
    'FILE' => 'file',
    'ROW' => 'row',
    'ADD' => 'add',
    'DELETE' => 'delete'
  ];
  protected $regexpRules = [
    ['type' => 'FILE', 'regexp' => '/^(diff --git .*|(?:\+\+\+|---) .*)/'],
    ['type' => 'ROW', 'regexp' => '/^@@.*?@@/'],
    ['type' => 'ADD', 'regexp' => '/^\+.*/'],
    ['type' => 'DELETE', 'regexp' => '/^-.*/'],
    ['type' => 'HEADER', 'regexp' => '/^(index .*|new file mode .*|deleted file mode .*|similarity index .*|dissimilarity index .*|rename from .*|rename to .*|copy from .*|copy to .*|old mode .*|new mode .*)/'],
    ['type' => 'TEXT', 'regexp' => '/^.*/']
  ];

}

(new GitDiff)->initialize();
