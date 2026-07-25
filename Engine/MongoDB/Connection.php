<?php

namespace MADB\Engine\MongoDB;

class Connection extends \MADB\Connection\Connection {

  private $manager = null;
  private array|false $serverInfo = false;

  public static function getDefaults() {
    return [
      'name' => 'new',
      'host' => 'localhost',
      'port' => '27017',
      'database' => '',
      'authDatabase' => '',
      'username' => '',
      'password' => '',
      'timeout' => '10',
      'tls' => false,
      'options' => ''
    ];
  }

  public static function supportsOperation($operation): bool {
    return in_array($operation, [
      'schemaList',
      'tableList',
      'schemaDrop'
    ], true);
  }

  public function connect() {
    if (!extension_loaded('mongodb')) {
      throw new \Exception('The mongodb PHP extension is not installed or enabled.');
    }
    $uri = $this->connectionUri();
    $options = $this->driverOptions();
    $this->manager = new \MongoDB\Driver\Manager($uri, $options);
    $this->serverInfo = $this->ping();
  }

  public function test() {
    return [
      'message' => 'The connection to the MongoDB server was successful.',
      'serverInfo' => $this->getServerInfo()
    ];
  }

  public function getServerInfo() {
    return $this->serverInfo;
  }

  public function schemaList() {
    $cursor = $this->command('admin', ['listDatabases' => 1, 'nameOnly' => true]);
    $result = current($cursor->toArray());
    $databases = [];
    foreach (($result->databases ?? []) as $database) {
      if (isset($database->name)) {
        $databases[] = (string)$database->name;
      }
    }
    $this->queryTime = microtime(true);
    return $databases;
  }

  public function tableList($schema) {
    $cursor = $this->command((string)$schema, ['listCollections' => 1, 'nameOnly' => true]);
    $collections = [];
    foreach ($cursor as $collection) {
      if (isset($collection->name)) {
        $collections[] = [
          'name' => (string)$collection->name,
          'type' => 'COLLECTION'
        ];
      }
    }
    $this->queryTime = microtime(true);
    return $collections;
  }

  public function createCollection($database, $collection) {
    $database = trim((string)$database);
    $collection = trim((string)$collection);
    if ($database === '') {
      throw new \Exception('Missing MongoDB database name.');
    }
    if ($collection === '') {
      throw new \Exception('Missing MongoDB collection name.');
    }
    $this->command($database, ['create' => $collection]);
    $this->queryTime = microtime(true);
    return [
      'affectedRows' => 1
    ];
  }

  public function createSchema($schema) {
    throw new \Exception('Creating MongoDB databases is not supported yet.');
  }

  public function schemaInfo($schema) {
    $database = (string)$schema;
    $statsCursor = $this->command($database, ['dbStats' => 1]);
    $stats = current($statsCursor->toArray());
    $collections = (int)($stats->collections ?? 0);
    $objects = (int)($stats->objects ?? 0);
    $bytes = (int)($stats->dataSize ?? 0) + (int)($stats->indexSize ?? 0);
    $this->queryTime = microtime(true);
    return [
      'tables' => $collections,
      'views' => 0,
      'bytes' => $bytes,
      'objects' => $objects,
      'collections' => $collections,
      'indexes' => (int)($stats->indexes ?? 0),
      'storageSize' => (int)($stats->storageSize ?? 0),
      'indexSize' => (int)($stats->indexSize ?? 0)
    ];
  }

  public function renameSchemaInfo($schema, $targetSchema) {
    throw new \Exception('Renaming MongoDB databases is not supported yet.');
  }

  public function renameSchema($schema, $targetSchema) {
    throw new \Exception('Renaming MongoDB databases is not supported yet.');
  }

  public function dropSchema($schema) {
    $this->command((string)$schema, ['dropDatabase' => 1]);
    $this->queryTime = microtime(true);
    return true;
  }

  public function characterSetsAndCollations() {
    return [
      'charsets' => [],
      'collations' => [],
      'engines' => []
    ];
  }

  public function tableFields($schema, $table) {
    throw new \Exception('MongoDB collection field inspection is not supported yet.');
  }

  public function tableDefinition($schema, $table) {
    throw new \Exception('MongoDB collection definition is not supported yet.');
  }

  public function tableReferencedBy($schema, $table) {
    return [];
  }

  public function rowEditorDefinition($schema, $table) {
    throw new \Exception('MongoDB row editing is not supported yet.');
  }

  public function query($sql) {
    throw new \Exception('MongoDB editor execution is not supported yet.');
  }

  public function queryBatch($statements, $resultFiles = [], $schema = false, $progress = false) {
    throw new \Exception('MongoDB editor execution is not supported yet.');
  }

  private function ping(): array {
    $cursor = $this->command('admin', ['ping' => 1]);
    current($cursor->toArray());
    return [
      'vendor' => 'mongodb',
      'vendorLabel' => 'MongoDB',
      'version' => '',
      'versionComment' => '',
      'capabilities' => [
        'mongodb' => true
      ]
    ];
  }

  private function command(string $database, array $command): \MongoDB\Driver\Cursor {
    if ($this->manager === null) {
      $this->connect();
    }
    $cursor = $this->manager->executeCommand($database, new \MongoDB\Driver\Command($command));
    $this->queryTime = microtime(true);
    return $cursor;
  }

  private function connectionUri(): string {
    $host = trim((string)($this->data['host'] ?? 'localhost'));
    $port = trim((string)($this->data['port'] ?? '27017'));
    $auth = '';
    $username = (string)($this->data['username'] ?? '');
    if ($username !== '') {
      $auth = rawurlencode($username) . ':' . rawurlencode((string)($this->data['password'] ?? '')) . '@';
    }
    $path = trim((string)($this->data['database'] ?? ''));
    return 'mongodb://' . $auth . $host . ($port === '' ? '' : ':' . $port) . ($path === '' ? '' : '/' . rawurlencode($path));
  }

  private function driverOptions(): array {
    $options = [];
    $authDatabase = trim((string)($this->data['authDatabase'] ?? ''));
    if ($authDatabase !== '') {
      $options['authSource'] = $authDatabase;
    }
    if (!empty($this->data['tls'])) {
      $options['tls'] = true;
    }
    $timeout = (int)($this->data['timeout'] ?? 0);
    if ($timeout > 0) {
      $options['serverSelectionTimeoutMS'] = $timeout * 1000;
    }
    foreach ($this->extraOptions() as $key => $value) {
      $options[$key] = $value;
    }
    return $options;
  }

  private function extraOptions(): array {
    $raw = trim((string)($this->data['options'] ?? ''));
    if ($raw === '') {
      return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
      throw new \Exception('MongoDB options must be valid JSON object text.');
    }
    return $decoded;
  }

}
