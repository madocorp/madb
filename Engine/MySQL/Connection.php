<?php

namespace MADB\Engine\MySQL;

use \PDO;

class Connection extends \MADB\Connection\Connection {

  public $pdo;

  public static function getDefaults() {
    return [
      'name' => 'new',
      'host' => '',
      'port' => '3306',
      'schema' => '',
      'timeout' => '3600',
      'initCommand' => '',
      'username' => '',
      'password' => '',
      'sslKey' => '',
      'sslCert' => '',
      'sslCA' => '',
      'sslCipher' => ''
    ];
  }

  public static function getMenuLabels() {
    return [
      'schema' => 'Schema',
      'table' => 'Table'
    ];
  }

  public function connect() {
    if (empty($this->data['name'])) {
      throw new \Exception('Nameless connection!');
    }
    $name = $this->data['name'];
    if (empty($this->data['host'])) {
      throw new \Exception("Empty hostname in connection {$name}");
    } else {
      $host = $this->data['host'];
    }
    if (empty($this->data['port'])) {
      $port = $this->fields['port'];
    } else {
      $port = $this->data['port'];
    }
    $dsn = "mysql:host={$host};port={$port}";
    if (!empty($this->data['schema'])) {
      $dsn .= ";dbname={$this->data['schema']}";
    }
    if (empty($this->data['username'])) {
      $username = null;
    } else {
      $username = $this->data['username'];
    }
    if (empty($this->data['password'])) {
      $password = null;
    } else {
      $password = $this->data['password'];
    }
    $options = [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
    ];
    if (!empty($this->data['initCommand'])) {
      $options[PDO::MYSQL_ATTR_INIT_COMMAND] = $this->data['initCommand'];
    }
    if (!empty($this->data['sslKey'])) {
      $options[PDO::ATTR_SSL_KEY] = $this->data['sslKey'];
    }
    if (!empty($this->data['sslCert'])) {
      $options[PDO::ATTR_SSL_CERT] = $this->data['sslCert'];
    }
    if (!empty($this->data['sslCA'])) {
      $options[PDO::ATTR_SSL_CAPATH] = $this->data['sslCA'];
    }
    if (!empty($this->data['sslCipher'])) {
      $options[PDO::ATTR_SSL_CIPHER] = $this->data['sslCipher'];
    }
    $this->pdo = new PDO($dsn, $username, $password, $options);
  }

  public function test() {
    return "The connection to the server was successful.";
  }

  public function schemaList() {
    $stmt = $this->pdo->query("SHOW SCHEMAS");
    $this->queryTime = microtime(true);
    $schemaList = [];
    while ($schema = $stmt->fetchColumn()) {
      $schemaList[] = $schema;
    }
    return $schemaList;
  }

  public function createSchema($schema) {
    $schema = $this->escapeIdentifier($schema);
    $this->pdo->exec("CREATE SCHEMA `{$schema}`");
    $this->queryTime = microtime(true);
    return true;
  }

  private function escapeIdentifier($identifier) {
    return str_replace('`', '``', $identifier);
  }

  private function schemaExists($schema) {
    $stmt = $this->pdo->prepare(
      "SELECT COUNT(*)
       FROM INFORMATION_SCHEMA.SCHEMATA
       WHERE SCHEMA_NAME = ?"
    );
    $stmt->execute([$schema]);
    return ((int) $stmt->fetchColumn() > 0);
  }

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

  private function replaceSchemaReferences($sql, $schema, $targetSchema) {
    return str_replace(
      ["`{$schema}`.", "{$schema}."],
      ["`{$targetSchema}`.", "{$targetSchema}."],
      $sql
    );
  }

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

  private function dropTriggers($schema, $triggers) {
    $source = $this->escapeIdentifier($schema);
    foreach ($triggers as $trigger) {
      $name = $this->escapeIdentifier($trigger['TRIGGER_NAME']);
      $this->pdo->exec("DROP TRIGGER IF EXISTS `{$source}`.`{$name}`");
    }
  }

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

  private function copyViews($schema, $targetSchema) {
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
    if (empty($views)) {
      return;
    }
    $this->pdo->exec("USE `{$target}`");
    foreach ($views as $view) {
      $this->pdo->exec($view);
    }
  }

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

  private function restoreFunctions($targetSchema, $functions) {
    $target = $this->escapeIdentifier($targetSchema);
    $this->pdo->exec("USE `{$target}`");
    foreach ($functions as $function) {
      $this->pdo->exec($function);
    }
  }

  private function restoreProcedures($targetSchema, $procedures) {
    $target = $this->escapeIdentifier($targetSchema);
    $this->pdo->exec("USE `{$target}`");
    foreach ($procedures as $procedure) {
      $this->pdo->exec($procedure);
    }
  }

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

  public function renameSchemaInfo($schema, $targetSchema) {
    $info = $this->schemaInfo($schema);
    $info['targetExists'] = $this->schemaExists($targetSchema);
    return $info;
  }

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

  public function dropSchema($schema) {
    $schema = str_replace('`', '``', $schema);
    $this->pdo->exec("DROP SCHEMA `{$schema}`");
    $this->queryTime = microtime(true);
    return true;
  }

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

  public function query($sql) {
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
    return [
      'columns' => $columns,
      'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ];
  }

}
