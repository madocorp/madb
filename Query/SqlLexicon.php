<?php

namespace MADB\Query;

/** Provides SQL keyword, function, operator, and boundary patterns for tokenizer and formatter code. */
class SqlLexicon {

  public const DATA_TYPES = [
    'BIGINT', 'BINARY', 'BIT', 'BLOB', 'BOOL', 'BOOLEAN', 'CHAR',
    'DATE', 'DATETIME', 'DEC', 'DECIMAL', 'DOUBLE', 'ENUM', 'FLOAT',
    'INT', 'INTEGER', 'JSON', 'LONGTEXT', 'MEDIUMINT', 'MEDIUMTEXT',
    'NUMERIC', 'REAL', 'SET', 'SMALLINT', 'TEXT', 'TIME', 'TIMESTAMP',
    'TINYINT', 'VARCHAR', 'YEAR'
  ];

  public const CONSTANTS = [
    'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'CURRENT_USER',
    'FALSE', 'NULL', 'TRUE'
  ];

  public const KEYWORDS = [
    'ACTION', 'ADD', 'AFTER', 'ALGORITHM', 'ALL', 'ALTER', 'AND', 'AS', 'ASC',
    'AUTO_INCREMENT', 'BETWEEN', 'BTREE', 'BY', 'CASCADE', 'CASE', 'CASCADED', 'CHARACTER',
    'CHARSET', 'CHECK', 'COLLATE', 'COLUMN', 'COMMENT', 'CONSTRAINT', 'CREATE', 'CROSS', 'DEFAULT',
    'DEFINER', 'DELETE', 'DESC', 'DISTINCT', 'DROP', 'ELSE', 'END', 'ENGINE',
    'EXISTS', 'FIRST', 'FOREIGN', 'FROM', 'FULLTEXT', 'GROUP', 'HASH', 'HAVING', 'IF',
    'IGNORE', 'IN', 'INDEX', 'INNER', 'INSERT', 'INTERVAL', 'INTO', 'INVISIBLE',
    'IS', 'JOIN', 'KEY', 'LEFT', 'LIKE', 'LIMIT', 'LOCAL', 'MERGE', 'MODIFY', 'NO', 'NOT', 'NULL',
    'ON', 'OPTION', 'OR', 'ORDER', 'OUTER', 'PRIMARY', 'REFERENCES', 'RENAME', 'REPLACE', 'RESTRICT',
    'RIGHT', 'RTREE', 'SECURITY', 'SELECT', 'SET', 'SIGNED', 'SPATIAL', 'SQL', 'TABLE', 'TEMPTABLE', 'THEN', 'TO', 'UNDEFINED', 'UNION',
    'UNIQUE', 'UNSIGNED', 'UPDATE', 'USING', 'VALUES', 'VIEW', 'VISIBLE', 'WHEN', 'WHERE', 'WITH', 'XOR',
    'ZEROFILL'
  ];

  public const FUNCTIONS = [
    'AVG', 'CAST', 'COALESCE', 'CONCAT', 'COUNT', 'DATE', 'IF', 'IFNULL',
    'LOWER', 'MAX', 'MIN', 'NOW', 'SUM', 'TRIM', 'UPPER', 'VALUES'
  ];

  public const BOUNDARIES = [
    ',', ';', ':', ')', '(', '.', '{', '}', '[', ']'
  ];

  public const OPERATORS = [
    '<>', '>=', '<=', '!=', ':=', '->>', '->', '&&', '||',
    '=', '<', '>', '+', '-', '*', '/', '%', '^', '|', '&', '!', '@'
  ];

  /** Coordinates keyword pattern work in the query support. */
  public static function keywordPattern(): string {
    return self::wordPattern(array_merge(self::KEYWORDS, self::DATA_TYPES, self::CONSTANTS));
  }

  /** Coordinates function pattern work in the query support. */
  public static function functionPattern(): string {
    return self::wordPattern(self::FUNCTIONS);
  }

  /** Coordinates operator pattern work in the query support. */
  public static function operatorPattern(): string {
    return self::symbolPattern(self::OPERATORS);
  }

  /** Coordinates boundary pattern work in the query support. */
  public static function boundaryPattern(): string {
    return self::symbolPattern(self::BOUNDARIES);
  }

  /** Coordinates word pattern work in the query support. */
  private static function wordPattern(array $words): string {
    usort($words, fn($a, $b) => strlen($b) <=> strlen($a));
    return implode('|', array_map(fn($word) => preg_quote($word, '/'), $words));
  }

  /** Coordinates symbol pattern work in the query support. */
  private static function symbolPattern(array $symbols): string {
    usort($symbols, fn($a, $b) => strlen($b) <=> strlen($a));
    return implode('|', array_map(fn($symbol) => preg_quote($symbol, '/'), $symbols));
  }

}
