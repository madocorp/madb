<?php

namespace MADB\Engine\MySQL;

use \PDO;

/** Builds MySQL connection defaults, menu labels, PDO setup, and basic schema listing. */
trait ConnectionBootstrapTrait {

  /** Returns defaults data used by the MySQL engine. */
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

  /** Returns menu labels data used by the MySQL engine. */
  public static function getMenuLabels() {
    return [
      'schema' => 'Schema',
      'table' => 'Table'
    ];
  }

  /** Coordinates connect work in the MySQL engine. */
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

  /** Coordinates test work in the MySQL engine. */
  public function test() {
    return "The connection to the server was successful.";
  }

  /** Coordinates schema list work in the MySQL engine. */
  public function schemaList() {
    $stmt = $this->pdo->query("SHOW SCHEMAS");
    $this->queryTime = microtime(true);
    $schemaList = [];
    while ($schema = $stmt->fetchColumn()) {
      $schemaList[] = $schema;
    }
    return $schemaList;
  }

  /** Creates schema data for the MySQL engine. */
  public function createSchema($schema) {
    $schema = $this->escapeIdentifier($schema);
    $this->pdo->exec("CREATE SCHEMA `{$schema}`");
    $this->queryTime = microtime(true);
    return true;
  }

}
