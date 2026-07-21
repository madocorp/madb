<?php

namespace MADB\Engine\MySQL;

use \PDO;

/** Reads MySQL table lists, field lists, definitions, and result sets for UI panels. */
trait TableInspectionTrait {

  /** Coordinates table list work in the MySQL engine. */
  public function tableList($schema) {
    $stmt = $this->pdo->prepare(
      "SELECT TABLE_NAME, TABLE_TYPE
       FROM INFORMATION_SCHEMA.TABLES
       WHERE TABLE_SCHEMA = ?
       ORDER BY TABLE_TYPE, TABLE_NAME"
    );
    $stmt->execute([$schema]);
    $this->queryTime = microtime(true);
    $tableList = [];
    while ($table = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $tableList[] = [
        'name' => $table['TABLE_NAME'],
        'type' => $table['TABLE_TYPE']
      ];
    }
    return $tableList;
  }

  /** Coordinates table fields work in the MySQL engine. */
  public function tableFields($schema, $table) {
    $stmt = $this->pdo->prepare(
      "SELECT COLUMN_NAME
       FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
       ORDER BY ORDINAL_POSITION"
    );
    $stmt->execute([$schema, $table]);
    $this->queryTime = microtime(true);
    $fields = [];
    while ($field = $stmt->fetchColumn()) {
      $fields[] = $field;
    }
    return $fields;
  }

  /** Coordinates table definition work in the MySQL engine. */
  public function tableDefinition($schema, $table) {
    $stmt = $this->pdo->prepare(
      "SELECT T.TABLE_NAME, T.TABLE_TYPE, T.ENGINE, T.TABLE_COLLATION, T.TABLE_COMMENT,
              T.TABLE_ROWS, T.DATA_LENGTH, T.INDEX_LENGTH
       FROM INFORMATION_SCHEMA.TABLES T
       WHERE T.TABLE_SCHEMA = ? AND T.TABLE_NAME = ?"
    );
    $stmt->execute([$schema, $table]);
    $tableInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($tableInfo === false) {
      throw new \Exception("Table '{$schema}.{$table}' does not exist.");
    }

    $stmt = $this->pdo->prepare(
      "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
              EXTRA, COLUMN_KEY, COLUMN_COMMENT, CHARACTER_SET_NAME,
              COLLATION_NAME, ORDINAL_POSITION
       FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
       ORDER BY ORDINAL_POSITION"
    );
    $stmt->execute([$schema, $table]);
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $this->pdo->prepare(
      "SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME,
              COLLATION, CARDINALITY, INDEX_TYPE
       FROM INFORMATION_SCHEMA.STATISTICS
       WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
       ORDER BY INDEX_NAME, SEQ_IN_INDEX"
    );
    $stmt->execute([$schema, $table]);
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $foreignKeys = $this->foreignKeyDefinitions($schema, $table);

    $stmt = $this->pdo->prepare(
      "SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION,
              ACTION_STATEMENT
       FROM INFORMATION_SCHEMA.TRIGGERS
       WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?
       ORDER BY TRIGGER_NAME"
    );
    $stmt->execute([$schema, $table]);
    $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $this->queryTime = microtime(true);

    return [
      'table' => [
        'name' => $tableInfo['TABLE_NAME'],
        'type' => $tableInfo['TABLE_TYPE'],
        'engine' => $tableInfo['ENGINE'] ?? '',
        'charset' => $this->characterSetForCollation($tableInfo['TABLE_COLLATION'] ?? ''),
        'collation' => $tableInfo['TABLE_COLLATION'] ?? '',
        'comment' => $tableInfo['TABLE_COMMENT'] ?? '',
        'rows' => (int)($tableInfo['TABLE_ROWS'] ?? 0),
        'dataLength' => (int)($tableInfo['DATA_LENGTH'] ?? 0),
        'indexLength' => (int)($tableInfo['INDEX_LENGTH'] ?? 0)
      ],
      'columns' => $columns,
      'indexes' => $indexes,
      'foreignKeys' => $foreignKeys,
      'referencedBy' => [],
      'triggers' => $triggers
    ];
  }

  /** Runs query through the MySQL engine. */
  public function query($sql, $resultFile = false) {
    if (trim($sql) === '') {
      throw new \Exception('Query is empty.');
    }
    $stmt = $this->pdo->query($sql);
    $this->queryTime = microtime(true);
    if ($stmt === false) {
      throw new \Exception('Query failed.');
    }
    if ($stmt->columnCount() === 0) {
      return [
        'affectedRows' => $stmt->rowCount()
      ];
    }
    $columns = [];
    for ($i = 0; $i < $stmt->columnCount(); $i++) {
      $meta = $stmt->getColumnMeta($i);
      $columns[] = $meta['name'] ?? (string) $i;
    }
    if ($resultFile !== false) {
      return $this->writeResultFile($stmt, $columns, $resultFile);
    }
    return [
      'columns' => $columns,
      'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ];
  }

  /** Returns the character set for one collation without joining INFORMATION_SCHEMA tables. */
  private function characterSetForCollation($collation): string {
    if ($collation === '' || $collation === null) {
      return '';
    }
    static $characterSets = [];
    $connectionName = (string)($this->data['name'] ?? '');
    if (isset($characterSets[$connectionName][$collation])) {
      return $characterSets[$connectionName][$collation];
    }
    $stmt = $this->pdo->prepare(
      "SELECT CHARACTER_SET_NAME
       FROM INFORMATION_SCHEMA.COLLATION_CHARACTER_SET_APPLICABILITY
       WHERE COLLATION_NAME = ?"
    );
    $stmt->execute([$collation]);
    $characterSets[$connectionName][$collation] = (string)($stmt->fetchColumn() ?: '');
    return $characterSets[$connectionName][$collation];
  }

  /** Returns foreign keys defined on one MySQL table without a broad INFORMATION_SCHEMA join. */
  private function foreignKeyDefinitions($schema, $table): array {
    $stmt = $this->pdo->prepare(
      "SELECT KCU.CONSTRAINT_SCHEMA, KCU.TABLE_NAME, KCU.CONSTRAINT_NAME,
              KCU.COLUMN_NAME, KCU.REFERENCED_TABLE_SCHEMA, KCU.REFERENCED_TABLE_NAME,
              KCU.REFERENCED_COLUMN_NAME, KCU.ORDINAL_POSITION
       FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE KCU
       WHERE KCU.TABLE_SCHEMA = ?
         AND KCU.TABLE_NAME = ?
         AND KCU.REFERENCED_TABLE_NAME IS NOT NULL
       ORDER BY KCU.CONSTRAINT_NAME, KCU.ORDINAL_POSITION"
    );
    $stmt->execute([$schema, $table]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
      return [];
    }
    return $this->withReferentialRules($rows, $this->referentialRulesForRows($rows));
  }

  /** Loads incoming foreign-key references for workflows that explicitly need them. */
  public function tableReferencedBy($schema, $table): array {
    $referencedBy = $this->referencedByDefinitions($schema, $table);
    $this->queryTime = microtime(true);
    return $referencedBy;
  }

  /** Returns foreign keys in other MySQL tables that reference one table. */
  private function referencedByDefinitions($schema, $table): array {
    $stmt = $this->pdo->prepare(
      "SELECT KCU.CONSTRAINT_SCHEMA, KCU.TABLE_NAME, KCU.CONSTRAINT_NAME,
              KCU.COLUMN_NAME, KCU.REFERENCED_TABLE_SCHEMA, KCU.REFERENCED_TABLE_NAME,
              KCU.REFERENCED_COLUMN_NAME, KCU.ORDINAL_POSITION
       FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE KCU
       WHERE KCU.TABLE_SCHEMA = ?
         AND KCU.REFERENCED_TABLE_SCHEMA = ?
         AND KCU.REFERENCED_TABLE_NAME = ?
       ORDER BY KCU.CONSTRAINT_SCHEMA, KCU.TABLE_NAME, KCU.CONSTRAINT_NAME, KCU.ORDINAL_POSITION"
    );
    $stmt->execute([$schema, $schema, $table]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
      return [];
    }
    return $this->withReferentialRules($rows, $this->referentialRulesForRows($rows));
  }

  /** Fetches referential rules for exact foreign-key rows. */
  private function referentialRulesForRows(array $rows): array {
    $constraints = [];
    foreach ($rows as $row) {
      $key = $this->constraintKey($row);
      if ($key !== '') {
        $constraints[$key] = [
          'CONSTRAINT_SCHEMA' => $row['CONSTRAINT_SCHEMA'] ?? '',
          'TABLE_NAME' => $row['TABLE_NAME'] ?? '',
          'CONSTRAINT_NAME' => $row['CONSTRAINT_NAME'] ?? ''
        ];
      }
    }
    return $this->referentialRuleRows(array_values($constraints));
  }

  /** Fetches referential rule rows for exact constraints. */
  private function referentialRuleRows(array $constraints): array {
    if (empty($constraints)) {
      return [];
    }
    $conditions = [];
    $params = [];
    foreach ($constraints as $constraint) {
      $conditions[] = '(RC.CONSTRAINT_SCHEMA = ? AND RC.TABLE_NAME = ? AND RC.CONSTRAINT_NAME = ?)';
      $params[] = $constraint['CONSTRAINT_SCHEMA'] ?? '';
      $params[] = $constraint['TABLE_NAME'] ?? '';
      $params[] = $constraint['CONSTRAINT_NAME'] ?? '';
    }
    $stmt = $this->pdo->prepare(
      "SELECT RC.CONSTRAINT_SCHEMA, RC.TABLE_NAME, RC.CONSTRAINT_NAME,
              RC.UPDATE_RULE, RC.DELETE_RULE
       FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS RC
       WHERE " . implode(' OR ', $conditions)
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  /** Adds update/delete rules to key-column usage rows. */
  private function withReferentialRules(array $rows, array $rules): array {
    $ruleMap = $this->referentialRuleMap($rules);
    foreach ($rows as $index => $row) {
      $rule = $ruleMap[$this->constraintKey($row)] ?? [];
      $rows[$index]['UPDATE_RULE'] = $rule['UPDATE_RULE'] ?? '';
      $rows[$index]['DELETE_RULE'] = $rule['DELETE_RULE'] ?? '';
    }
    return $rows;
  }

  /** Builds a lookup map for referential rule rows. */
  private function referentialRuleMap(array $rules): array {
    $map = [];
    foreach ($rules as $rule) {
      $key = $this->constraintKey($rule);
      if ($key !== '') {
        $map[$key] = $rule;
      }
    }
    return $map;
  }

  /** Returns a stable foreign-key constraint lookup key. */
  private function constraintKey(array $row): string {
    $schema = (string)($row['CONSTRAINT_SCHEMA'] ?? '');
    $table = (string)($row['TABLE_NAME'] ?? '');
    $name = (string)($row['CONSTRAINT_NAME'] ?? '');
    if ($schema === '' || $table === '' || $name === '') {
      return '';
    }
    return $schema . "\0" . $table . "\0" . $name;
  }

  /** Returns the lean table metadata needed by row insert, update, and delete panels. */
  public function rowEditorDefinition($schema, $table) {
    $stmt = $this->pdo->prepare(
      "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
              EXTRA, COLUMN_KEY, COLUMN_COMMENT, CHARACTER_SET_NAME,
              COLLATION_NAME, ORDINAL_POSITION
       FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
       ORDER BY ORDINAL_POSITION"
    );
    $stmt->execute([$schema, $table]);
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($columns)) {
      throw new \Exception("Table '{$schema}.{$table}' does not exist or has no columns.");
    }
    $this->queryTime = microtime(true);
    return [
      'columns' => $columns
    ];
  }

  /** Returns CREATE SQL for a MySQL table or view. */
  public function showCreateTable($schema, $table) {
    $schema = $this->escapeIdentifier($schema);
    $table = $this->escapeIdentifier($table);
    $stmt = $this->pdo->query("SHOW CREATE TABLE `{$schema}`.`{$table}`");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \Exception('The query returned no rows.');
    }
    foreach ($row as $column => $value) {
      if (strpos($column, 'Create ') === 0) {
        $this->queryTime = microtime(true);
        return [
          'sql' => $value
        ];
      }
    }
    throw new \Exception('The query result did not contain a CREATE statement.');
  }

}
