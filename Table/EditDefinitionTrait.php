<?php

namespace MADB\Table;

/**
 * Builds normalized table, column, index, foreign-key, and trigger definitions used by the table editor SQL generator.
 */
trait EditDefinitionTrait {

  /** Builds the query tab name for generated table editor SQL. */
  private static function queryName($prefix, $table) {
    return $prefix . ' ' . self::$schema . '.' . $table;
  }

  /** Converts nullable metadata values to strings before comparison or SQL generation. */
  private static function normalizeValue($value) {
    return $value === null ? '' : (string) $value;
  }

  /** Builds a column DEFAULT clause from table metadata. */
  private static function defaultClause($value) {
    if ($value === null || $value === '') {
      return '';
    }
    $upper = strtoupper((string) $value);
    if (in_array($upper, ['NULL', 'CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP()'])) {
      return ' DEFAULT ' . $upper;
    }
    return ' DEFAULT ' . self::quoteString((string) $value);
  }

  /** Builds a CREATE/ALTER column definition from column metadata. */
  private static function columnDefinition($column) {
    $name = self::normalizeValue($column['COLUMN_NAME'] ?? '');
    $type = self::normalizeValue($column['COLUMN_TYPE'] ?? '');
    $sql = self::quoteIdentifier($name) . ' ' . $type;
    $charset = self::normalizeValue($column['CHARACTER_SET_NAME'] ?? '');
    $collation = self::normalizeValue($column['COLLATION_NAME'] ?? '');
    $comment = self::normalizeValue($column['COLUMN_COMMENT'] ?? '');
    if ($charset !== '') {
      $sql .= ' CHARACTER SET ' . $charset;
    }
    if ($collation !== '') {
      $sql .= ' COLLATE ' . $collation;
    }
    if (($column['IS_NULLABLE'] ?? '') === 'NO') {
      $sql .= ' NOT NULL';
    } else {
      $sql .= ' NULL';
    }
    $sql .= self::defaultClause($column['COLUMN_DEFAULT'] ?? null);
    if (stripos(self::normalizeValue($column['EXTRA'] ?? ''), 'auto_increment') !== false) {
      $sql .= ' AUTO_INCREMENT';
    }
    if ($comment !== '') {
      $sql .= ' COMMENT ' . self::quoteString($comment);
    }
    return $sql;
  }

  /** Builds FIRST or AFTER placement SQL for reordered columns. */
  private static function columnPositionClause($previousColumn) {
    return $previousColumn === false ? ' FIRST' : ' AFTER ' . self::quoteIdentifier($previousColumn);
  }

  /** Finds the previous real column name for ALTER TABLE placement clauses. */
  private static function previousNamedColumn($columns, $index) {
    for ($i = $index - 1; $i >= 0; $i--) {
      $name = self::normalizeValue($columns[$i]['COLUMN_NAME'] ?? '');
      if ($name !== '') {
        return $name;
      }
    }
    return false;
  }

  /** Reduces raw column metadata to the fields compared by the ALTER generator. */
  private static function normalizeColumn($column) {
    return [
      'name' => self::normalizeValue($column['COLUMN_NAME'] ?? ''),
      'type' => strtolower(self::normalizeValue($column['COLUMN_TYPE'] ?? '')),
      'nullable' => self::normalizeValue($column['IS_NULLABLE'] ?? ''),
      'default' => self::normalizeValue($column['COLUMN_DEFAULT'] ?? ''),
      'extra' => strtolower(self::normalizeValue($column['EXTRA'] ?? '')),
      'charset' => self::normalizeValue($column['CHARACTER_SET_NAME'] ?? ''),
      'collation' => self::normalizeValue($column['COLLATION_NAME'] ?? ''),
      'comment' => self::normalizeValue($column['COLUMN_COMMENT'] ?? '')
    ];
  }

  /** Groups SHOW INDEX rows by index name for table editor display and comparison. */
  private static function groupIndexes($indexes) {
    $groups = [];
    foreach ($indexes as $index) {
      $name = self::normalizeValue($index['INDEX_NAME'] ?? '');
      if ($name === '') {
        continue;
      }
      if (!isset($groups[$name])) {
        $groups[$name] = [
          'name' => $name,
          'nonUnique' => (int) ($index['NON_UNIQUE'] ?? ($name === 'PRIMARY' ? 0 : 1)),
          'type' => strtoupper(self::normalizeValue($index['INDEX_TYPE'] ?? '')),
          'columns' => []
        ];
      }
      $column = self::normalizeValue($index['COLUMN_NAME'] ?? '');
      if ($column !== '') {
        $groups[$name]['columns'][] = [
          'name' => $column,
          'collation' => self::normalizeValue($index['COLLATION'] ?? 'A'),
          'sequence' => (int) ($index['SEQ_IN_INDEX'] ?? count($groups[$name]['columns']) + 1)
        ];
      }
    }
    foreach ($groups as &$group) {
      usort($group['columns'], fn($a, $b) => $a['sequence'] <=> $b['sequence']);
    }
    unset($group);
    return $groups;
  }

  /** Normalizes an index group before comparing current and edited index state. */
  private static function normalizeIndex($index) {
    $type = strtoupper($index['type']);
    if ($type === 'DEFAULT' || $type === '') {
      $type = 'BTREE';
    }
    return [
      'name' => $index['name'],
      'nonUnique' => (int) $index['nonUnique'],
      'type' => $type,
      'columns' => array_map(fn($column) => [
        'name' => $column['name'],
        'collation' => $column['collation'] === 'D' ? 'D' : 'A'
      ], $index['columns'])
    ];
  }

  /** Builds a CREATE/ALTER index definition from normalized index state. */
  private static function indexDefinition($index) {
    $type = strtoupper($index['type']);
    $name = $index['name'];
    if ($name === 'PRIMARY' || $type === 'PRIMARY') {
      $sql = 'PRIMARY KEY';
    } elseif ($type === 'FULLTEXT') {
      $sql = 'FULLTEXT KEY ' . self::quoteIdentifier($name);
    } elseif ($type === 'SPATIAL') {
      $sql = 'SPATIAL KEY ' . self::quoteIdentifier($name);
    } elseif ((int) $index['nonUnique'] === 0) {
      $sql = 'UNIQUE KEY ' . self::quoteIdentifier($name);
    } else {
      $sql = 'KEY ' . self::quoteIdentifier($name);
    }
    $columns = [];
    foreach ($index['columns'] as $column) {
      $columns[] = self::quoteIdentifier($column['name']) . (($column['collation'] ?? '') === 'D' ? ' DESC' : '');
    }
    return $sql . ' (' . implode(', ', $columns) . ')';
  }

  /** Groups foreign-key metadata rows by constraint name for editor display and SQL generation. */
  private static function groupForeignKeys($foreignKeys) {
    $groups = [];
    foreach ($foreignKeys as $foreignKey) {
      $name = self::normalizeValue($foreignKey['CONSTRAINT_NAME'] ?? '');
      if ($name === '') {
        continue;
      }
      if (!isset($groups[$name])) {
        $groups[$name] = [
          'name' => $name,
          'targetSchema' => self::normalizeValue($foreignKey['REFERENCED_TABLE_SCHEMA'] ?? ''),
          'targetTable' => self::normalizeValue($foreignKey['REFERENCED_TABLE_NAME'] ?? ''),
          'updateRule' => self::normalizeValue($foreignKey['UPDATE_RULE'] ?? ''),
          'deleteRule' => self::normalizeValue($foreignKey['DELETE_RULE'] ?? ''),
          'columns' => []
        ];
      }
      $groups[$name]['columns'][] = [
        'source' => self::normalizeValue($foreignKey['COLUMN_NAME'] ?? ''),
        'target' => self::normalizeValue($foreignKey['REFERENCED_COLUMN_NAME'] ?? ''),
        'sequence' => (int) ($foreignKey['ORDINAL_POSITION'] ?? count($groups[$name]['columns']) + 1)
      ];
    }
    foreach ($groups as &$group) {
      usort($group['columns'], fn($a, $b) => $a['sequence'] <=> $b['sequence']);
    }
    unset($group);
    return $groups;
  }

  /** Normalizes a foreign-key group before comparing current and edited state. */
  private static function normalizeForeignKey($foreignKey) {
    return [
      'name' => $foreignKey['name'],
      'targetSchema' => $foreignKey['targetSchema'],
      'targetTable' => $foreignKey['targetTable'],
      'updateRule' => $foreignKey['updateRule'],
      'deleteRule' => $foreignKey['deleteRule'],
      'columns' => array_map(fn($column) => [
        'source' => $column['source'],
        'target' => $column['target']
      ], $foreignKey['columns'])
    ];
  }

  /** Builds a foreign-key constraint clause from normalized editor state. */
  private static function foreignKeyDefinition($foreignKey) {
    $sourceColumns = [];
    $targetColumns = [];
    foreach ($foreignKey['columns'] as $column) {
      $sourceColumns[] = self::quoteIdentifier($column['source']);
      $targetColumns[] = self::quoteIdentifier($column['target']);
    }
    $sql =
      'CONSTRAINT ' . self::quoteIdentifier($foreignKey['name']) .
      ' FOREIGN KEY (' . implode(', ', $sourceColumns) . ')' .
      ' REFERENCES ' . self::quoteQualifiedTable($foreignKey['targetSchema'], $foreignKey['targetTable']) .
      ' (' . implode(', ', $targetColumns) . ')';
    if ($foreignKey['updateRule'] !== '') {
      $sql .= ' ON UPDATE ' . $foreignKey['updateRule'];
    }
    if ($foreignKey['deleteRule'] !== '') {
      $sql .= ' ON DELETE ' . $foreignKey['deleteRule'];
    }
    return $sql;
  }

  /** Normalizes trigger metadata before comparing current and edited trigger state. */
  private static function normalizeTrigger($trigger) {
    return [
      'name' => self::normalizeValue($trigger['TRIGGER_NAME'] ?? ''),
      'timing' => self::normalizeValue($trigger['ACTION_TIMING'] ?? ''),
      'event' => self::normalizeValue($trigger['EVENT_MANIPULATION'] ?? ''),
      'statement' => trim(self::textValue($trigger['ACTION_STATEMENT'] ?? ''))
    ];
  }

  /** Builds CREATE TRIGGER SQL for the selected schema table. */
  private static function triggerCreateSql($table, $trigger) {
    $trigger = self::normalizeTrigger($trigger);
    return
      'CREATE TRIGGER ' . self::quoteIdentifier(self::$schema) . '.' . self::quoteIdentifier($trigger['name']) . "\n" .
      $trigger['timing'] . ' ' . $trigger['event'] . ' ON ' . self::quoteQualifiedTable(self::$schema, $table) . "\n" .
      "FOR EACH ROW\n" .
      $trigger['statement'] . ';';
  }

}
