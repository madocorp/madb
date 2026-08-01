#!/usr/bin/env php
<?php

require_once __DIR__ . '/../Engine/EngineConnectionInterface.php';
require_once __DIR__ . '/../Engine/EngineDefinitionInterface.php';
require_once __DIR__ . '/../Engine/EngineLanguageInterface.php';
require_once __DIR__ . '/../Connection/Connection.php';
require_once __DIR__ . '/../App/Format.php';
require_once __DIR__ . '/../Engine/TextLanguage.php';
require_once dirname(__DIR__, 2) . '/SPTK/Tokenizer.php';
require_once __DIR__ . '/../Tokenizer/MongoFieldString.php';
require_once __DIR__ . '/../Tokenizer/MongoOperationString.php';
require_once __DIR__ . '/../Tokenizer/MongoString.php';
require_once __DIR__ . '/../Tokenizer/MongoBlockComment.php';
require_once __DIR__ . '/../Tokenizer/MongoCommand.php';
require_once __DIR__ . '/../Engine/MongoDB/MongoLanguage.php';
require_once __DIR__ . '/../Engine/MongoDB/Connection.php';
require_once __DIR__ . '/../Engine/MongoDB/EngineDefinition.php';
require_once __DIR__ . '/../Table/MongoIndexController.php';

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
$cursorTable = new \ReflectionMethod($connection, 'cursorDocumentsShouldUseTable');
$cursorTable->setAccessible(true);
$commandStatus = new \ReflectionMethod($connection, 'commandDocumentsStatus');
$commandStatus->setAccessible(true);
$renameCommand = new \ReflectionMethod($connection, 'renameCollectionCommand');
$renameCommand->setAccessible(true);
$collectionRenameInfo = new \ReflectionMethod($connection, 'collectionRenameInfo');
$collectionRenameInfo->setAccessible(true);

$find = $parse->invoke($connection, '{"find":"users","filter":{"active":true},"limit":25}');
$assertSame($find['database'], 'fallback', 'command parser uses connection database');
$assertSame($find['commandName'], 'find', 'find command name parses');
$assertSame($find['mode'], 'read', 'find command is read');
$assertSame(get_object_vars($find['command']['filter']), ['active' => true], 'find filter parses');
$assertSame($find['command']['limit'], 25, 'find limit parses');

$bareKeysFind = $parse->invoke($connection, '{find: "users", filter: {active: true, age: {$gte: 18}}, limit: 25}');
$assertSame($bareKeysFind['commandName'], 'find', 'find command accepts bare keys');
$assertSame(get_object_vars($bareKeysFind['command']['filter']->age), ['$gte' => 18], 'find command accepts bare operator keys');

$commentedFind = $parse->invoke($connection, "{\n  // collection\n  find: \"users\",\n  filter: {url: \"https://example.test/a//b\", active: true},\n  /* capped */\n  limit: 25\n}");
$assertSame($commentedFind['commandName'], 'find', 'find command accepts comments before parsing');
$assertSame($commentedFind['command']['filter']->url, 'https://example.test/a//b', 'comment stripping preserves comment markers inside strings');

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
$assertSame($cursorTable->invoke($connection, $find, [(object)['name' => 'Ada']]), true, 'find cursor results render as a table');
$assertSame($cursorTable->invoke($connection, $aggregate, [(object)['name' => 'Ada']]), true, 'read aggregate cursor results render as a table');
$assertSame($cursorTable->invoke($connection, $aggregateOut, [(object)['ok' => 1]]), false, 'write aggregate command reply renders as JSON status');
$assertSame($cursorTable->invoke($connection, $drop, [(object)['ok' => 1]]), false, 'single write command reply renders as JSON status');
$assertSame($cursorTable->invoke($connection, $drop, [(object)['name' => 'a'], (object)['name' => 'b']]), true, 'multi-document non-find cursor results still render as a table');

$deleteStatus = $commandStatus->invoke($connection, [(object)['n' => 2, 'ok' => 1]]);
$assertSame($deleteStatus['affectedRows'], 2, 'MongoDB command status derives affected rows from n');
$assertSame($deleteStatus['message'], "{\n  \"n\": 2,\n  \"ok\": 1\n}", 'MongoDB command status keeps the reply document as JSON');

$assertThrows(
  fn() => $parse->invoke($connection, '{"filter":{"active":true},"find":"users"}'),
  'command name must be first key'
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
$assertSame(\MADB\Engine\MongoDB\Connection::supportsOperation('schemaRename'), true, 'MongoDB database rename is supported from database menu');
$mongoPrimaryItems = array_map(fn($item) => $item['text'] ?? '', \MADB\Engine\MongoDB\EngineDefinition::primaryMenuItems());
$assertSame(in_array('Rename', $mongoPrimaryItems, true), true, 'MongoDB database menu includes rename');
$rowEditorDefinition = $connection->rowEditorDefinition('fallback', 'users');
$assertSame($rowEditorDefinition['columns'][0]['COLUMN_NAME'] ?? '', '_id', 'MongoDB row delete metadata uses _id');
$assertSame($rowEditorDefinition['columns'][0]['COLUMN_KEY'] ?? '', 'PRI', 'MongoDB row delete metadata marks _id as primary key');

$templates = $language->templates();
$assertSame(in_array('FIND by _id', $templates, true), true, 'MongoDB templates include common find command');
$assertSame(in_array('PART update $set', $templates, true), true, 'MongoDB templates include common update part');
$filledFind = $language->fillTemplate($language->template('FIND filter'), 'app', 'users');
$assertSame(str_contains($filledFind, '"find": "users"'), true, 'MongoDB command template fills collection');
$assertSame(str_contains($filledFind, '[TABLE]'), false, 'MongoDB command template replaces command document placeholders');
$assertSame($language->template('PART filter $in'), '"field": {"$in": ["value1", "value2"]}', 'MongoDB part template is available');

$split = $language->split("{\"dropIndexes\":\"users\",\"index\":\"email_1\"}\n{\"createIndexes\":\"users\",\"indexes\":[{\"name\":\"email_1\",\"key\":{\"email\":1}}]}");
$assertSame(count($split), 2, 'MongoDB splitter separates adjacent command documents');
$assertSame($split[0]['sql'], '{"dropIndexes":"users","index":"email_1"}', 'MongoDB splitter keeps first command text');
$assertSame($split[1]['sql'], '{"createIndexes":"users","indexes":[{"name":"email_1","key":{"email":1}}]}', 'MongoDB splitter keeps second command text');
$commentedSplit = $language->split("{\"dropIndexes\":\"users\",\"index\":\"email_1\"}\n// next\n{\"createIndexes\":\"users\",\"indexes\":[{\"name\":\"email_1\",\"key\":{\"email\":1}}]}");
$assertSame(count($commentedSplit), 2, 'MongoDB splitter ignores comments between command documents');
$assertSame(count($language->split('[not a command document]')), 1, 'MongoDB splitter leaves unsupported text as one statement');

$indexSpec = [
  'name' => 'email_1',
  'key' => ['email' => 1],
  'unique' => true
];
$assertSame(\MADB\Table\MongoIndexController::keySummary($indexSpec), 'email:1', 'MongoDB index key summary renders field direction');
$assertSame(\MADB\Table\MongoIndexController::optionSummary($indexSpec), 'unique', 'MongoDB index option summary renders unique');
$assertSame(
  \MADB\Table\MongoIndexController::parseFields('email:1, createdAt:-1, title:text, loc:2dsphere'),
  ['email' => 1, 'createdAt' => -1, 'title' => 'text', 'loc' => '2dsphere'],
  'MongoDB index builder parses common field directions'
);
$assertSame(
  \MADB\Table\MongoIndexController::createCommand('users', $indexSpec),
  "{\n" .
  "  \"createIndexes\": \"users\",\n" .
  "  \"indexes\": [\n" .
  "    {\n" .
  "      \"name\": \"email_1\",\n" .
  "      \"key\": {\n" .
  "        \"email\": 1\n" .
  "      },\n" .
  "      \"unique\": true\n" .
  "    }\n" .
  "  ]\n" .
  "}",
  'MongoDB index create command is generated'
);
$assertSame(
  \MADB\Table\MongoIndexController::dropCommand('users', 'email_1'),
  "{\n" .
  "  \"dropIndexes\": \"users\",\n" .
  "  \"index\": \"email_1\"\n" .
  "}",
  'MongoDB index drop command is generated'
);
$assertSame(
  $renameCommand->invoke($connection, 'source', 'target', 'users'),
  [
    'renameCollection' => 'source.users',
    'to' => 'target.users',
    'dropTarget' => false
  ],
  'MongoDB database rename uses admin renameCollection command documents'
);
$assertSame(
  $collectionRenameInfo->invoke($connection, 'source', (object)['name' => 'active_view', 'type' => 'view'])['unsupported'] ?? '',
  'view',
  'MongoDB database rename preflight rejects views'
);
$assertSame(
  $collectionRenameInfo->invoke($connection, 'source', (object)['name' => 'events', 'type' => 'collection', 'options' => (object)['timeseries' => (object)[]]])['unsupported'] ?? '',
  'time-series collection',
  'MongoDB database rename preflight rejects time-series collections'
);

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
$highlightTokens = \SPTK\Tokenizer::start(['{"find":"users","filter":{"name":"Ada"}}'], '\MADB\Tokenizer\MongoCommand')[0]['tokens'];
$highlighted = [];
foreach ($highlightTokens as $token) {
  if (($token['style'] ?? '') !== '') {
    $highlighted[] = [$token['style'], $token['value']];
  }
}
$assertSame($highlighted[1], ['mongo-field', '"'], 'MongoDB tokenizer starts quoted field names as fields');
$assertSame($highlighted[2], ['mongo-field', 'find'], 'MongoDB tokenizer highlights quoted field name text as field');
$assertSame($highlighted[5], ['mongo-string', '"'], 'MongoDB tokenizer starts quoted values as strings');
$assertSame($highlighted[6], ['mongo-string', 'users'], 'MongoDB tokenizer highlights quoted value text as string');
$bareFieldTokens = \SPTK\Tokenizer::start(['{find:"users",filter:{active:true},extra:noop}'], '\MADB\Tokenizer\MongoCommand')[0]['tokens'];
$bareHighlighted = [];
foreach ($bareFieldTokens as $token) {
  if (($token['style'] ?? '') !== '') {
    $bareHighlighted[] = [$token['style'], $token['value']];
  }
}
$assertSame(in_array(['mongo-field', 'find'], $bareHighlighted, true), true, 'MongoDB tokenizer highlights bare object keys as fields');
$assertSame(in_array(['mongo-field', 'filter'], $bareHighlighted, true), true, 'MongoDB tokenizer highlights nested bare object keys as fields');
$assertSame(in_array(['mongo-field', 'active'], $bareHighlighted, true), true, 'MongoDB tokenizer highlights inner bare object keys as fields');
$assertSame(in_array(['mongo-identifier', 'noop'], $bareHighlighted, true), true, 'MongoDB tokenizer keeps non-key bare words as their normal token type');
$operationTokens = \SPTK\Tokenizer::start(['{field: {$regex: "text", "$options": "i"}}'], '\MADB\Tokenizer\MongoCommand')[0]['tokens'];
$operationHighlighted = [];
foreach ($operationTokens as $token) {
  if (($token['style'] ?? '') !== '') {
    $operationHighlighted[] = [$token['style'], $token['value']];
  }
}
$assertSame(in_array(['mongo-field', 'field'], $operationHighlighted, true), true, 'MongoDB tokenizer keeps ordinary field names as fields beside operations');
$assertSame(in_array(['mongo-operation', '$regex'], $operationHighlighted, true), true, 'MongoDB tokenizer highlights bare $ operations separately');
$assertSame(in_array(['mongo-operation', '$options'], $operationHighlighted, true), true, 'MongoDB tokenizer highlights quoted $ operation text separately');
$assertSame(in_array(['mongo-keyword', 'true'], $operationHighlighted, true), false, 'MongoDB tokenizer does not classify $ operations as keywords');
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
$assertSame(
  $language->format("{\n  // collection\n  find: \"users\",\n  filter: {active: true},\n  limit: 25\n}"),
  "{\n" .
  "  \"find\": \"users\",\n" .
  "  \"filter\": {\n" .
  "    \"active\": true\n" .
  "  },\n" .
  "  \"limit\": 25\n" .
  "}",
  'MongoDB formatter removes comments before formatting command JSON'
);
$assertSame(
  $language->format('{"dropIndexes":"users","index":"email_1"}{"createIndexes":"users","indexes":[{"name":"email_1","key":{"email":1}}]}'),
  "{\n" .
  "  \"dropIndexes\": \"users\",\n" .
  "  \"index\": \"email_1\"\n" .
  "}\n\n" .
  "{\n" .
  "  \"createIndexes\": \"users\",\n" .
  "  \"indexes\": [\n" .
  "    {\n" .
  "      \"name\": \"email_1\",\n" .
  "      \"key\": {\n" .
  "        \"email\": 1\n" .
  "      }\n" .
  "    }\n" .
  "  ]\n" .
  "}",
  'MongoDB formatter formats adjacent command documents'
);
$assertSame($language->format('[not a command document]'), '[not a command document]', 'MongoDB formatter keeps unsupported query text unchanged');
$invalidCommand = "{\n  \"find\": \"zzz\n  \"filter\": {}\n}";
$assertSame($language->format($invalidCommand), $invalidCommand, 'MongoDB formatter keeps invalid command JSON unchanged');
$assertSame($language->lastFormatError() !== false, true, 'MongoDB formatter exposes invalid command JSON error');

if ($failures > 0) {
  echo "\n{$failures} MongoDB command case(s) failed.\n";
  exit(1);
}

echo "\nMongoDB command cases passed.\n";
