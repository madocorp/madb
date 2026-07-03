<?php

namespace MADB\Query;

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
    'ACTION', 'ADD', 'AFTER', 'ALTER', 'AND', 'AS', 'ASC',
    'AUTO_INCREMENT', 'BETWEEN', 'BTREE', 'BY', 'CASCADE', 'CASE', 'CHARACTER',
    'CHARSET', 'COLLATE', 'COLUMN', 'COMMENT', 'CONSTRAINT', 'CREATE', 'CROSS', 'DEFAULT',
    'DELETE', 'DESC', 'DISTINCT', 'DROP', 'ELSE', 'END', 'ENGINE',
    'EXISTS', 'FIRST', 'FOREIGN', 'FROM', 'FULLTEXT', 'GROUP', 'HASH', 'HAVING', 'IF',
    'IGNORE', 'IN', 'INDEX', 'INNER', 'INSERT', 'INTERVAL', 'INTO', 'INVISIBLE',
    'IS', 'JOIN', 'KEY', 'LEFT', 'LIKE', 'LIMIT', 'NO', 'NOT', 'NULL',
    'ON', 'OR', 'ORDER', 'OUTER', 'PRIMARY', 'REFERENCES', 'RESTRICT',
    'RIGHT', 'RTREE', 'SELECT', 'SET', 'SIGNED', 'SPATIAL', 'TABLE', 'THEN', 'TO', 'UNION',
    'UNIQUE', 'UNSIGNED', 'UPDATE', 'USING', 'VALUES', 'VISIBLE', 'WHEN', 'WHERE', 'XOR',
    'ZEROFILL'
  ];

  public const FUNCTIONS = [
    'AVG', 'CAST', 'COALESCE', 'CONCAT', 'COUNT', 'DATE', 'IF', 'IFNULL',
    'LOWER', 'MAX', 'MIN', 'NOW', 'SUM', 'TRIM', 'UPPER', 'VALUES'
  ];

  public const BOUNDARIES = [
    ',', ';', ':', ')', '(', '.'
  ];

  public const OPERATORS = [
    '<>', '>=', '<=', '!=', ':=', '->>', '->', '&&', '||',
    '=', '<', '>', '+', '-', '*', '/', '%', '^', '|', '&', '!'
  ];

  public static function keywordPattern(): string {
    return self::wordPattern(array_merge(self::KEYWORDS, self::DATA_TYPES, self::CONSTANTS));
  }

  public static function functionPattern(): string {
    return self::wordPattern(self::FUNCTIONS);
  }

  public static function operatorPattern(): string {
    return self::symbolPattern(self::OPERATORS);
  }

  public static function boundaryPattern(): string {
    return self::symbolPattern(self::BOUNDARIES);
  }

  private static function wordPattern(array $words): string {
    usort($words, fn($a, $b) => strlen($b) <=> strlen($a));
    return implode('|', array_map(fn($word) => preg_quote($word, '/'), $words));
  }

  private static function symbolPattern(array $symbols): string {
    usort($symbols, fn($a, $b) => strlen($b) <=> strlen($a));
    return implode('|', array_map(fn($symbol) => preg_quote($symbol, '/'), $symbols));
  }

}
