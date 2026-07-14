<?php

namespace MADB\Engine\MySQL;

use \PDO;

/** Runs MySQL single and batch queries and writes result files for the query workspace. */
trait QueryRunnerTrait {

  /** Runs batch through the MySQL engine. */
  public function queryBatch($statements, $resultFiles = [], $schema = false, $progress = false) {
    if (is_callable($schema) && $progress === false) {
      $progress = $schema;
      $schema = false;
    }
    if (!is_array($statements) || empty($statements)) {
      throw new \Exception('Query is empty.');
    }
    $this->useSchema($schema);
    $results = [];
    $resultIndex = 0;
    foreach ($statements as $index => $statement) {
      $statementIndex = $statement['index'] ?? $index;
      $sql = trim((string) ($statement['sql'] ?? ''));
      if ($sql === '') {
        continue;
      }
      $started = microtime(true);
      if (is_callable($progress)) {
        $progress([
          'statements' => array_merge($results, [[
            'index' => $statementIndex,
            'sql' => $sql,
            'status' => 'RUNNING',
            'startedAt' => $started,
            'range' => [
              'start' => $statement['start'] ?? 0,
              'end' => $statement['end'] ?? 0
            ]
          ]]),
          'resultCount' => $resultIndex
        ]);
      }
      try {
        $file = $resultFiles[$resultIndex] ?? false;
        $result = $this->query($sql, $file);
        $finished = microtime(true);
        $entry = [
          'index' => $statementIndex,
          'sql' => $sql,
          'status' => 'OK',
          'startedAt' => $started,
          'time' => round($finished - $started, 4),
          'finishedAt' => $finished,
          'range' => [
            'start' => $statement['start'] ?? 0,
            'end' => $statement['end'] ?? 0
          ]
        ];
        if (is_array($result) && isset($result['columns'])) {
          $entry['resultIndex'] = $resultIndex;
          $entry['result'] = $result;
          if ($file !== false && isset($result['rowCount'])) {
            $entry['result']['file'] = $file;
          }
          $resultIndex++;
        } else {
          $entry['result'] = $result;
        }
        $results[] = $entry;
        if (is_callable($progress)) {
          $progress([
            'statements' => $results,
            'resultCount' => $resultIndex
          ]);
        }
      } catch (\Exception $e) {
        $finished = microtime(true);
        $results[] = [
          'index' => $statementIndex,
          'sql' => $sql,
          'status' => 'ERROR',
          'error' => $e->getMessage(),
          'startedAt' => $started,
          'time' => round($finished - $started, 4),
          'finishedAt' => $finished,
          'range' => [
            'start' => $statement['start'] ?? 0,
            'end' => $statement['end'] ?? 0
          ]
        ];
        if (is_callable($progress)) {
          $progress([
            'statements' => $results,
            'resultCount' => $resultIndex
          ]);
        }
        break;
      }
    }
    return [
      'statements' => $results,
      'resultCount' => $resultIndex
    ];
  }

  /** Applies selected schema context before running workspace queries. */
  private function useSchema($schema): void {
    $schema = trim((string) $schema);
    if ($schema === '') {
      return;
    }
    $schema = $this->escapeIdentifier($schema);
    $this->pdo->exec("USE `{$schema}`");
  }

  /** Inserts one row into a table using bound values. */
  public function insertTableRow($schema, $table, array $values) {
    $schema = $this->escapeIdentifier($schema);
    $table = $this->escapeIdentifier($table);
    if (empty($values)) {
      $sql = "INSERT INTO `{$schema}`.`{$table}` () VALUES ()";
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute();
    } else {
      $columns = [];
      $placeholders = [];
      $params = [];
      $index = 0;
      foreach ($values as $column => $value) {
        $param = ':p' . $index;
        $columns[] = '`' . $this->escapeIdentifier($column) . '`';
        $placeholders[] = $param;
        $params[$param] = $value;
        $index++;
      }
      $sql = "INSERT INTO `{$schema}`.`{$table}` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($params);
    }
    $this->queryTime = microtime(true);
    return [
      'affectedRows' => $stmt->rowCount(),
      'lastInsertId' => $this->pdo->lastInsertId()
    ];
  }

  /** Updates one row in a table using bound changed values and primary-key conditions. */
  public function updateTableRow($schema, $table, array $changes, array $where) {
    if (empty($changes)) {
      throw new \Exception('No values to update.');
    }
    if (empty($where)) {
      throw new \Exception('No update condition was provided.');
    }
    $schema = $this->escapeIdentifier($schema);
    $table = $this->escapeIdentifier($table);
    $sets = [];
    $conditions = [];
    $params = [];
    $index = 0;
    foreach ($changes as $column => $value) {
      $param = ':s' . $index;
      $sets[] = '`' . $this->escapeIdentifier($column) . '` = ' . $param;
      $params[$param] = $value;
      $index++;
    }
    $index = 0;
    foreach ($where as $column => $value) {
      $columnSql = '`' . $this->escapeIdentifier($column) . '`';
      if ($value === null) {
        $conditions[] = $columnSql . ' IS NULL';
      } else {
        $param = ':w' . $index;
        $conditions[] = $columnSql . ' = ' . $param;
        $params[$param] = $value;
        $index++;
      }
    }
    $sql = "UPDATE `{$schema}`.`{$table}` SET " . implode(', ', $sets) . ' WHERE ' . implode(' AND ', $conditions);
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    $this->queryTime = microtime(true);
    return [
      'affectedRows' => $stmt->rowCount()
    ];
  }

  /** Deletes selected rows from a table using primary-key conditions only. */
  public function deleteTableRows($schema, $table, array $primaryRows) {
    if (empty($primaryRows)) {
      throw new \Exception('No rows to delete.');
    }
    $schema = $this->escapeIdentifier($schema);
    $table = $this->escapeIdentifier($table);
    $groups = [];
    $params = [];
    $index = 0;
    foreach ($primaryRows as $row) {
      if (!is_array($row) || empty($row)) {
        throw new \Exception('No delete condition was provided.');
      }
      $conditions = [];
      foreach ($row as $column => $value) {
        $columnSql = '`' . $this->escapeIdentifier($column) . '`';
        if ($value === null) {
          $conditions[] = $columnSql . ' IS NULL';
        } else {
          $param = ':d' . $index;
          $conditions[] = $columnSql . ' = ' . $param;
          $params[$param] = $value;
          $index++;
        }
      }
      $groups[] = '(' . implode(' AND ', $conditions) . ')';
    }
    $sql = "DELETE FROM `{$schema}`.`{$table}` WHERE " . implode(' OR ', $groups);
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    $this->queryTime = microtime(true);
    return [
      'affectedRows' => $stmt->rowCount()
    ];
  }

  /** Coordinates write result file work in the MySQL engine. */
  private function writeResultFile($stmt, $columns, $resultFile) {
    $dir = dirname($resultFile);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
      throw new \Exception('Could not create result directory.');
    }
    $handle = fopen($resultFile, 'wb');
    if ($handle === false) {
      throw new \Exception('Could not create result file.');
    }
    try {
      $this->writeTsvLine($handle, $columns);
      $rowCount = 0;
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $values = [];
        foreach ($columns as $column) {
          $values[] = $row[$column] ?? null;
        }
        $this->writeTsvLine($handle, $values);
        $rowCount++;
      }
    } finally {
      fclose($handle);
    }
    return [
      'columns' => $columns,
      'rowCount' => $rowCount
    ];
  }

  /** Coordinates write tsv line work in the MySQL engine. */
  private function writeTsvLine($handle, $values) {
    $fields = [];
    foreach ($values as $value) {
      if ($value === null) {
        $fields[] = '\N';
      } else {
        $fields[] = str_replace(
          ["\\", "\t", "\n", "\r"],
          ["\\\\", "\\t", "\\n", "\\r"],
          (string)$value
        );
      }
    }
    if (fwrite($handle, implode("\t", $fields) . "\n") === false) {
      throw new \Exception('Could not write result file.');
    }
  }

}
