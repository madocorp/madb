#!/usr/bin/env php
<?php

require_once __DIR__ . '/../Engine/EngineConnectionInterface.php';
require_once __DIR__ . '/../Engine/EngineLanguageInterface.php';
require_once __DIR__ . '/../Connection/Connection.php';
require_once __DIR__ . '/../App/Format.php';
require_once __DIR__ . '/../Engine/TextLanguage.php';
require_once __DIR__ . '/../Engine/MongoDB/MongoLanguage.php';
require_once __DIR__ . '/../Engine/MongoDB/Connection.php';

$failures = 0;

$assertSame = function($actual, $expected, $message) use (&$failures) {
  if ($actual === $expected) {
    echo "OK  {$message}\n";
    return;
  }
  $failures++;
  echo "FAIL {$message}\n";
  echo "Expected:\n";
  var_export($expected);
  echo "\nActual:\n";
  var_export($actual);
  echo "\n";
};

$assertThrows = function(callable $callback, string $message) use (&$failures) {
  try {
    $callback();
  } catch (\Exception $e) {
    echo "OK  {$message}\n";
    return;
  }
  $failures++;
  echo "FAIL {$message}\n";
  echo "Expected exception, got none.\n";
};

$connection = new \MADB\Engine\MongoDB\Connection([
  'name' => 'test',
  'database' => 'fallback'
]);
$language = new \MADB\Engine\MongoDB\MongoLanguage();
$parse = new \ReflectionMethod($connection, 'parseFindQuery');
$parse->setAccessible(true);
$table = new \ReflectionMethod($connection, 'documentsToTable');
$table->setAccessible(true);
$writeResult = new \ReflectionMethod($connection, 'writeResultFile');
$writeResult->setAccessible(true);
$replacement = new \ReflectionMethod($connection, 'replacementDocument');
$replacement->setAccessible(true);
$sameId = new \ReflectionMethod($connection, 'sameId');
$sameId->setAccessible(true);

$find = $parse->invoke($connection, '{"find":"users","filter":{"active":true},"limit":25}');
$assertSame($find['database'], 'fallback', 'command parser uses connection database');
$assertSame($find['commandName'], 'find', 'find command name parses');
$assertSame($find['mode'], 'read', 'find command is read');
$assertSame(get_object_vars($find['command']['filter']), ['active' => true], 'find filter parses');
$assertSame($find['command']['limit'], 25, 'find limit parses');

$bareKeysFind = $parse->invoke($connection, '{find: "users", filter: {active: true, age: {$gte: 18}}, limit: 25}');
$assertSame($bareKeysFind['commandName'], 'find', 'find command accepts bare keys');
$assertSame(get_object_vars($bareKeysFind['command']['filter']->age), ['$gte' => 18], 'find command accepts bare operator keys');

$emptyFind = $parse->invoke($connection, '{"find":"users","filter":{},"limit":25}');
$assertSame($emptyFind['command']['filter'] instanceof \stdClass, true, 'empty filter remains a BSON document');

$aggregate = $parse->invoke(
  $connection,
  '{"aggregate":"orders","pipeline":[{"$match":{"active":true}}],"cursor":{}}'
);
$assertSame($aggregate['mode'], 'read', 'plain aggregate is read');

$aggregateOut = $parse->invoke(
  $connection,
  '{"aggregate":"orders","pipeline":[{"$match":{}},{"$out":"orders_archive"}],"cursor":{}}'
);
$assertSame($aggregateOut['mode'], 'readWrite', 'aggregate with $out is read/write');

$drop = $parse->invoke($connection, '{"drop":"users"}');
$assertSame($drop['mode'], 'write', 'drop command is write');

$findAndModify = $parse->invoke($connection, '{"findAndModify":"users","query":{"active":false},"update":{"$set":{"active":true}}}');
$assertSame($findAndModify['mode'], 'readWrite', 'findAndModify command is read/write');

$unknown = $parse->invoke($connection, '{"customCommand":1}');
$assertSame($unknown['mode'], 'generic', 'unknown command uses generic execution');

$assertThrows(
  fn() => $parse->invoke($connection, '{"filter":{"active":true},"find":"users"}'),
  'command name must be first key'
);

$assertThrows(
  fn() => $parse->invoke($connection, 'db.getSiblingDB("app").getCollection("users").find({});'),
  'shell query is not executable'
);

$documents = [
  (object)[
    '_id' => 'a1',
    'name' => 'Ada',
    'enabled' => true,
    'nested' => ['role' => 'admin']
  ],
  (object)[
    '_id' => 'b2',
    'f01' => 1,
    'f02' => 2,
    'f03' => 3,
    'f04' => 4,
    'f05' => 5,
    'f06' => 6,
    'f07' => 7,
    'f08' => 8,
    'f09' => 9,
    'f10' => 10,
    'f11' => 11
  ]
];
$result = $table->invoke($connection, $documents);

$assertSame($result['columns'], ['_id', '_document', 'name', 'enabled', 'nested', 'f01', 'f02', 'f03', 'f04', 'f05', 'f06', '_remnant'], 'document table columns cap field columns at 10');
$assertSame($result['rows'][0]['_id'], 'a1', 'document id is first column value');
$assertSame($result['rows'][0]['enabled'], 'true', 'boolean scalar renders as text');
$assertSame($result['rows'][0]['nested'], '{"role":"admin"}', 'nested field renders as JSON');
$assertSame($result['rows'][1]['_remnant'], 'f07, f08, f09, f10, f11', 'overflow field names render in remnant field');
$assertSame(isset($result['documents']), false, 'full document JSON is not stored with table result');

$file = tempnam(sys_get_temp_dir(), 'madb-mongo-result-');
$writeMetadata = $writeResult->invoke($connection, $result['columns'], $result['rows'], $file);
$assertSame(isset($writeMetadata['documentFile']), false, 'document sidecar file is not written');
@unlink($file);

$replacementDocument = $replacement->invoke($connection, '{"_id":{"$oid":"66c000000000000000000001"},"name":"Mira"}');
$assertSame($replacementDocument['_id'] instanceof \MongoDB\BSON\ObjectId, true, 'replacement parser preserves ObjectId from Extended JSON');
$assertSame($sameId->invoke($connection, $replacementDocument['_id'], new \MongoDB\BSON\ObjectId('66c000000000000000000001')), true, 'same ObjectIds compare equal');
$assertSame($sameId->invoke($connection, $replacementDocument['_id'], '66c000000000000000000001'), false, 'ObjectId and string id are not treated as the same _id');
$assertSame($connection->insertDocumentJson('{}', true), '{}', 'insert parser preserves empty document object');
$assertSame($connection->insertDocumentJson('{test: 1}', true), "{\n  \"test\": 1\n}", 'insert parser accepts bare document keys');
$assertSame(\MADB\Engine\MongoDB\Connection::supportsOperation('rowDelete'), true, 'MongoDB document deletion is supported from results');
$rowEditorDefinition = $connection->rowEditorDefinition('fallback', 'users');
$assertSame($rowEditorDefinition['columns'][0]['COLUMN_NAME'] ?? '', '_id', 'MongoDB row delete metadata uses _id');
$assertSame($rowEditorDefinition['columns'][0]['COLUMN_KEY'] ?? '', 'PRI', 'MongoDB row delete metadata marks _id as primary key');

$templates = $language->templates();
$assertSame(in_array('FIND by _id', $templates, true), true, 'MongoDB templates include common find command');
$assertSame(in_array('PART update $set', $templates, true), true, 'MongoDB templates include common update part');
$filledFind = $language->fillTemplate($language->template('FIND filter'), 'app', 'users');
$assertSame(str_contains($filledFind, '"find": "users"'), true, 'MongoDB command template fills collection');
$assertSame(str_contains($filledFind, 'getSiblingDB'), false, 'MongoDB command template does not use shell syntax');
$assertSame($language->template('PART filter $in'), '"field": {"$in": ["value1", "value2"]}', 'MongoDB part template is available');

$assertSame(
  $language->format('{"find":"users","filter":{"active":true,"age":{"$gte":18}},"sort":{"createdAt":-1},"limit":25}'),
  "{\n" .
  "  \"find\": \"users\",\n" .
  "  \"filter\": {\n" .
  "    \"active\": true,\n" .
  "    \"age\": {\n" .
  "      \"\$gte\": 18\n" .
  "    }\n" .
  "  },\n" .
  "  \"sort\": {\n" .
  "    \"createdAt\": -1\n" .
  "  },\n" .
  "  \"limit\": 25\n" .
  "}",
  'MongoDB formatter formats command documents'
);
$assertSame(
  $language->format('{test: 1, nested: {$exists: true}}'),
  "{\n" .
  "  \"test\": 1,\n" .
  "  \"nested\": {\n" .
  "    \"\$exists\": true\n" .
  "  }\n" .
  "}",
  'MongoDB formatter quotes bare keys'
);
$assertSame(
  $language->format('{"field":{"$in":["value1","value2"]}}'),
  "{\n" .
  "  \"field\": {\n" .
  "    \"\$in\": [\n" .
  "      \"value1\",\n" .
  "      \"value2\"\n" .
  "    ]\n" .
  "  }\n" .
  "}",
  'MongoDB formatter formats JSON fragments'
);
$assertSame(
  $language->format('{"find":"users","filter":{},"limit":25}'),
  "{\n" .
  "  \"find\": \"users\",\n" .
  "  \"filter\": {},\n" .
  "  \"limit\": 25\n" .
  "}",
  'MongoDB formatter preserves empty command objects'
);
$assertSame($language->format('db.users.find({bad json});'), 'db.users.find({bad json});', 'MongoDB formatter keeps unsupported query text unchanged');

if ($failures > 0) {
  echo "\n{$failures} MongoDB command case(s) failed.\n";
  exit(1);
}

echo "\nMongoDB command cases passed.\n";
