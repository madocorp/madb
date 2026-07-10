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
              CCSA.CHARACTER_SET_NAME
       FROM INFORMATION_SCHEMA.TABLES T
       LEFT JOIN INFORMATION_SCHEMA.COLLATION_CHARACTER_SET_APPLICABILITY CCSA
         ON CCSA.COLLATION_NAME = T.TABLE_COLLATION
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

    $stmt = $this->pdo->prepare(
      "SELECT KCU.CONSTRAINT_NAME, KCU.COLUMN_NAME,
              KCU.REFERENCED_TABLE_SCHEMA, KCU.REFERENCED_TABLE_NAME,
              KCU.REFERENCED_COLUMN_NAME, RC.UPDATE_RULE, RC.DELETE_RULE,
              KCU.ORDINAL_POSITION
       FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE KCU
       LEFT JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS RC
         ON RC.CONSTRAINT_SCHEMA = KCU.CONSTRAINT_SCHEMA
        AND RC.CONSTRAINT_NAME = KCU.CONSTRAINT_NAME
       WHERE KCU.TABLE_SCHEMA = ?
         AND KCU.TABLE_NAME = ?
         AND KCU.REFERENCED_TABLE_NAME IS NOT NULL
       ORDER BY KCU.CONSTRAINT_NAME, KCU.ORDINAL_POSITION"
    );
    $stmt->execute([$schema, $table]);
    $foreignKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        'charset' => $tableInfo['CHARACTER_SET_NAME'] ?? '',
        'collation' => $tableInfo['TABLE_COLLATION'] ?? '',
        'comment' => $tableInfo['TABLE_COMMENT'] ?? ''
      ],
      'columns' => $columns,
      'indexes' => $indexes,
      'foreignKeys' => $foreignKeys,
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

}
