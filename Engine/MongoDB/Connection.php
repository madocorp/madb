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
      'schemaDrop',
      'collectionIndexes',
      'rowDelete'
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

  public function collectionIndexes($schema, $table): array {
    $database = trim((string)$schema);
    $collection = trim((string)$table);
    if ($database === '' || $collection === '') {
      throw new \Exception('MongoDB index inspection needs a database and collection.');
    }
    $cursor = $this->command($database, ['listIndexes' => $collection]);
    $indexes = [];
    foreach ($cursor as $index) {
      $indexes[] = $this->normalizeIndexDocument($index);
    }
    $this->queryTime = microtime(true);
    return $indexes;
  }

  public function rowEditorDefinition($schema, $table) {
    return [
      'columns' => [[
        'COLUMN_NAME' => '_id',
        'COLUMN_TYPE' => 'MongoDB _id',
        'IS_NULLABLE' => 'NO',
        'COLUMN_KEY' => 'PRI',
        'COLUMN_DEFAULT' => null,
        'EXTRA' => ''
      ]]
    ];
  }

  public function query($sql, $resultFile = false, $schema = false) {
    if ($this->manager === null) {
      $this->connect();
    }
    $parsed = $this->parseCommandText((string)$sql, $schema);
    $result = $this->executeParsedCommand($parsed);
    $this->queryTime = microtime(true);
    $table = $result instanceof \MongoDB\Driver\Cursor
      ? $this->cursorResult($parsed, $result)
      : $this->commandResultStatus($result);
    if ($resultFile !== false) {
      if (!isset($table['columns'], $table['rows'])) {
        return $table;
      }
      return $this->writeResultFile($table['columns'], $table['rows'], $resultFile);
    }
    return $table;
  }

  public function findDocumentById($schema, $table, $id) {
    if ($this->manager === null) {
      $this->connect();
    }
    $database = trim((string)$schema);
    $collection = trim((string)$table);
    if ($database === '' || $collection === '') {
      throw new \Exception('MongoDB find document lookup needs a database and collection.');
    }
    $document = $this->rawDocumentById($database, $collection, (string)$id);
    if ($document !== false) {
      $this->queryTime = microtime(true);
      return $this->documentJson($document, true);
    }
    $this->queryTime = microtime(true);
    return false;
  }

  public function documentIdFilterJson($schema, $table, $id): string {
    if ($this->manager === null) {
      $this->connect();
    }
    $database = trim((string)$schema);
    $collection = trim((string)$table);
    $current = $this->rawDocumentById($database, $collection, (string)$id);
    if ($current === false) {
      throw new \Exception('MongoDB document was not found.');
    }
    $current = $this->documentArray($current);
    return $this->documentJson(['_id' => $current['_id'] ?? null], true);
  }

  public function replacementDocumentJson($json, bool $pretty = false): string {
    return $this->documentJson($this->replacementDocument((string)$json), $pretty);
  }

  public function insertDocumentJson($json, bool $pretty = false): string {
    $json = $this->bsonObjectJson((string)$json, 'MongoDB document');
    if (!$pretty) {
      return $json;
    }
    $decoded = json_decode($json);
    $prettyJson = \MADB\Engine\MongoDB\MongoLanguage::prettyJson($decoded);
    return $prettyJson === false ? $json : $prettyJson;
  }

  public function updateDocumentById($schema, $table, $id, $json) {
    if ($this->manager === null) {
      $this->connect();
    }
    $database = trim((string)$schema);
    $collection = trim((string)$table);
    if ($database === '' || $collection === '') {
      throw new \Exception('MongoDB document update needs a database and collection.');
    }
    $current = $this->rawDocumentById($database, $collection, (string)$id);
    if ($current === false) {
      throw new \Exception('MongoDB document was not found.');
    }
    $current = $this->documentArray($current);
    $replacement = $this->replacementDocument((string)$json);
    if (!array_key_exists('_id', $replacement)) {
      throw new \Exception('MongoDB document _id cannot be removed.');
    }
    if (!$this->sameId($current['_id'] ?? null, $replacement['_id'])) {
      throw new \Exception('MongoDB document _id cannot be changed.');
    }
    $bulk = new \MongoDB\Driver\BulkWrite();
    $bulk->update(
      ['_id' => $current['_id']],
      $replacement,
      ['multi' => false, 'upsert' => false]
    );
    $result = $this->manager->executeBulkWrite($database . '.' . $collection, $bulk);
    $this->queryTime = microtime(true);
    return [
      'matchedRows' => $result->getMatchedCount(),
      'modifiedRows' => $result->getModifiedCount(),
      'affectedRows' => $result->getModifiedCount()
    ];
  }

  public function deleteDocumentsCommandJson($schema, $table, array $ids, bool $pretty = false): string {
    if ($this->manager === null) {
      $this->connect();
    }
    $database = trim((string)$schema);
    $collection = trim((string)$table);
    if ($database === '' || $collection === '') {
      throw new \Exception('MongoDB document delete needs a database and collection.');
    }
    if (empty($ids)) {
      throw new \Exception('MongoDB document delete needs at least one _id.');
    }
    $deletes = [];
    $seen = [];
    foreach ($ids as $id) {
      $current = $this->rawDocumentById($database, $collection, (string)$id);
      if ($current === false) {
        throw new \Exception('MongoDB document was not found: ' . (string)$id);
      }
      $current = $this->documentArray($current);
      $actualId = $current['_id'] ?? null;
      $key = $this->idFingerprint($actualId);
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;
      $deletes[] = [
        'q' => ['_id' => $actualId],
        'limit' => 1
      ];
    }
    return $this->documentJson([
      'delete' => $collection,
      'deletes' => $deletes
    ], $pretty);
  }

  public function queryBatch($statements, $resultFiles = [], $schema = false, $progress = false, $cancelled = false) {
    if (is_callable($schema) && $progress === false) {
      $progress = $schema;
      $schema = false;
    }
    if (!is_array($statements) || empty($statements)) {
      throw new \Exception('Query is empty.');
    }
    $results = [];
    $resultIndex = 0;
    $interrupted = false;
    foreach ($statements as $index => $statement) {
      if (is_callable($cancelled) && $cancelled()) {
        $interrupted = true;
        break;
      }
      $statementIndex = $statement['index'] ?? $index;
      $range = [
        'start' => $statement['range']['start'] ?? $statement['start'] ?? 0,
        'end' => $statement['range']['end'] ?? $statement['end'] ?? 0
      ];
      $sql = trim((string)($statement['sql'] ?? ''));
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
            'range' => $range
          ]]),
          'resultCount' => $resultIndex
        ]);
      }
      try {
        $file = $resultFiles[$resultIndex] ?? false;
        $result = $this->query($sql, $file, $schema);
        $finished = microtime(true);
        $entry = [
          'index' => $statementIndex,
          'sql' => $sql,
          'status' => 'OK',
          'startedAt' => $started,
          'time' => round($finished - $started, 4),
          'finishedAt' => $finished,
          'range' => $range
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
          'range' => $range
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
      'resultCount' => $resultIndex,
      'interrupted' => $interrupted
    ];
  }

  private function ping(): array {
    $cursor = $this->command('admin', ['ping' => 1]);
    current($cursor->toArray());
    $buildInfo = $this->firstCommandDocument('admin', ['buildInfo' => 1]);
    $hello = $this->firstCommandDocument('admin', ['hello' => 1]);
    if ($hello === false) {
      $hello = $this->firstCommandDocument('admin', ['isMaster' => 1]);
    }
    $modules = $this->documentValue($buildInfo, 'modules', []);
    if (!is_array($modules)) {
      $modules = [];
    }
    return [
      'vendor' => 'mongodb',
      'vendorLabel' => 'MongoDB',
      'version' => (string)$this->documentValue($buildInfo, 'version', ''),
      'versionNumber' => (string)$this->documentValue($buildInfo, 'version', ''),
      'versionComment' => (string)$this->documentValue($buildInfo, 'gitVersion', ''),
      'maxWireVersion' => $this->documentValue($hello, 'maxWireVersion', null),
      'minWireVersion' => $this->documentValue($hello, 'minWireVersion', null),
      'storageEngines' => $this->documentValue($buildInfo, 'storageEngines', []),
      'javascriptEngine' => $this->documentValue($buildInfo, 'javascriptEngine', ''),
      'allocator' => $this->documentValue($buildInfo, 'allocator', ''),
      'bits' => $this->documentValue($buildInfo, 'bits', null),
      'debug' => $this->documentValue($buildInfo, 'debug', null),
      'modules' => $modules,
      'capabilities' => [
        'mongodb' => true,
        'mongodbEnterprise' => in_array('enterprise', $modules, true),
        'mongodbReplicaSet' => $this->documentValue($hello, 'setName', '') !== '',
        'mongodbWritablePrimary' => (bool)$this->documentValue($hello, 'isWritablePrimary', false)
      ]
    ];
  }

  private function firstCommandDocument(string $database, array $command) {
    try {
      $cursor = $this->command($database, $command);
      $rows = $cursor->toArray();
      return $rows[0] ?? false;
    } catch (\Throwable $e) {
      return false;
    }
  }

  private function documentValue($document, string $key, $default = null) {
    if (is_object($document) && isset($document->$key)) {
      return $document->$key;
    }
    if (is_array($document) && array_key_exists($key, $document)) {
      return $document[$key];
    }
    return $default;
  }

  private function command(string $database, array $command): \MongoDB\Driver\Cursor {
    if ($this->manager === null) {
      $this->connect();
    }
    $cursor = $this->manager->executeCommand($database, new \MongoDB\Driver\Command($command));
    $this->queryTime = microtime(true);
    return $cursor;
  }

  private function rawDocumentById(string $database, string $collection, string $id) {
    $candidates = $this->idLookupCandidates($id);
    $filter = count($candidates) === 1 ? ['_id' => $candidates[0]] : ['_id' => ['$in' => $candidates]];
    $cursor = $this->manager->executeQuery(
      $database . '.' . $collection,
      new \MongoDB\Driver\Query($filter, ['limit' => 1])
    );
    foreach ($cursor as $document) {
      return $document;
    }
    return false;
  }

  private function parseCommandText(string $text, $database = false): array {
    $text = trim($text);
    if ($text === '') {
      throw new \Exception('Query is empty.');
    }
    $command = $this->parseBsonDocument($text, 'MongoDB command', 'object');
    $commandName = array_key_first($command);
    if ($commandName === null) {
      throw new \Exception('MongoDB command document cannot be empty.');
    }
    if (!$this->isKnownCommandName((string)$commandName)) {
      $knownLater = $this->knownCommandKeyAfterFirst($command);
      if ($knownLater !== false) {
        throw new \Exception('MongoDB command name must be the first field. Found "' . $commandName . '" first, but command key "' . $knownLater . '" appears later.');
      }
    }
    $database = trim((string)($database !== false ? $database : ''));
    if ($database === '') {
      $database = trim((string)($this->data['database'] ?? ''));
    }
    if ($database === '') {
      throw new \Exception('MongoDB command execution needs a selected database.');
    }
    return [
      'database' => $database,
      'commandName' => (string)$commandName,
      'command' => $command,
      'mode' => $this->commandMode((string)$commandName, $command)
    ];
  }

  private function parseFindQuery(string $text): array {
    $parsed = $this->parseCommandText($text);
    return $parsed + [
      'operation' => $parsed['commandName']
    ];
  }

  private function parseBsonDocument(string $text, string $label, string $documentType = 'array'): array {
    $text = \MADB\Engine\MongoDB\MongoLanguage::stripComments($text);
    $text = \MADB\Engine\MongoDB\MongoLanguage::quoteBareKeys($text);
    $typemap = [
      'root' => 'array',
      'document' => $documentType,
      'array' => 'array'
    ];
    if (class_exists('\MongoDB\BSON\Document', false) && method_exists('\MongoDB\BSON\Document', 'fromJSON')) {
      try {
        $document = \MongoDB\BSON\Document::fromJSON($text)->toPHP($typemap);
      } catch (\Throwable $e) {
        throw new \Exception($label . ' JSON is invalid: ' . $e->getMessage());
      }
    } else if (function_exists('MongoDB\BSON\fromJSON') && function_exists('MongoDB\BSON\toPHP')) {
      try {
        $document = \MongoDB\BSON\toPHP(
          \MongoDB\BSON\fromJSON($text),
          $typemap
        );
      } catch (\Throwable $e) {
        throw new \Exception($label . ' JSON is invalid: ' . $e->getMessage());
      }
    } else {
      throw new \Exception('MongoDB BSON JSON parsing is not available.');
    }
    if (!is_array($document) || array_is_list($document)) {
      throw new \Exception($label . ' must be a JSON object.');
    }
    return $document;
  }

  private function executeParsedCommand(array $parsed) {
    $driverCommand = new \MongoDB\Driver\Command($parsed['command']);
    return match ($parsed['mode']) {
      'read' => $this->manager->executeReadCommand($parsed['database'], $driverCommand),
      'write' => $this->manager->executeWriteCommand($parsed['database'], $driverCommand),
      'readWrite' => $this->manager->executeReadWriteCommand($parsed['database'], $driverCommand),
      default => $this->manager->executeCommand($parsed['database'], $driverCommand)
    };
  }

  private function commandMode(string $commandName, array $command): string {
    $name = strtolower($commandName);
    if (in_array($name, $this->readCommands(), true)) {
      return 'read';
    }
    if (in_array($name, $this->writeCommands(), true)) {
      return 'write';
    }
    if ($name === 'aggregate') {
      return $this->aggregateWrites($command['pipeline'] ?? []) ? 'readWrite' : 'read';
    }
    if (in_array($name, $this->readWriteCommands(), true)) {
      return 'readWrite';
    }
    return 'generic';
  }

  private function readCommands(): array {
    return [
      'find',
      'count',
      'distinct',
      'listcollections',
      'listindexes',
      'dbstats',
      'collstats'
    ];
  }

  private function writeCommands(): array {
    return [
      'drop',
      'create',
      'createindexes',
      'dropindexes',
      'renamecollection',
      'dropdatabase',
      'insert',
      'update',
      'delete'
    ];
  }

  private function readWriteCommands(): array {
    return [
      'findandmodify',
      'mapreduce'
    ];
  }

  private function isKnownCommandName(string $commandName): bool {
    return in_array(strtolower($commandName), array_merge(
      $this->readCommands(),
      $this->writeCommands(),
      $this->readWriteCommands(),
      ['aggregate']
    ), true);
  }

  private function knownCommandKeyAfterFirst(array $command) {
    $known = array_merge(
      $this->readCommands(),
      $this->writeCommands(),
      $this->readWriteCommands(),
      ['aggregate']
    );
    $first = true;
    foreach ($command as $key => $value) {
      if ($first) {
        $first = false;
        continue;
      }
      if (in_array(strtolower((string)$key), $known, true)) {
        return (string)$key;
      }
    }
    return false;
  }

  private function aggregateWrites($pipeline): bool {
    if (!is_array($pipeline)) {
      return false;
    }
    foreach ($pipeline as $stage) {
      if (is_object($stage)) {
        $stage = get_object_vars($stage);
      }
      if (!is_array($stage)) {
        continue;
      }
      $stageName = array_key_first($stage);
      if ($stageName === '$out' || $stageName === '$merge') {
        return true;
      }
    }
    return false;
  }

  private function documentsToTable(array $documents): array {
    $fields = [];
    foreach ($documents as $document) {
      foreach ($this->documentArray($document) as $field => $value) {
        if ($field !== '_id' && !in_array($field, $fields, true)) {
          $fields[] = $field;
        }
      }
    }
    $visibleFields = array_slice($fields, 0, 10);
    $overflowFields = [];
    if (count($fields) > 10) {
      $visibleFields = array_slice($fields, 0, 9);
      $overflowFields = array_slice($fields, 9);
    }
    $columns = array_merge(['_id', '_document'], $visibleFields);
    if (!empty($overflowFields)) {
      $columns[] = '_remnant';
    }
    $rows = [];
    foreach ($documents as $document) {
      $data = $this->documentArray($document);
      $row = [
        '_id' => $this->documentId($data['_id'] ?? ''),
        '_document' => '{' . $this->humanBytes($this->documentSize($document)) . '}'
      ];
      foreach ($visibleFields as $field) {
        $row[$field] = array_key_exists($field, $data) ? $this->fieldValue($data[$field]) : null;
      }
      if (!empty($overflowFields)) {
        $other = [];
        foreach ($overflowFields as $field) {
          if (array_key_exists($field, $data)) {
            $other[] = $field;
          }
        }
        $row['_remnant'] = implode(', ', $other);
      }
      $rows[] = $row;
    }
    return [
      'columns' => $columns,
      'rows' => $rows
    ];
  }

  private function cursorResult(array $parsed, \MongoDB\Driver\Cursor $cursor): array {
    $documents = iterator_to_array($cursor, false);
    return $this->cursorDocumentsShouldUseTable($parsed, $documents)
      ? $this->documentsToTable($documents)
      : $this->commandDocumentsStatus($documents);
  }

  private function cursorDocumentsShouldUseTable(array $parsed, array $documents): bool {
    $name = strtolower((string)($parsed['commandName'] ?? ''));
    if (in_array($name, ['find', 'aggregate'], true) && ($parsed['mode'] ?? '') === 'read') {
      return true;
    }
    return count($documents) !== 1;
  }

  private function commandDocumentsStatus(array $documents): array {
    $document = count($documents) === 1 ? $documents[0] : $documents;
    $status = [
      'message' => $this->documentJson($document, true)
    ];
    $data = $this->documentArray($document);
    if (array_key_exists('n', $data) && is_numeric($data['n'])) {
      $status['affectedRows'] = (int)$data['n'];
    }
    if (array_key_exists('nModified', $data) && is_numeric($data['nModified'])) {
      $status['modifiedRows'] = (int)$data['nModified'];
    }
    return $status;
  }

  private function commandResultStatus($result): array {
    if ($result instanceof \MongoDB\Driver\WriteResult) {
      $matched = method_exists($result, 'getMatchedCount') ? $result->getMatchedCount() : 0;
      $modified = method_exists($result, 'getModifiedCount') ? $result->getModifiedCount() : 0;
      $deleted = method_exists($result, 'getDeletedCount') ? $result->getDeletedCount() : 0;
      $inserted = method_exists($result, 'getInsertedCount') ? $result->getInsertedCount() : 0;
      $status = [
        'matchedRows' => $matched,
        'modifiedRows' => $modified,
        'deletedRows' => $deleted,
        'insertedRows' => $inserted,
        'affectedRows' => $modified + $deleted + $inserted
      ];
      $status['message'] = $this->documentJson($status, true);
      return $status;
    }
    return $this->commandDocumentsStatus([$result]);
  }

  private function documentArray($document): array {
    if ($document instanceof \stdClass) {
      return get_object_vars($document);
    }
    if (is_array($document)) {
      return $document;
    }
    return [];
  }

  private function normalizeIndexDocument($document): array {
    $spec = $this->documentArray($document);
    $name = (string)($spec['name'] ?? '');
    $key = $this->documentArray($spec['key'] ?? []);
    return [
      'name' => $name,
      'key' => $key,
      'unique' => (bool)($spec['unique'] ?? false),
      'sparse' => (bool)($spec['sparse'] ?? false),
      'hidden' => (bool)($spec['hidden'] ?? false),
      'expireAfterSeconds' => array_key_exists('expireAfterSeconds', $spec) ? $spec['expireAfterSeconds'] : null,
      'partialFilterExpression' => $this->documentArray($spec['partialFilterExpression'] ?? []),
      'collation' => $this->documentArray($spec['collation'] ?? []),
      'spec' => $this->indexSpecArray($spec),
      'json' => $this->documentJson($this->indexSpecArray($spec), true)
    ];
  }

  private function indexSpecArray(array $spec): array {
    unset($spec['v'], $spec['ns']);
    foreach ($spec as $key => $value) {
      if ($value instanceof \stdClass) {
        $spec[$key] = $this->documentArray($value);
      }
    }
    return $spec;
  }

  private function documentId($id): string {
    if (is_object($id) && method_exists($id, '__toString')) {
      return (string)$id;
    }
    if (is_scalar($id)) {
      return (string)$id;
    }
    return $this->jsonValue($id);
  }

  private function fieldValue($value) {
    if ($value === null || is_int($value) || is_float($value) || is_string($value)) {
      return $value;
    }
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    return $this->jsonValue($value);
  }

  private function jsonValue($value): string {
    if (function_exists('MongoDB\BSON\fromPHP') && function_exists('MongoDB\BSON\toRelaxedExtendedJSON')) {
      try {
        $json = \MongoDB\BSON\toRelaxedExtendedJSON(\MongoDB\BSON\fromPHP(['value' => $value]));
        $decoded = json_decode($json, true);
        if (is_array($decoded) && array_key_exists('value', $decoded)) {
          $json = json_encode($decoded['value'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
          if ($json !== false) {
            return $json;
          }
        }
      } catch (\Throwable $e) {
      }
    }
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? (string)$value : $json;
  }

  private function documentJson($document, bool $pretty = false): string {
    if (function_exists('MongoDB\BSON\fromPHP') && function_exists('MongoDB\BSON\toRelaxedExtendedJSON')) {
      try {
        $json = \MongoDB\BSON\toRelaxedExtendedJSON(\MongoDB\BSON\fromPHP($document));
        if ($pretty) {
          $decoded = json_decode($json, true);
          $prettyJson = \MADB\Engine\MongoDB\MongoLanguage::prettyJson($decoded);
          if ($prettyJson !== false) {
            return $prettyJson;
          }
        }
        return $json;
      } catch (\Throwable $e) {
      }
    }
    $json = $pretty
      ? \MADB\Engine\MongoDB\MongoLanguage::prettyJson($document)
      : json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? (string)$document : $json;
  }

  private function replacementDocument(string $json): array {
    $json = trim($json);
    if ($json === '') {
      throw new \Exception('MongoDB document JSON is empty.');
    }
    return $this->parseBsonDocument($json, 'MongoDB document');
  }

  private function bsonObjectJson(string $text, string $label): string {
    $text = trim($text);
    if ($text === '') {
      throw new \Exception($label . ' JSON is empty.');
    }
    $text = \MADB\Engine\MongoDB\MongoLanguage::stripComments($text);
    $text = \MADB\Engine\MongoDB\MongoLanguage::quoteBareKeys($text);
    if (class_exists('\MongoDB\BSON\Document', false) && method_exists('\MongoDB\BSON\Document', 'fromJSON')) {
      try {
        $json = \MongoDB\BSON\Document::fromJSON($text)->toRelaxedExtendedJSON();
      } catch (\Throwable $e) {
        throw new \Exception($label . ' JSON is invalid: ' . $e->getMessage());
      }
    } else if (function_exists('MongoDB\BSON\fromJSON') && function_exists('MongoDB\BSON\toRelaxedExtendedJSON')) {
      try {
        $json = \MongoDB\BSON\toRelaxedExtendedJSON(\MongoDB\BSON\fromJSON($text));
      } catch (\Throwable $e) {
        throw new \Exception($label . ' JSON is invalid: ' . $e->getMessage());
      }
    } else {
      $json = $text;
    }
    $document = json_decode($json);
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \Exception($label . ' JSON is invalid: ' . json_last_error_msg());
    }
    if (!is_object($document)) {
      throw new \Exception($label . ' must be a JSON object.');
    }
    $normalized = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $normalized === false ? $json : $normalized;
  }

  private function sameId($left, $right): bool {
    return $this->idFingerprint($left) === $this->idFingerprint($right);
  }

  private function idFingerprint($id): string {
    if (function_exists('MongoDB\BSON\fromPHP') && function_exists('MongoDB\BSON\toCanonicalExtendedJSON')) {
      try {
        return \MongoDB\BSON\toCanonicalExtendedJSON(\MongoDB\BSON\fromPHP(['_id' => $id]));
      } catch (\Throwable $e) {
      }
    }
    if (is_object($id) && method_exists($id, '__toString')) {
      return get_class($id) . ':' . (string)$id;
    }
    return gettype($id) . ':' . serialize($id);
  }

  private function documentSize($document): int {
    if (function_exists('MongoDB\BSON\fromPHP')) {
      try {
        return strlen(\MongoDB\BSON\fromPHP($document));
      } catch (\Throwable $e) {
      }
    }
    return strlen($this->jsonValue($document));
  }

  private function humanBytes(int $bytes): string {
    return \MADB\App\Format::bytes($bytes, 1, '');
  }

  private function writeResultFile(array $columns, array $rows, string $resultFile): array {
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
      foreach ($rows as $row) {
        $values = [];
        foreach ($columns as $column) {
          $values[] = $row[$column] ?? null;
        }
        $this->writeTsvLine($handle, $values);
      }
    } finally {
      fclose($handle);
    }
    return [
      'columns' => $columns,
      'rowCount' => count($rows)
    ];
  }

  private function writeTsvLine($handle, array $values): void {
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

  private function idLookupCandidates(string $id): array {
    $candidates = [];
    if (preg_match('/^[a-fA-F0-9]{24}$/', $id) && class_exists('\MongoDB\BSON\ObjectId', false)) {
      try {
        $candidates[] = new \MongoDB\BSON\ObjectId($id);
      } catch (\Throwable $e) {
      }
    }
    if (preg_match('/^-?\d+$/', $id)) {
      $candidates[] = (int)$id;
    }
    $candidates[] = $id;
    return $candidates;
  }

}
