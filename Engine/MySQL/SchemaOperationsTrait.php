<?php

namespace MADB\Engine\MySQL;

use \PDO;

/** Implements MySQL schema inspection, create, rename, drop, and charset/collation operations. */
trait SchemaOperationsTrait {

  /** Coordinates schema info work in the MySQL engine. */
  public function schemaInfo($schema) {
    $stmt = $this->pdo->prepare(
      "SELECT TABLE_TYPE, COUNT(*) AS object_count,
              COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS bytes
       FROM INFORMATION_SCHEMA.TABLES
       WHERE TABLE_SCHEMA = ?
       GROUP BY TABLE_TYPE"
    );
    $stmt->execute([$schema]);
    $this->queryTime = microtime(true);
    $info = [
      'tables' => 0,
      'views' => 0,
      'bytes' => 0
    ];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      if ($row['TABLE_TYPE'] === 'BASE TABLE') {
        $info['tables'] += (int) $row['object_count'];
      } else {
        $info['views'] += (int) $row['object_count'];
      }
      $info['bytes'] += (int) $row['bytes'];
    }
    $stmt = $this->pdo->prepare(
      "SELECT COUNT(*)
       FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
       WHERE CONSTRAINT_SCHEMA = ?"
    );
    $stmt->execute([$schema]);
    $info['foreignKeys'] = (int) $stmt->fetchColumn();
    $stmt = $this->pdo->prepare(
      "SELECT COUNT(*)
       FROM INFORMATION_SCHEMA.ROUTINES
       WHERE ROUTINE_SCHEMA = ?"
    );
    $stmt->execute([$schema]);
    $info['routines'] = (int) $stmt->fetchColumn();
    $stmt = $this->pdo->prepare(
      "SELECT COUNT(*)
       FROM INFORMATION_SCHEMA.EVENTS
       WHERE EVENT_SCHEMA = ?"
    );
    $stmt->execute([$schema]);
    $info['events'] = (int) $stmt->fetchColumn();
    return $info;
  }

  /** Coordinates rename schema info work in the MySQL engine. */
  public function renameSchemaInfo($schema, $targetSchema) {
    $info = $this->schemaInfo($schema);
    $info['targetExists'] = $this->schemaExists($targetSchema);
    return $info;
  }

  /** Coordinates rename schema work in the MySQL engine. */
  public function renameSchema($schema, $targetSchema) {
    if (!$this->schemaExists($schema)) {
      throw new \Exception("Source schema '{$schema}' does not exist.");
    }
    if ($this->schemaExists($targetSchema)) {
      throw new \Exception("Target schema '{$targetSchema}' already exists.");
    }
    $defaults = $this->schemaDefaults($schema);
    $target = $this->escapeIdentifier($targetSchema);
    $charset = $defaults['DEFAULT_CHARACTER_SET_NAME'];
    $collation = $defaults['DEFAULT_COLLATION_NAME'];
    $triggers = $this->getTriggers($schema, $targetSchema);
    $procedures = $this->getProcedures($schema, $targetSchema);
    $functions = $this->getFunctions($schema, $targetSchema);
    $this->pdo->exec("CREATE SCHEMA `{$target}` DEFAULT CHARACTER SET {$charset} COLLATE {$collation}");
    $this->dropTriggers($schema, $triggers);
    $this->moveTables($schema, $targetSchema);
    $this->copyViews($schema, $targetSchema);
    $this->restoreTriggers($targetSchema, $triggers);
    $this->restoreFunctions($targetSchema, $functions);
    $this->restoreProcedures($targetSchema, $procedures);
    $source = $this->escapeIdentifier($schema);
    $this->pdo->exec("DROP SCHEMA `{$source}`");
    $this->queryTime = microtime(true);
    return true;
  }

  /** Coordinates drop schema work in the MySQL engine. */
  public function dropSchema($schema) {
    $schema = str_replace('`', '``', $schema);
    $this->pdo->exec("DROP SCHEMA `{$schema}`");
    $this->queryTime = microtime(true);
    return true;
  }

  /** Coordinates character sets and collations work in the MySQL engine. */
  public function characterSetsAndCollations() {
    $charsets = $this->pdo->query("SHOW CHARACTER SET")->fetchAll(PDO::FETCH_COLUMN, 0);
    $collations = $this->pdo->query("SHOW COLLATION")->fetchAll(PDO::FETCH_COLUMN, 0);
    $engineRows = $this->pdo->query("SHOW ENGINES")->fetchAll(PDO::FETCH_ASSOC);
    $engines = [];
    foreach ($engineRows as $engine) {
      if (in_array($engine['Support'] ?? '', ['YES', 'DEFAULT'])) {
        $engines[] = $engine['Engine'];
      }
    }
    $this->queryTime = microtime(true);
    return [
      'charsets' => $charsets,
      'collations' => $collations,
      'engines' => $engines
    ];
  }

}
