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
    $schema = str_replace('`', '``', $schema);
    $this->pdo->exec("CREATE SCHEMA `{$schema}`");
    $this->queryTime = microtime(true);
    return true;
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
    return $info;
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

  public function query() {
    // ...
  }

}
