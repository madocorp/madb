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
    $this->serverInfo = $this->detectServerInfo();
  }

  /** Coordinates test work in the MySQL engine. */
  public function test() {
    return [
      'message' => 'The connection to the server was successful.',
      'serverInfo' => $this->getServerInfo()
    ];
  }

  /** Returns detected server metadata for status displays. */
  public function getServerInfo() {
    return $this->serverInfo;
  }

  /** Detects MySQL-compatible server version and capability metadata. */
  private function detectServerInfo() {
    $stmt = $this->pdo->query("SELECT VERSION() AS version, @@version_comment AS version_comment");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $version = (string)($row['version'] ?? '');
    $comment = (string)($row['version_comment'] ?? '');
    $vendor = $this->detectServerVendor($version, $comment);
    $versionNumber = $this->normalizeServerVersion($version);
    return [
      'vendor' => $vendor,
      'vendorLabel' => $this->serverVendorLabel($vendor),
      'version' => $version,
      'versionNumber' => $versionNumber,
      'versionComment' => $comment,
      'capabilities' => $this->serverCapabilities($vendor, $versionNumber)
    ];
  }

  /** Returns mysql, mariadb, or unknown from raw server version values. */
  private function detectServerVendor($version, $comment) {
    $haystack = strtolower($version . ' ' . $comment);
    if (strpos($haystack, 'mariadb') !== false) {
      return 'mariadb';
    }
    if (strpos($haystack, 'mysql') !== false || preg_match('/^\d+\.\d+\.\d+/', $version)) {
      return 'mysql';
    }
    return 'unknown';
  }

  /** Returns a human label for a server vendor code. */
  private function serverVendorLabel($vendor) {
    return [
      'mysql' => 'MySQL',
      'mariadb' => 'MariaDB',
      'unknown' => 'MySQL-compatible'
    ][$vendor] ?? 'MySQL-compatible';
  }

  /** Normalizes the first numeric x.y.z version in a raw server string. */
  private function normalizeServerVersion($version) {
    if (preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $match)) {
      return "{$match[1]}.{$match[2]}.{$match[3]}";
    }
    if (preg_match('/(\d+)\.(\d+)/', $version, $match)) {
      return "{$match[1]}.{$match[2]}.0";
    }
    return '';
  }

  /** Returns conservative capability flags used by MySQL version-aware features. */
  private function serverCapabilities($vendor, $versionNumber) {
    return [
      'nativeJson' => $vendor === 'mysql' && version_compare($versionNumber, '5.7.8', '>='),
      'mariaDbJsonAlias' => $vendor === 'mariadb',
      'descendingIndexes' => $vendor === 'mysql' && version_compare($versionNumber, '8.0.0', '>='),
      'invisibleIndexes' => $vendor === 'mysql' && version_compare($versionNumber, '8.0.0', '>='),
      'checkConstraints' => (
        ($vendor === 'mysql' && version_compare($versionNumber, '8.0.16', '>=')) ||
        ($vendor === 'mariadb' && version_compare($versionNumber, '10.2.1', '>='))
      )
    ];
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
