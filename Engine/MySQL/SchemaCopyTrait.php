<?php

namespace MADB\Engine\MySQL;

use \PDO;

/** Implements the MySQL schema-copy steps used to emulate schema rename. */
trait SchemaCopyTrait {

  /** Escapes identifier for SQL built by the MySQL engine. */
  private function escapeIdentifier($identifier) {
    return str_replace('`', '``', $identifier);
  }

  /** Coordinates schema exists work in the MySQL engine. */
  private function schemaExists($schema) {
    $stmt = $this->pdo->prepare(
      "SELECT COUNT(*)
       FROM INFORMATION_SCHEMA.SCHEMATA
       WHERE SCHEMA_NAME = ?"
    );
    $stmt->execute([$schema]);
    return ((int) $stmt->fetchColumn() > 0);
  }

  /** Coordinates schema defaults work in the MySQL engine. */
  private function schemaDefaults($schema) {
    $stmt = $this->pdo->prepare(
      "SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
       FROM INFORMATION_SCHEMA.SCHEMATA
       WHERE SCHEMA_NAME = ?"
    );
    $stmt->execute([$schema]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new \Exception("Schema '{$schema}' does not exist.");
    }
    return $row;
  }

  /** Coordinates schema objects work in the MySQL engine. */
  private function schemaObjects($schema) {
    $stmt = $this->pdo->prepare(
      "SELECT TABLE_NAME, TABLE_TYPE
       FROM INFORMATION_SCHEMA.TABLES
       WHERE TABLE_SCHEMA = ?
       ORDER BY FIELD(TABLE_TYPE, 'BASE TABLE', 'VIEW'), TABLE_NAME"
    );
    $stmt->execute([$schema]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  /** Coordinates replace schema references work in the MySQL engine. */
  private function replaceSchemaReferences($sql, $schema, $targetSchema) {
    return str_replace(
      ["`{$schema}`.", "{$schema}."],
      ["`{$targetSchema}`.", "{$targetSchema}."],
      $sql
    );
  }

  /** Returns triggers data used by the MySQL engine. */
  private function getTriggers($schema, $targetSchema) {
    $stmt = $this->pdo->prepare(
      "SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION,
              EVENT_OBJECT_TABLE, ACTION_STATEMENT
       FROM INFORMATION_SCHEMA.TRIGGERS
       WHERE TRIGGER_SCHEMA = ?"
    );
    $stmt->execute([$schema]);
    $triggers = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $row['ACTION_STATEMENT'] = $this->replaceSchemaReferences($row['ACTION_STATEMENT'], $schema, $targetSchema);
      $triggers[] = $row;
    }
    return $triggers;
  }

  /** Returns procedures data used by the MySQL engine. */
  private function getProcedures($schema, $targetSchema) {
    $stmt = $this->pdo->prepare("SHOW PROCEDURE STATUS WHERE `Db` = ?");
    $stmt->execute([$schema]);
    $procedures = [];
    $source = $this->escapeIdentifier($schema);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $name = $this->escapeIdentifier($row['Name']);
      $stmt2 = $this->pdo->query("SHOW CREATE PROCEDURE `{$source}`.`{$name}`");
      $data = $stmt2->fetch(PDO::FETCH_ASSOC);
      $procedures[] = $this->replaceSchemaReferences($data['Create Procedure'], $schema, $targetSchema);
    }
    return $procedures;
  }

  /** Returns functions data used by the MySQL engine. */
  private function getFunctions($schema, $targetSchema) {
    $stmt = $this->pdo->prepare("SHOW FUNCTION STATUS WHERE `Db` = ?");
    $stmt->execute([$schema]);
    $functions = [];
    $source = $this->escapeIdentifier($schema);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $name = $this->escapeIdentifier($row['Name']);
      $stmt2 = $this->pdo->query("SHOW CREATE FUNCTION `{$source}`.`{$name}`");
      $data = $stmt2->fetch(PDO::FETCH_ASSOC);
      $functions[] = $this->replaceSchemaReferences($data['Create Function'], $schema, $targetSchema);
    }
    return $functions;
  }

  /** Coordinates drop triggers work in the MySQL engine. */
  private function dropTriggers($schema, $triggers) {
    $source = $this->escapeIdentifier($schema);
    foreach ($triggers as $trigger) {
      $name = $this->escapeIdentifier($trigger['TRIGGER_NAME']);
      $this->pdo->exec("DROP TRIGGER IF EXISTS `{$source}`.`{$name}`");
    }
  }

  /** Coordinates move tables work in the MySQL engine. */
  private function moveTables($schema, $targetSchema) {
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
    foreach (array_chunk($tables, 50) as $chunk) {
      $this->pdo->exec('RENAME TABLE ' . implode(', ', $chunk));
    }
  }

  /** Coordinates copy views work in the MySQL engine. */
  private function copyViews($schema, $targetSchema) {
    $views = $this->getViews($schema, $targetSchema);
    if (empty($views)) {
      return;
    }
    $target = $this->escapeIdentifier($targetSchema);
    $this->pdo->exec("USE `{$target}`");
    foreach ($views as $view) {
      $this->pdo->exec($view);
    }
  }

  /** Returns views data used by the MySQL engine. */
  private function getViews($schema, $targetSchema) {
    $stmt = $this->pdo->prepare(
      "SELECT TABLE_NAME
       FROM INFORMATION_SCHEMA.VIEWS
       WHERE TABLE_SCHEMA = ?
       ORDER BY TABLE_NAME"
    );
    $stmt->execute([$schema]);
    $source = $this->escapeIdentifier($schema);
    $target = $this->escapeIdentifier($targetSchema);
    $views = [];
    while ($view = $stmt->fetchColumn()) {
      $name = $this->escapeIdentifier($view);
      $stmt2 = $this->pdo->query("SHOW CREATE VIEW `{$source}`.`{$name}`");
      $data = $stmt2->fetch(PDO::FETCH_ASSOC);
      $views[] = $this->replaceSchemaReferences($data['Create View'], $schema, $targetSchema);
    }
    return $views;
  }

  /** Coordinates restore triggers work in the MySQL engine. */
  private function restoreTriggers($targetSchema, $triggers) {
    $target = $this->escapeIdentifier($targetSchema);
    $this->pdo->exec("USE `{$target}`");
    foreach ($triggers as $trigger) {
      $name = $this->escapeIdentifier($trigger['TRIGGER_NAME']);
      $table = $this->escapeIdentifier($trigger['EVENT_OBJECT_TABLE']);
      $query = "CREATE TRIGGER `{$name}` ";
      $query .= "{$trigger['ACTION_TIMING']} ";
      $query .= "{$trigger['EVENT_MANIPULATION']} ON ";
      $query .= "`{$table}` ";
      $query .= "FOR EACH ROW {$trigger['ACTION_STATEMENT']}";
      $this->pdo->exec($query);
    }
  }

  /** Coordinates restore functions work in the MySQL engine. */
  private function restoreFunctions($targetSchema, $functions) {
    $target = $this->escapeIdentifier($targetSchema);
    $this->pdo->exec("USE `{$target}`");
    foreach ($functions as $function) {
      $this->pdo->exec($function);
    }
  }

  /** Coordinates restore procedures work in the MySQL engine. */
  private function restoreProcedures($targetSchema, $procedures) {
    $target = $this->escapeIdentifier($targetSchema);
    $this->pdo->exec("USE `{$target}`");
    foreach ($procedures as $procedure) {
      $this->pdo->exec($procedure);
    }
  }

}
