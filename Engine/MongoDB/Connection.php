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

  public function query($sql, $resultFile = false) {
    if ($this->manager === null) {
      $this->connect();
    }
    $query = $this->parseQuery((string)$sql);
    if (($query['operation'] ?? '') === 'replaceOne') {
      return $this->replaceOne($query);
    }
    $cursor = $this->manager->executeQuery(
      $query['database'] . '.' . $query['collection'],
      new \MongoDB\Driver\Query($query['filter'], ['limit' => $query['limit']])
    );
    $documents = [];
    foreach ($cursor as $document) {
      $documents[] = $document;
    }
    $this->queryTime = microtime(true);
    $table = $this->documentsToTable($documents);
    if ($resultFile !== false) {
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

  public function convertShellQueryToJsonCommand(string $text): string {
    $query = $this->parseShellQuery($text);
    $command = [
      'database' => $query['database'],
      'collection' => $query['collection']
    ];
    if (($query['operation'] ?? '') === 'find') {
      $command['find'] = [
        'filter' => $query['filter'],
        'limit' => $query['limit']
      ];
    } else if (($query['operation'] ?? '') === 'replaceOne') {
      $command['replaceOne'] = [
        'filter' => $query['filter'],
        'replacement' => $query['replacement']
      ];
    } else {
      throw new \Exception('Unsupported MongoDB query operation.');
    }
    return $this->documentJson($command, true);
  }

  public function convertShellQueryToPhpDriver(string $text): string {
    $query = $this->parseShellQuery($text);
    $namespace = $this->phpString($query['database'] . '.' . $query['collection']);
    if (($query['operation'] ?? '') === 'find') {
      return $this->phpDriverFindSnippet($namespace, $query);
    }
    if (($query['operation'] ?? '') === 'replaceOne') {
      return $this->phpDriverReplaceOneSnippet($namespace, $query);
    }
    throw new \Exception('Unsupported MongoDB query operation.');
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
        $result = $this->query($sql, $file);
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

  private function parseQuery(string $text): array {
    $text = trim($text);
    if ($text === '') {
      throw new \Exception('Query is empty.');
    }
    if (str_starts_with($text, '{')) {
      throw new \Exception('MongoDB execution supports shell-style find and replaceOne queries only.');
    }
    if (preg_match('/\.\s*replaceOne\s*\(/', $text)) {
      return $this->parseShellReplaceOneQuery($text);
    }
    return $this->parseShellFindQuery($text);
  }

  private function parseShellQuery(string $text): array {
    $text = trim($text);
    if ($text === '') {
      throw new \Exception('Query is empty.');
    }
    if (str_starts_with($text, '{')) {
      throw new \Exception('MongoDB conversion expects a shell-style query.');
    }
    if (preg_match('/\.\s*replaceOne\s*\(/', $text)) {
      return $this->parseShellReplaceOneQuery($text);
    }
    return $this->parseShellFindQuery($text);
  }

  private function parseFindQuery(string $text): array {
    return $this->parseQuery($text);
  }

  private function parseShellFindQuery(string $text): array {
    $text = rtrim(trim($text), ';');
    $pattern = '/^db(?:\s*\.\s*getSiblingDB\(\s*((?:"(?:\\\\.|[^"\\\\])*")|(?:\'(?:\\\\.|[^\'\\\\])*\'))\s*\))?\s*\.\s*getCollection\(\s*((?:"(?:\\\\.|[^"\\\\])*")|(?:\'(?:\\\\.|[^\'\\\\])*\'))\s*\)\s*\.\s*find\s*\(/s';
    if (!preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE)) {
      throw new \Exception('MongoDB editor execution only supports generated find queries.');
    }
    $database = isset($match[1][0]) && $match[1][0] !== ''
      ? $this->decodeJsString($match[1][0])
      : trim((string)($this->data['database'] ?? ''));
    $collection = $this->decodeJsString($match[2][0]);
    $filterStart = $match[0][1] + strlen($match[0][0]);
    $filterEnd = $this->matchingParenthesisOffset($text, $filterStart - 1);
    if ($filterEnd === false) {
      throw new \Exception('MongoDB find query has an unterminated filter.');
    }
    $filterJson = trim(substr($text, $filterStart, $filterEnd - $filterStart));
    $tail = trim(substr($text, $filterEnd + 1));
    if (!preg_match('/^(?:\s*\.\s*limit\s*\(\s*(\d+)\s*\))?$/s', $tail, $limitMatch)) {
      throw new \Exception('MongoDB editor execution only supports find(...).limit(n).');
    }
    $filter = $filterJson === '' ? [] : json_decode($filterJson, true);
    if (!is_array($filter)) {
      throw new \Exception('MongoDB find filter must be a JSON object.');
    }
    if ($database === '' || $collection === '') {
      throw new \Exception('MongoDB find query must include database and collection names.');
    }
    return [
      'operation' => 'find',
      'database' => $database,
      'collection' => $collection,
      'filter' => $filter,
      'limit' => $this->positiveLimit($limitMatch[1] ?? null)
    ];
  }

  private function parseShellReplaceOneQuery(string $text): array {
    $text = rtrim(trim($text), ';');
    $pattern = '/^db(?:\s*\.\s*getSiblingDB\(\s*((?:"(?:\\\\.|[^"\\\\])*")|(?:\'(?:\\\\.|[^\'\\\\])*\'))\s*\))?\s*\.\s*getCollection\(\s*((?:"(?:\\\\.|[^"\\\\])*")|(?:\'(?:\\\\.|[^\'\\\\])*\'))\s*\)\s*\.\s*replaceOne\s*\(/s';
    if (!preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE)) {
      throw new \Exception('MongoDB editor execution only supports generated find and replaceOne queries.');
    }
    $database = isset($match[1][0]) && $match[1][0] !== ''
      ? $this->decodeJsString($match[1][0])
      : trim((string)($this->data['database'] ?? ''));
    $collection = $this->decodeJsString($match[2][0]);
    $argsStart = $match[0][1] + strlen($match[0][0]);
    $argsEnd = $this->matchingParenthesisOffset($text, $argsStart - 1);
    if ($argsEnd === false) {
      throw new \Exception('MongoDB replaceOne query has unterminated arguments.');
    }
    $tail = trim(substr($text, $argsEnd + 1));
    if ($tail !== '') {
      throw new \Exception('MongoDB replaceOne query must end after replaceOne(...).');
    }
    [$filterJson, $replacementJson] = $this->replaceOneArguments(substr($text, $argsStart, $argsEnd - $argsStart));
    $filter = $this->replacementDocument($filterJson);
    $replacement = $this->replacementDocument($replacementJson);
    if (!array_key_exists('_id', $filter)) {
      throw new \Exception('MongoDB replaceOne filter must contain _id.');
    }
    if (!array_key_exists('_id', $replacement)) {
      throw new \Exception('MongoDB replacement document must contain _id.');
    }
    if (!$this->sameId($filter['_id'], $replacement['_id'])) {
      throw new \Exception('MongoDB document _id cannot be changed.');
    }
    if ($database === '' || $collection === '') {
      throw new \Exception('MongoDB replaceOne query must include database and collection names.');
    }
    return [
      'operation' => 'replaceOne',
      'database' => $database,
      'collection' => $collection,
      'filter' => $filter,
      'replacement' => $replacement
    ];
  }

  private function replaceOneArguments(string $arguments): array {
    $comma = $this->topLevelCommaOffset($arguments);
    if ($comma === false) {
      throw new \Exception('MongoDB replaceOne query must include filter and replacement documents.');
    }
    $filter = trim(substr($arguments, 0, $comma));
    $replacement = trim(substr($arguments, $comma + 1));
    if ($filter === '' || $replacement === '') {
      throw new \Exception('MongoDB replaceOne query must include filter and replacement documents.');
    }
    if ($this->topLevelCommaOffset($replacement) !== false) {
      throw new \Exception('MongoDB replaceOne options are not supported yet.');
    }
    return [$filter, $replacement];
  }

  private function topLevelCommaOffset(string $text) {
    $depth = 0;
    $length = strlen($text);
    for ($i = 0; $i < $length; $i++) {
      $char = $text[$i];
      if ($char === '"' || $char === "'") {
        $i = $this->skipQuotedString($text, $i, $char);
      } else if ($char === '{' || $char === '[' || $char === '(') {
        $depth++;
      } else if ($char === '}' || $char === ']' || $char === ')') {
        $depth = max(0, $depth - 1);
      } else if ($char === ',' && $depth === 0) {
        return $i;
      }
    }
    return false;
  }

  private function replaceOne(array $query): array {
    $bulk = new \MongoDB\Driver\BulkWrite();
    $bulk->update(
      $query['filter'],
      $query['replacement'],
      ['multi' => false, 'upsert' => false]
    );
    $result = $this->manager->executeBulkWrite($query['database'] . '.' . $query['collection'], $bulk);
    $this->queryTime = microtime(true);
    return [
      'matchedRows' => $result->getMatchedCount(),
      'modifiedRows' => $result->getModifiedCount(),
      'affectedRows' => $result->getModifiedCount()
    ];
  }

  private function phpDriverFindSnippet(string $namespace, array $query): string {
    return '$filterJson = <<<' . "'JSON'\n" .
      $this->documentJson($query['filter'], true) . "\n" .
      "JSON;\n\n" .
      '$filter = \MongoDB\BSON\toPHP(\MongoDB\BSON\fromJSON($filterJson), [' . "\n" .
      "  'root' => 'array',\n" .
      "  'document' => 'array',\n" .
      "  'array' => 'array'\n" .
      "]);\n\n" .
      '$cursor = $manager->executeQuery(' . $namespace . ', new \MongoDB\Driver\Query($filter, [' . "\n" .
      "  'limit' => " . (int)$query['limit'] . "\n" .
      ']));';
  }

  private function phpDriverReplaceOneSnippet(string $namespace, array $query): string {
    return '$filterJson = <<<' . "'JSON'\n" .
      $this->documentJson($query['filter'], true) . "\n" .
      "JSON;\n" .
      '$replacementJson = <<<' . "'JSON'\n" .
      $this->documentJson($query['replacement'], true) . "\n" .
      "JSON;\n\n" .
      '$filter = \MongoDB\BSON\toPHP(\MongoDB\BSON\fromJSON($filterJson), [' . "\n" .
      "  'root' => 'array',\n" .
      "  'document' => 'array',\n" .
      "  'array' => 'array'\n" .
      "]);\n" .
      '$replacement = \MongoDB\BSON\toPHP(\MongoDB\BSON\fromJSON($replacementJson), [' . "\n" .
      "  'root' => 'array',\n" .
      "  'document' => 'array',\n" .
      "  'array' => 'array'\n" .
      "]);\n\n" .
      '$bulk = new \MongoDB\Driver\BulkWrite();' . "\n" .
      '$bulk->update($filter, $replacement, [' . "\n" .
      "  'multi' => false,\n" .
      "  'upsert' => false\n" .
      "]);\n" .
      '$result = $manager->executeBulkWrite(' . $namespace . ', $bulk);';
  }

  private function phpString(string $value): string {
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
  }

  private function decodeJsString(string $text): string {
    $quote = $text[0] ?? '"';
    $body = substr($text, 1, -1);
    if ($quote === '"') {
      $decoded = json_decode($text);
      if (is_string($decoded)) {
        return $decoded;
      }
    }
    return stripcslashes($body);
  }

  private function matchingParenthesisOffset(string $text, int $openOffset) {
    $depth = 0;
    $length = strlen($text);
    for ($i = $openOffset; $i < $length; $i++) {
      $char = $text[$i];
      if ($char === '"' || $char === "'") {
        $i = $this->skipQuotedString($text, $i, $char);
        continue;
      }
      if ($char === '(') {
        $depth++;
      } else if ($char === ')') {
        $depth--;
        if ($depth === 0) {
          return $i;
        }
      }
    }
    return false;
  }

  private function skipQuotedString(string $text, int $offset, string $quote): int {
    $length = strlen($text);
    for ($i = $offset + 1; $i < $length; $i++) {
      if ($text[$i] === '\\') {
        $i++;
      } else if ($text[$i] === $quote) {
        return $i;
      }
    }
    return $length - 1;
  }

  private function positiveLimit($limit): int {
    if (is_int($limit) && $limit > 0) {
      return $limit;
    }
    if (is_string($limit) && ctype_digit($limit) && (int)$limit > 0) {
      return (int)$limit;
    }
    return \MADB\App\Settings::defaultSelectLimit();
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

  private function documentArray($document): array {
    if ($document instanceof \stdClass) {
      return get_object_vars($document);
    }
    if (is_array($document)) {
      return $document;
    }
    return [];
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
          $prettyJson = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
          if ($prettyJson !== false) {
            return $prettyJson;
          }
        }
        return $json;
      } catch (\Throwable $e) {
      }
    }
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0);
    $json = json_encode($document, $flags);
    return $json === false ? (string)$document : $json;
  }

  private function replacementDocument(string $json): array {
    $json = trim($json);
    if ($json === '') {
      throw new \Exception('MongoDB document JSON is empty.');
    }
    if (!function_exists('MongoDB\BSON\fromJSON') || !function_exists('MongoDB\BSON\toPHP')) {
      throw new \Exception('MongoDB BSON JSON parsing is not available.');
    }
    try {
      $document = \MongoDB\BSON\toPHP(
        \MongoDB\BSON\fromJSON($json),
        ['root' => 'array', 'document' => 'array', 'array' => 'array']
      );
    } catch (\Throwable $e) {
      throw new \Exception('MongoDB document JSON is invalid: ' . $e->getMessage());
    }
    if (!is_array($document) || array_is_list($document)) {
      throw new \Exception('MongoDB replacement document must be a JSON object.');
    }
    return $document;
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
    if ($bytes < 1024) {
      return $bytes . 'B';
    }
    $units = ['KB', 'MB', 'GB', 'TB'];
    $value = $bytes / 1024;
    foreach ($units as $unit) {
      if ($value < 1024 || $unit === 'TB') {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') . $unit;
      }
      $value /= 1024;
    }
    return $bytes . 'B';
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
    if (preg_match('/^[a-fA-F0-9]{24}$/', $id) && class_exists('\MongoDB\BSON\ObjectId')) {
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
