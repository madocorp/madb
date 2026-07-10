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
