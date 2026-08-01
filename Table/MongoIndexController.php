<?php

namespace MADB\Table;

/** Lists MongoDB collection indexes and generates reviewed create/drop index commands. */
class MongoIndexController {

  private static array $state = [
    'connection' => false,
    'schema' => false,
    'table' => false,
    'indexes' => [],
    'mode' => 'edit',
    'editingName' => false
  ];

  private const DIRECTION_OPTIONS = ['1', '-1', 'text', 'hashed', '2dsphere', '2d'];

  public static function openIndexList(): void {
    $context = self::context();
    if ($context === false) {
      return;
    }
    if (!\MADB\Connection\MenuController::requireOperation('collectionIndexes', 'Listing MongoDB indexes', $context['connection'])) {
      return;
    }
    self::$state = array_merge(self::$state, [
      'connection' => $context['connection'],
      'schema' => $context['schema'],
      'table' => $context['table'],
      'indexes' => [],
      'mode' => 'edit',
      'editingName' => false
    ]);
    $panel = self::panel('mongodb-index-list');
    if ($panel === false) {
      return;
    }
    self::setTitle($panel, 'MongoDB indexes: ' . $context['schema'] . '.' . $context['table']);
    self::setListPlaceholder('Loading...');
    $panel->show();
    $panel->activateInput('mongodb-index-list-items');
    \SPTK\Element::refresh();
    self::loadIndexes();
  }

  public static function loadIndexes(): void {
    if (empty(self::$state['connection']) || empty(self::$state['schema']) || empty(self::$state['table'])) {
      return;
    }
    \MADB\Job\JobHandler::startJob([
      'connection' => self::$state['connection'],
      'command' => 'collectionIndexes',
      'arguments' => [self::$state['schema'], self::$state['table']],
      'callback' => ['\MADB\Table\MongoIndexController', 'setIndexes'],
      'schema' => self::$state['schema'],
      'table' => self::$state['table'],
      'cache' => self::cacheKey(self::$state['schema'], self::$state['table'])
    ]);
  }

  public static function setIndexes($response): void {
    if (($response['status'] ?? '') !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not list MongoDB indexes', (string)($response['result'] ?? 'Unknown error'));
      return;
    }
    self::$state['connection'] = $response['connection'] ?? self::$state['connection'];
    self::$state['schema'] = $response['schema'] ?? self::$state['schema'];
    self::$state['table'] = $response['table'] ?? self::$state['table'];
    self::$state['indexes'] = array_values((array)($response['result'] ?? []));
    self::renderIndexList();
    \SPTK\Element::refresh();
  }

  public static function openSelectedIndexEditor($panel = null): void {
    $list = self::listElement();
    if ($list === false || !method_exists($list, 'getActive')) {
      return;
    }
    self::openIndexEditor($list->getActive());
  }

  public static function openIndexEditor($item): void {
    if ($item === false || $item === null || !method_exists($item, 'getValue')) {
      return;
    }
    $name = (string)$item->getValue();
    $index = self::findIndex($name);
    if ($index === false) {
      return;
    }
    if ($name === '_id_') {
      \SPTK\Elements\WarningPanel::forge('Protected index', 'MongoDB _id indexes cannot be changed.');
      return;
    }
    self::$state['mode'] = 'edit';
    self::$state['editingName'] = $name;
    self::showEditor(self::specFromIndex($index), false);
  }

  public static function addIndex($panel = null): void {
    if (empty(self::$state['schema']) || empty(self::$state['table'])) {
      $context = self::context();
      if ($context === false) {
        return;
      }
      self::$state['connection'] = $context['connection'];
      self::$state['schema'] = $context['schema'];
      self::$state['table'] = $context['table'];
    }
    self::$state['mode'] = 'add';
    self::$state['editingName'] = false;
    self::showEditor([
      'name' => 'new_index',
      'key' => ['field' => 1]
    ], false);
  }

  public static function deleteIndex($panel = null): void {
    $list = self::listElement();
    if ($list === false || !method_exists($list, 'getActive')) {
      return;
    }
    $item = $list->getActive();
    if ($item === false || !method_exists($item, 'getValue')) {
      \SPTK\Elements\WarningPanel::forge('No index selected', 'Please select an index before deleting.');
      return;
    }
    $name = (string)$item->getValue();
    if ($name === '_id_') {
      \SPTK\Elements\WarningPanel::forge('Protected index', 'MongoDB _id indexes cannot be dropped.');
      return;
    }
    self::openGeneratedQuery(
      'Drop MongoDB index',
      'DROP INDEX ' . self::$state['schema'] . '.' . self::$state['table'] . '.' . $name,
      self::dropCommand(self::$state['table'], $name)
    );
  }

  public static function syncIndexBuilder($element = null): void {
    $panel = self::panel('mongodb-index-editor');
    if ($panel === false || (self::$state['editingName'] ?? false) === '_id_') {
      return;
    }
    try {
      $spec = self::specFromBuilder($panel->getValue());
      $panel->setValue(['mongodb-index-spec' => self::json($spec)]);
      \SPTK\Element::refresh();
    } catch (\Exception $e) {
    }
  }

  public static function saveIndexEditor($panel): void {
    if ((self::$state['editingName'] ?? false) === '_id_') {
      \SPTK\Elements\WarningPanel::forge('Protected index', 'MongoDB _id indexes cannot be changed.');
      return;
    }
    $values = $panel->getValue();
    try {
      $spec = self::parseSpec(self::textValue($values['mongodb-index-spec'] ?? ''));
      self::validateSpec($spec);
    } catch (\Exception $e) {
      \SPTK\Elements\WarningPanel::forge('Invalid index spec', $e->getMessage());
      return;
    }
    $mode = self::$state['mode'] ?? 'edit';
    $oldName = self::$state['editingName'] ?? false;
    if ($mode === 'edit') {
      $original = self::findIndex((string)$oldName);
      if ($original !== false && self::normalizeSpec(self::specFromIndex($original)) === self::normalizeSpec($spec)) {
        \SPTK\Elements\WarningPanel::forge('No changes', 'No MongoDB index changes were detected.');
        return;
      }
    }
    $commands = [];
    if ($mode === 'edit' && $oldName !== false) {
      $commands[] = self::dropCommand(self::$state['table'], (string)$oldName);
    }
    $commands[] = self::createCommand(self::$state['table'], $spec);
    $panel->hide();
    self::openGeneratedQuery(
      $mode === 'add' ? 'Create MongoDB index' : 'Modify MongoDB index',
      ($mode === 'add' ? 'CREATE INDEX ' : 'ALTER INDEX ') . self::$state['schema'] . '.' . self::$state['table'] . '.' . ($spec['name'] ?? ''),
      implode("\n", $commands)
    );
    \SPTK\Element::refresh();
  }

  public static function closeIndexList($panel): void {
    $panel->hide();
    \MADB\Main\ScreenController::restoreFocusAfterPanelClose();
    \SPTK\Element::refresh();
  }

  public static function closeIndexEditor($panel): void {
    $panel->hide();
    $list = self::panel('mongodb-index-list');
    if ($list !== false && method_exists($list, 'activateInput')) {
      $list->activateInput('mongodb-index-list-items');
    }
    \SPTK\Element::refresh();
  }

  public static function closeIndexPanels(): void {
    foreach (['mongodb-index-editor', 'mongodb-index-list'] as $name) {
      $panel = self::panel($name);
      if ($panel !== false) {
        $panel->hide();
      }
    }
  }

  public static function refreshOpenIndexList(string $schema, string $table): void {
    $panel = self::panel('mongodb-index-list');
    if ($panel === false || !$panel->isDisplayed()) {
      return;
    }
    if ((string)self::$state['schema'] !== $schema || (string)self::$state['table'] !== $table) {
      return;
    }
    self::setListPlaceholder('Loading...');
    self::loadIndexes();
  }

  public static function cacheKey(string $schema, string $table): string {
    return 'CollectionIndexes:' . $schema . ':' . $table;
  }

  public static function keySummary(array $spec): string {
    $parts = [];
    foreach ((array)($spec['key'] ?? []) as $field => $direction) {
      $parts[] = (string)$field . ':' . self::shortValue($direction);
    }
    return implode(', ', $parts);
  }

  public static function optionSummary(array $spec): string {
    $options = [];
    foreach (['unique', 'sparse', 'hidden'] as $option) {
      if (!empty($spec[$option])) {
        $options[] = $option;
      }
    }
    if (array_key_exists('expireAfterSeconds', $spec)) {
      $options[] = 'ttl=' . (string)$spec['expireAfterSeconds'];
    }
    if (!empty($spec['partialFilterExpression'])) {
      $options[] = 'partial';
    }
    if (!empty($spec['collation'])) {
      $options[] = 'collation';
    }
    return implode(', ', $options);
  }

  public static function createCommand(string $collection, array $spec): string {
    return self::json([
      'createIndexes' => $collection,
      'indexes' => [self::commandSpec($spec)]
    ]);
  }

  public static function dropCommand(string $collection, string $name): string {
    return self::json([
      'dropIndexes' => $collection,
      'index' => $name
    ]);
  }

  public static function parseFields(string $fields): array {
    $fields = trim($fields);
    if ($fields === '') {
      throw new \Exception('Please enter at least one indexed field.');
    }
    $key = [];
    foreach (explode(',', $fields) as $part) {
      $part = trim($part);
      if ($part === '') {
        continue;
      }
      if (str_contains($part, ':')) {
        [$field, $direction] = array_map('trim', explode(':', $part, 2));
      } else {
        $pieces = preg_split('/\s+/', $part, 2);
        $field = trim($pieces[0] ?? '');
        $direction = trim($pieces[1] ?? '1');
      }
      if ($field === '') {
        throw new \Exception('Index field names cannot be empty.');
      }
      $key[$field] = self::parseDirection($direction);
    }
    if (empty($key)) {
      throw new \Exception('Please enter at least one indexed field.');
    }
    return $key;
  }

  public static function parseSpec(string $json): array {
    $json = trim(\MADB\Engine\MongoDB\MongoLanguage::quoteBareKeys($json));
    if ($json === '') {
      throw new \Exception('Index spec JSON is empty.');
    }
    $decoded = json_decode($json);
    if (json_last_error() !== JSON_ERROR_NONE || !is_object($decoded)) {
      throw new \Exception('Index spec must be a JSON object.');
    }
    return self::objectToArray($decoded);
  }

  public static function validateSpec(array $spec): void {
    if (trim((string)($spec['name'] ?? '')) === '') {
      throw new \Exception('Index spec must contain a name.');
    }
    if (empty($spec['key']) || !is_array($spec['key']) || array_is_list($spec['key'])) {
      throw new \Exception('Index spec must contain a key object.');
    }
  }

  private static function context() {
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    $connection = $connectionList->current;
    $schema = \MADB\Table\MenuController::getCurrentSchema();
    $table = \MADB\Table\MenuController::getCurrentTable();
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return false;
    }
    if (($connection['engine'] ?? '') !== 'MongoDB') {
      \SPTK\Elements\WarningPanel::forge('Unsupported operation', 'MongoDB indexes are only available for MongoDB connections.');
      return false;
    }
    if ($schema === false || $schema === '') {
      \SPTK\Elements\WarningPanel::forge('No database selected!', 'Please select a database before preforming this operation.');
      return false;
    }
    if ($table === false || $table === '') {
      \SPTK\Elements\WarningPanel::forge('No collection selected!', 'Please select a collection before preforming this operation.');
      return false;
    }
    return [
      'connection' => $connection,
      'schema' => $schema,
      'table' => $table
    ];
  }

  private static function renderIndexList(): void {
    $list = self::listElement();
    if ($list === false) {
      return;
    }
    $list->clear();
    if (empty(self::$state['indexes'])) {
      $list->addItem(['text' => 'No indexes defined yet.']);
      return;
    }
    foreach (self::$state['indexes'] as $index) {
      $spec = self::specFromIndex($index);
      $name = (string)($spec['name'] ?? ($index['name'] ?? ''));
      $list->addItem([
        'value' => $name,
        'columns' => [$name, self::keySummary($spec), self::optionSummary($spec)]
      ]);
    }
  }

  private static function showEditor(array $spec, bool $readOnly): void {
    $panel = self::panel('mongodb-index-editor');
    if ($panel === false) {
      return;
    }
    $title = ($readOnly ? 'MongoDB index details: ' : 'MongoDB index: ') . ((string)($spec['name'] ?? 'new'));
    self::setTitle($panel, $title);
    self::setDirectionOptions($panel);
    $panel->setValue(self::builderValues($spec) + [
      'mongodb-index-spec' => self::json($spec)
    ]);
    $panel->show();
    $panel->activateInput($readOnly ? 'mongodb-index-spec' : 'mongodb-index-name');
    \SPTK\Element::refresh();
  }

  private static function builderValues(array $spec): array {
    return self::fieldBuilderValues((array)($spec['key'] ?? [])) + [
      'mongodb-index-name' => (string)($spec['name'] ?? ''),
      'mongodb-index-unique' => (bool)($spec['unique'] ?? false),
      'mongodb-index-sparse' => (bool)($spec['sparse'] ?? false),
      'mongodb-index-hidden' => (bool)($spec['hidden'] ?? false),
      'mongodb-index-ttl' => array_key_exists('expireAfterSeconds', $spec) ? (string)$spec['expireAfterSeconds'] : '',
      'mongodb-index-partial' => empty($spec['partialFilterExpression']) ? '' : self::json($spec['partialFilterExpression']),
      'mongodb-index-collation' => empty($spec['collation']) ? '' : self::json($spec['collation'])
    ];
  }

  private static function specFromBuilder(array $values): array {
    $name = trim(self::textValue($values['mongodb-index-name'] ?? ''));
    if ($name === '') {
      throw new \Exception('Please enter an index name.');
    }
    $spec = [
      'name' => $name,
      'key' => self::fieldsFromBuilder($values)
    ];
    foreach (['unique', 'sparse', 'hidden'] as $option) {
      if (!empty($values['mongodb-index-' . $option])) {
        $spec[$option] = true;
      }
    }
    $ttl = trim(self::textValue($values['mongodb-index-ttl'] ?? ''));
    if ($ttl !== '') {
      if (!ctype_digit($ttl)) {
        throw new \Exception('TTL seconds must be a non-negative integer.');
      }
      $spec['expireAfterSeconds'] = (int)$ttl;
    }
    foreach (['partial' => 'partialFilterExpression', 'collation' => 'collation'] as $field => $option) {
      $text = trim(self::textValue($values['mongodb-index-' . $field] ?? ''));
      if ($text !== '') {
        $spec[$option] = self::parseSpec($text);
      }
    }
    return $spec;
  }

  private static function parseDirection(string $direction) {
    $direction = trim($direction, " \t\n\r\0\x0B\"");
    $lower = strtolower($direction);
    if ($direction === '1' || $lower === 'asc' || $lower === 'ascending') {
      return 1;
    }
    if ($direction === '-1' || $lower === 'desc' || $lower === 'descending') {
      return -1;
    }
    if (in_array($lower, ['text', 'hashed', '2dsphere', '2d'], true)) {
      return $lower;
    }
    throw new \Exception("Unsupported index direction '{$direction}'.");
  }

  private static function findIndex(string $name) {
    foreach (self::$state['indexes'] as $index) {
      if ((string)($index['name'] ?? ($index['spec']['name'] ?? '')) === $name) {
        return $index;
      }
    }
    return false;
  }

  private static function specFromIndex(array $index): array {
    if (isset($index['spec']) && is_array($index['spec'])) {
      return $index['spec'];
    }
    return $index;
  }

  private static function openGeneratedQuery(string $title, string $name, string $sql): void {
    \MADB\Query\GeneratedQueryController::open([
      'title' => $title,
      'name' => $name,
      'sql' => $sql,
      'connection' => self::$state['connection'],
      'schema' => self::$state['schema'],
      'table' => self::$state['table'],
      'cacheKeys' => [self::cacheKey(self::$state['schema'], self::$state['table'])],
      'refresh' => 'mongoIndexes'
    ]);
  }

  private static function setListPlaceholder(string $text): void {
    $list = self::listElement();
    if ($list === false) {
      return;
    }
    $list->clear();
    $list->addItem(['text' => $text]);
  }

  private static function listElement() {
    return \SPTK\Element::byName('mongodb-index-list-items', self::panel('mongodb-index-list'));
  }

  private static function panel(string $name) {
    return \SPTK\Element::byName($name);
  }

  private static function setTitle($panel, string $text): void {
    $title = \SPTK\Element::firstByType('PanelTitle', $panel);
    if ($title !== false) {
      $title->setText($text);
    }
  }

  private static function fieldsText(array $key): string {
    $parts = [];
    foreach ($key as $field => $direction) {
      $parts[] = (string)$field . ':' . self::shortValue($direction);
    }
    return implode(', ', $parts);
  }

  private static function fieldBuilderValues(array $key): array {
    $values = [];
    $rows = array_slice($key, 0, 3, true);
    $row = 1;
    foreach ($rows as $field => $direction) {
      $values['mongodb-index-field-' . $row] = (string)$field;
      $values['mongodb-index-direction-' . $row] = self::directionValue($direction);
      $row++;
    }
    for (; $row <= 3; $row++) {
      $values['mongodb-index-field-' . $row] = '';
      $values['mongodb-index-direction-' . $row] = '1';
    }
    return $values;
  }

  private static function fieldsFromBuilder(array $values): array {
    $key = [];
    for ($row = 1; $row <= 3; $row++) {
      $field = trim(self::textValue($values['mongodb-index-field-' . $row] ?? ''));
      if ($field === '') {
        continue;
      }
      $key[$field] = self::parseDirection(self::textValue($values['mongodb-index-direction-' . $row] ?? '1'));
    }
    if (empty($key)) {
      throw new \Exception('Please enter at least one indexed field.');
    }
    return $key;
  }

  private static function directionValue($direction): string {
    if ($direction === 1 || $direction === '1') {
      return '1';
    }
    if ($direction === -1 || $direction === '-1') {
      return '-1';
    }
    $direction = strtolower((string)$direction);
    return in_array($direction, ['text', 'hashed', '2dsphere', '2d'], true) ? $direction : '1';
  }

  private static function setDirectionOptions($panel): void {
    for ($row = 1; $row <= 3; $row++) {
      $select = \SPTK\Element::byName('mongodb-index-direction-' . $row, $panel);
      if ($select !== false && method_exists($select, 'setOptions')) {
        $select->setOptions(self::DIRECTION_OPTIONS);
      }
    }
  }

  private static function normalizeSpec(array $spec): string {
    return self::json(self::commandSpec($spec));
  }

  private static function json($value): string {
    $json = \MADB\Engine\MongoDB\MongoLanguage::prettyJson($value);
    return $json === false ? '{}' : $json;
  }

  private static function shortValue($value): string {
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    if ($value === null || is_int($value) || is_float($value) || is_string($value)) {
      return (string)$value;
    }
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json === false ? '' : $json;
  }

  private static function textValue($value): string {
    if (is_array($value)) {
      return implode("\n", $value);
    }
    return (string)$value;
  }

  private static function commandSpec(array $spec): array {
    unset($spec['v'], $spec['ns']);
    return $spec;
  }

  private static function objectToArray($value) {
    if ($value instanceof \stdClass) {
      $result = [];
      foreach (get_object_vars($value) as $key => $item) {
        $result[$key] = self::objectToArray($item);
      }
      return $result;
    }
    if (is_array($value)) {
      return array_map(fn($item) => self::objectToArray($item), $value);
    }
    return $value;
  }

}
