<?php

namespace MADB\Query\SqlFormatter;

/**
 * Formats SQL text used by the query editor. Tokenizer, list, statement, CASE, and writer behavior are split into focused traits.
 */
class SqlFormatter {

  use SqlFormatterTokenizerTrait;
  use SqlFormatterClassifierTrait;
  use SqlFormatterStatementTrait;
  use SqlFormatterListTrait;
  use SqlFormatterTokenNavigationTrait;
  use SqlFormatterWriterTrait;
  use SqlFormatterCaseWriterTrait;

  private const INDENT = '  ';

  private const TYPE_WORD = 'word';
  private const TYPE_KEYWORD = 'keyword';
  private const TYPE_FUNCTION = 'function';
  private const TYPE_IDENTIFIER = 'identifier';
  private const TYPE_STRING = 'string';
  private const TYPE_NUMBER = 'number';
  private const TYPE_VARIABLE = 'variable';
  private const TYPE_PLACEHOLDER = 'placeholder';
  private const TYPE_OPERATOR = 'operator';
  private const TYPE_BOUNDARY = 'boundary';
  private const TYPE_LINE_COMMENT = 'line-comment';
  private const TYPE_BLOCK_COMMENT = 'block-comment';

  private const CLAUSE_KEYWORDS = [
    'SELECT', 'FROM', 'WHERE', 'GROUP BY', 'ORDER BY', 'HAVING', 'LIMIT',
    'INSERT INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE FROM', 'CREATE TABLE', 'CREATE VIEW',
    'ALTER TABLE', 'DROP TABLE', 'RENAME TABLE', 'UNION', 'UNION ALL', 'ENGINE',
    'DEFAULT CHARSET', 'DEFAULT CHARACTER SET', 'COLLATE', 'COMMENT'
  ];

  private const JOIN_KEYWORDS = [
    'JOIN', 'INNER JOIN', 'LEFT JOIN', 'LEFT OUTER JOIN', 'RIGHT JOIN',
    'RIGHT OUTER JOIN', 'OUTER JOIN', 'CROSS JOIN'
  ];

  private const LIST_CLAUSES = [
    'SELECT', 'GROUP BY', 'ORDER BY', 'SET', 'VALUES'
  ];

  private const CONDITION_CLAUSES = [
    'WHERE', 'HAVING', 'ON'
  ];

  private array $tokens = [];

}
