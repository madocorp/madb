#!/usr/bin/env php
<?php

require_once __DIR__ . '/../Engine/EngineConnectionInterface.php';
require_once __DIR__ . '/../Engine/EngineLanguageInterface.php';
require_once __DIR__ . '/../Connection/Connection.php';
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

$assertSame(
  $parse->invoke($connection, 'db.getSiblingDB("app").getCollection("users").find({"active":true}).limit(25);'),
  [
    'operation' => 'find',
    'database' => 'app',
    'collection' => 'users',
    'filter' => ['active' => true],
    'limit' => 25
  ],
  'shell find query parses'
);
$assertSame(
  $parse->invoke(
    $connection,
    "db\n" .
    "  .getSiblingDB(\"app\")\n" .
    "  .getCollection(\"users\")\n" .
    "  .find({\"active\": true})\n" .
    "  .limit(25);"
  ),
  [
    'operation' => 'find',
    'database' => 'app',
    'collection' => 'users',
    'filter' => ['active' => true],
    'limit' => 25
  ],
  'formatted shell find query parses'
);

$assertThrows(
  fn() => $parse->invoke($connection, '{"database":"app","collection":"users","filter":{"active":true},"limit":25}'),
  'JSON find query is not executable'
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

$replaceOne = $parse->invoke(
  $connection,
  "db.getSiblingDB(\"app\").getCollection(\"users\").replaceOne(\n" .
  "  {\"_id\":{\"$" . "oid\":\"66c000000000000000000001\"}},\n" .
  "  {\"_id\":{\"$" . "oid\":\"66c000000000000000000001\"},\"name\":\"Mira\"}\n" .
  ');'
);
$assertSame($replaceOne['operation'], 'replaceOne', 'replaceOne query parses');
$assertSame($replaceOne['database'], 'app', 'replaceOne database parses');
$assertSame($replaceOne['collection'], 'users', 'replaceOne collection parses');
$assertSame($replaceOne['filter']['_id'] instanceof \MongoDB\BSON\ObjectId, true, 'replaceOne filter preserves ObjectId');
$assertSame($replaceOne['replacement']['name'], 'Mira', 'replaceOne replacement parses');

$formattedReplaceOne = $parse->invoke(
  $connection,
  "db\n" .
  "  .getSiblingDB(\"app\")\n" .
  "  .getCollection(\"users\")\n" .
  "  .replaceOne(\n" .
  "    {\"_id\": {\"\$" . "oid\": \"66c000000000000000000001\"}},\n" .
  "    {\"_id\": {\"\$" . "oid\": \"66c000000000000000000001\"}, \"name\": \"Mira\"}\n" .
  "  );"
);
$assertSame($formattedReplaceOne['operation'], 'replaceOne', 'formatted replaceOne query parses');
$assertSame($formattedReplaceOne['replacement']['name'], 'Mira', 'formatted replaceOne replacement parses');

$findJson = $connection->convertShellQueryToJsonCommand('db.getSiblingDB("app").getCollection("users").find({"active":true}).limit(25);');
$findCommand = json_decode($findJson, true);
$assertSame($findCommand['database'], 'app', 'find JSON command includes database');
$assertSame($findCommand['collection'], 'users', 'find JSON command includes collection');
$assertSame($findCommand['find']['filter'], ['active' => true], 'find JSON command includes filter');
$assertSame($findCommand['find']['limit'], 25, 'find JSON command includes limit');

$replaceJson = $connection->convertShellQueryToJsonCommand(
  "db.getSiblingDB(\"app\").getCollection(\"users\").replaceOne(" .
  "{\"_id\":{\"$" . "oid\":\"66c000000000000000000001\"}}," .
  "{\"_id\":{\"$" . "oid\":\"66c000000000000000000001\"},\"name\":\"Mira\"}" .
  ');'
);
$replaceCommand = json_decode($replaceJson, true);
$assertSame($replaceCommand['replaceOne']['replacement']['name'], 'Mira', 'replaceOne JSON command includes replacement');

$findPhp = $connection->convertShellQueryToPhpDriver('db.getSiblingDB("app").getCollection("users").find({"active":true}).limit(25);');
$assertSame(str_contains($findPhp, '$cursor = $manager->executeQuery'), true, 'find PHP driver snippet executes query');
$assertSame(str_contains($findPhp, "'limit' => 25"), true, 'find PHP driver snippet includes limit');

$replacePhp = $connection->convertShellQueryToPhpDriver(
  "db.getSiblingDB(\"app\").getCollection(\"users\").replaceOne(" .
  "{\"_id\":{\"$" . "oid\":\"66c000000000000000000001\"}}," .
  "{\"_id\":{\"$" . "oid\":\"66c000000000000000000001\"},\"name\":\"Mira\"}" .
  ');'
);
$assertSame(str_contains($replacePhp, '$bulk = new \MongoDB\Driver\BulkWrite();'), true, 'replaceOne PHP driver snippet uses BulkWrite');
$assertSame(str_contains($replacePhp, '$manager->executeBulkWrite'), true, 'replaceOne PHP driver snippet executes bulk write');

$templates = $language->templates();
$assertSame(in_array('FIND by _id', $templates, true), true, 'MongoDB templates include common find command');
$assertSame(in_array('PART update $set', $templates, true), true, 'MongoDB templates include common update part');
$filledFind = $language->fillTemplate($language->template('FIND filter'), 'app', 'users');
$assertSame(str_contains($filledFind, 'db.getSiblingDB("app").getCollection("users").find'), true, 'MongoDB command template fills database and collection');
$assertSame($language->template('PART filter $in'), '"field": {"$in": ["value1", "value2"]}', 'MongoDB part template is available');

$assertSame(
  $language->format('db.getSiblingDB("app").getCollection("users").find({"active":true,"age":{"$gte":18}}).sort({"createdAt":-1}).limit(25);'),
  "db\n" .
  "  .getSiblingDB(\"app\")\n" .
  "  .getCollection(\"users\")\n" .
  "  .find({\"active\": true, \"age\": {\"\$gte\": 18}})\n" .
  "  .sort({\"createdAt\": -1})\n" .
  "  .limit(25);",
  'MongoDB formatter formats find query chains'
);
$assertSame(
  $language->format('db.getSiblingDB("app").getCollection("users").find({"first":"aaaaaaaaaaaaaaaaaaaa","second":"bbbbbbbbbbbbbbbbbbbb","third":"cccccccccccccccccccc","fourth":"dddddddddddddddddddd"}).limit(25);'),
  "db\n" .
  "  .getSiblingDB(\"app\")\n" .
  "  .getCollection(\"users\")\n" .
  "  .find(\n" .
  "    {\n" .
  "        \"first\": \"aaaaaaaaaaaaaaaaaaaa\",\n" .
  "        \"second\": \"bbbbbbbbbbbbbbbbbbbb\",\n" .
  "        \"third\": \"cccccccccccccccccccc\",\n" .
  "        \"fourth\": \"dddddddddddddddddddd\"\n" .
  "    }\n" .
  "  )\n" .
  "  .limit(25);",
  'MongoDB formatter expands long filter objects'
);
$assertSame(
  $language->format(
    "db.getSiblingDB(\"app\").getCollection(\"users\").replaceOne(" .
    "{\"_id\":{\"$" . "oid\":\"66c000000000000000000001\"}}," .
    "{\"_id\":{\"$" . "oid\":\"66c000000000000000000001\"},\"name\":\"Mira\"}" .
    ');'
  ),
  "db\n" .
  "  .getSiblingDB(\"app\")\n" .
  "  .getCollection(\"users\")\n" .
  "  .replaceOne(\n" .
  "    {\"_id\": {\"\$oid\": \"66c000000000000000000001\"}},\n" .
  "    {\"_id\": {\"\$oid\": \"66c000000000000000000001\"}, \"name\": \"Mira\"}\n" .
  "  );",
  'MongoDB formatter formats replaceOne queries'
);
$assertSame(
  $language->format('{"field":{"$in":["value1","value2"]}}'),
  "{\n" .
  "    \"field\": {\n" .
  "        \"\$in\": [\n" .
  "            \"value1\",\n" .
  "            \"value2\"\n" .
  "        ]\n" .
  "    }\n" .
  "}",
  'MongoDB formatter formats JSON fragments'
);
$assertSame($language->format('db.users.find({bad json});'), 'db.users.find({bad json});', 'MongoDB formatter keeps unsupported query text unchanged');

if ($failures > 0) {
  echo "\n{$failures} MongoDB find case(s) failed.\n";
  exit(1);
}

echo "\nMongoDB find cases passed.\n";
