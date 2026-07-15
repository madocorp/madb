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

  /** Generates SQL used to emulate a MySQL schema rename. */
  public function renameSchemaSql($schema, $targetSchema) {
    if (!$this->schemaExists($schema)) {
      throw new \Exception("Source schema '{$schema}' does not exist.");
    }
    $info = $this->renameSchemaInfo($schema, $targetSchema);
    if (!empty($info['targetExists'])) {
      throw new \Exception("Target schema '{$targetSchema}' already exists.");
    }
    $defaults = $this->schemaDefaults($schema);
    $source = $this->escapeIdentifier($schema);
    $target = $this->escapeIdentifier($targetSchema);
    $statements = [
      "CREATE SCHEMA `{$target}` DEFAULT CHARACTER SET {$defaults['DEFAULT_CHARACTER_SET_NAME']} COLLATE {$defaults['DEFAULT_COLLATION_NAME']};"
    ];
    foreach ($this->getTriggers($schema, $targetSchema) as $trigger) {
      $name = $this->escapeIdentifier($trigger['TRIGGER_NAME']);
      $statements[] = "DROP TRIGGER IF EXISTS `{$source}`.`{$name}`;";
    }
    foreach ($this->renameTableStatements($schema, $targetSchema) as $statement) {
      $statements[] = $statement;
    }
    foreach ($this->getViews($schema, $targetSchema) as $view) {
      $statements[] = rtrim($view, ';') . ';';
    }
    foreach ($this->getTriggers($schema, $targetSchema) as $trigger) {
      $name = $this->escapeIdentifier($trigger['TRIGGER_NAME']);
      $table = $this->escapeIdentifier($trigger['EVENT_OBJECT_TABLE']);
      $statements[] =
        "CREATE TRIGGER `{$target}`.`{$name}` {$trigger['ACTION_TIMING']} {$trigger['EVENT_MANIPULATION']} " .
        "ON `{$target}`.`{$table}` FOR EACH ROW {$trigger['ACTION_STATEMENT']};";
    }
    foreach ($this->getFunctions($schema, $targetSchema) as $function) {
      $statements[] = rtrim($function, ';') . ';';
    }
    foreach ($this->getProcedures($schema, $targetSchema) as $procedure) {
      $statements[] = rtrim($procedure, ';') . ';';
    }
    $statements[] = "DROP SCHEMA `{$source}`;";
    $this->queryTime = microtime(true);
    return [
      'info' => $info,
      'sql' => implode("\n\n", $statements)
    ];
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

  /** Returns RENAME TABLE statements for all base tables in a schema. */
  private function renameTableStatements($schema, $targetSchema) {
    $stmt = $this->pdo->prepare(
      "SELECT TABLE_NAME
       FROM INFORMATION_SCHEMA.TABLES
       WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
       ORDER BY TABLE_NAME"
    );
    $stmt->execute([$schema]);
    $source = $this->escapeIdentifier($schema);
    $target = $this->escapeIdentifier($targetSchema);
    $tables = [];
    while ($table = $stmt->fetchColumn()) {
      $name = $this->escapeIdentifier($table);
      $tables[] = "`{$source}`.`{$name}` TO `{$target}`.`{$name}`";
    }
    $statements = [];
    foreach (array_chunk($tables, 50) as $chunk) {
      $statements[] = 'RENAME TABLE ' . implode(', ', $chunk) . ';';
    }
    return $statements;
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
