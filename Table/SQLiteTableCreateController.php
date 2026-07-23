<?php

namespace MADB\Table;

/** Owns the SQLite-specific table create panel and SQL generation. */
class SQLiteTableCreateController {

  private static $schema = false;
  private static $table = false;
  private static string $mode = 'create';
  private static array $columns = [];
  private static string|false $originalTable = false;
  private static array $originalColumns = [];
  private static array $indexes = [];
  private static array $originalIndexes = [];
  private static array $foreignKeys = [];
  private static array $originalForeignKeys = [];
  private static array $triggers = [];
  private static array $originalTriggers = [];
  private static int|false $editingColumn = false;
  private static int|false $addingColumn = false;
  private static string|false $editingIndex = false;
  private static string|false $addingIndex = false;
  private static int|false $editingForeignKey = false;
  private static int|false $addingForeignKey = false;
  private static int|false $editingTrigger = false;
  private static int|false $addingTrigger = false;
  private static bool $syncingColumnPanel = false;

  /** Opens the SQLite table create panel. */
  public static function openCreate(): void {
    $connection = self::currentConnection();
    self::$schema = \MADB\Table\MenuController::getCurrentSchema();
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    if (self::$schema === false || self::$schema === '') {
      \SPTK\Elements\WarningPanel::forge('No database selected!', 'Please select a database before preforming this operation.');
      return;
    }
    if (!\MADB\Connection\MenuController::requireOperation('tableCreate', 'Creating tables', $connection)) {
      return;
    }
    self::$mode = 'create';
    self::$table = false;
    self::$originalTable = false;
    self::$originalColumns = [];
    self::$originalIndexes = [];
    self::$originalForeignKeys = [];
    self::$originalTriggers = [];
    self::$columns = [];
    self::$indexes = [];
    self::$foreignKeys = [];
    self::$triggers = [];
    self::$editingColumn = false;
    self::$addingColumn = false;
    self::resetItemEditors();
    $panel = self::panel();
    if ($panel === false) {
      return;
    }
    self::setTitle('Create SQLite table in ' . self::$schema);
    $panel->setValue([
      'sqlite-table-name' => ''
    ]);
    self::setColumns();
    self::setIndexes();
    self::setForeignKeys();
    self::setTriggers();
    self::updateAddButton();
    $panel->show();
    if (method_exists($panel, 'activateInput')) {
      $panel->activateInput('sqlite-table-name');
    }
    \SPTK\Element::refresh();
  }

  /** Opens the SQLite table modify panel and loads current table metadata. */
  public static function openModify(): void {
    $connection = self::currentConnection();
    self::$schema = \MADB\Table\MenuController::getCurrentSchema();
    self::$table = \MADB\Table\MenuController::getCurrentTable();
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    if (self::$schema === false || self::$schema === '' || self::$table === false || self::$table === '') {
      \SPTK\Elements\WarningPanel::forge('No object selected!', 'Please select a table before preforming this operation.');
      return;
    }
    if (!\MADB\Connection\MenuController::requireOperation('tableModify', 'Modifying tables', $connection)) {
      return;
    }
    self::$mode = 'modify';
    self::$originalTable = false;
    self::$originalColumns = [];
    self::$originalIndexes = [];
    self::$originalForeignKeys = [];
    self::$originalTriggers = [];
    self::$columns = [];
    self::$indexes = [];
    self::$foreignKeys = [];
    self::$triggers = [];
    self::$editingColumn = false;
    self::$addingColumn = false;
    self::resetItemEditors();
    $panel = self::panel();
    if ($panel === false) {
      return;
    }
    self::setTitle('Modify SQLite table ' . self::$schema . '.' . self::$table);
    $panel->setValue([
      'sqlite-table-name' => self::$table
    ]);
    self::setColumnPlaceholder('Loading...');
    self::setPlaceholder('sqlite-table-indexes', 'Loading...');
    self::setPlaceholder('sqlite-table-foreign-keys', 'Loading...');
    self::setPlaceholder('sqlite-table-triggers', 'Loading...');
    self::updateAddButton();
    $panel->show();
    if (method_exists($panel, 'activateInput')) {
      $panel->activateInput('sqlite-table-name');
    }
    \SPTK\Element::refresh();
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'tableDefinition',
      'arguments' => [self::$schema, self::$table],
      'callback' => ['\MADB\Table\SQLiteTableCreateController', 'setModifyDefinition'],
      'schema' => self::$schema,
      'table' => self::$table,
      'cache' => 'TableDefinition:' . self::$schema . ':' . self::$table
    ]);
  }

  /** Applies loaded SQLite table metadata to the modify panel. */
  public static function setModifyDefinition($response): void {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not inspect table', $response['result']);
      return;
    }
    $definition = is_array($response['result'] ?? false) ? $response['result'] : [];
    $table = $definition['table'] ?? [];
    if (($table['type'] ?? '') === 'VIEW') {
      \SPTK\Elements\WarningPanel::forge('Unsupported operation', 'Modifying SQLite views is not supported yet.');
      return;
    }
    self::$schema = $response['schema'];
    self::$table = $response['table'];
    self::$originalTable = (string)($table['name'] ?? self::$table);
    self::$originalColumns = self::columnsFromDefinition($definition);
    self::$originalIndexes = self::indexesFromDefinition($definition);
    self::$originalForeignKeys = self::foreignKeysFromDefinition($definition);
    self::$originalTriggers = self::triggersFromDefinition($definition);
    self::$columns = self::$originalColumns;
    self::$indexes = self::$originalIndexes;
    self::$foreignKeys = self::$originalForeignKeys;
    self::$triggers = self::$originalTriggers;
    $panel = self::panel();
    if ($panel !== false) {
      self::setTitle('Modify SQLite table ' . self::$schema . '.' . self::$originalTable);
      $panel->setValue([
        'sqlite-table-name' => self::$originalTable
      ]);
    }
    self::setColumns();
    self::setIndexes();
    self::setForeignKeys();
    self::setTriggers();
    \SPTK\Element::refresh();
  }

  /** Adds a new column and opens the column editor. */
  public static function addColumn($panel = null): void {
    $index = count(self::$columns);
    self::$columns[] = [
      'name' => '',
      'type' => 'TEXT',
      'primary' => false,
      'notNull' => false,
      'autoincrement' => false,
      'default' => ''
    ];
    self::$editingColumn = $index;
    self::$addingColumn = $index;
    self::setColumns();
    self::openColumnPanel(self::$columns[$index], 'sqlite-column-name');
  }

  /** Opens the SQLite column editor for one column list item. */
  public static function openColumnEditor($item): void {
    $index = (int)$item->getValue();
    if (!isset(self::$columns[$index])) {
      return;
    }
    self::$editingColumn = $index;
    self::$addingColumn = false;
    self::openColumnPanel(self::$columns[$index], 'sqlite-column-name');
  }

  /** Synchronizes the open SQLite column editor into list state. */
  public static function syncColumnEditor($element = null): void {
    if (self::$syncingColumnPanel) {
      return;
    }
    $panel = self::columnPanel();
    if ($panel === false || self::$editingColumn === false || !isset(self::$columns[self::$editingColumn])) {
      return;
    }
    self::$columns[self::$editingColumn] = self::columnFromPanel($panel);
    self::setColumns();
    \SPTK\Element::refresh();
  }

  /** Saves the open SQLite column editor. */
  public static function saveColumnEditor($panel): void {
    if (self::$editingColumn === false || !isset(self::$columns[self::$editingColumn])) {
      $panel->hide();
      return;
    }
    $column = self::columnFromPanel($panel);
    if (trim($column['name']) === '') {
      \SPTK\Elements\WarningPanel::forge('No field name', 'Please enter a field name before saving.');
      return;
    }
    $oldName = self::$columns[self::$editingColumn]['name'] ?? '';
    self::$columns[self::$editingColumn] = $column;
    self::renameDependentColumnReferences($oldName, $column['name']);
    self::$addingColumn = false;
    self::setColumns();
    self::setIndexes();
    self::setForeignKeys();
    $panel->hide();
    \SPTK\Element::refresh();
  }

  /** Closes the SQLite column editor and removes a pending new column. */
  public static function closeColumnEditor($panel): void {
    if (self::$addingColumn !== false && isset(self::$columns[self::$addingColumn])) {
      array_splice(self::$columns, self::$addingColumn, 1);
      self::setColumns();
    }
    self::$editingColumn = false;
    self::$addingColumn = false;
    $panel->hide();
    \SPTK\Element::refresh();
  }

  /** Deletes the selected SQLite column. */
  public static function deleteColumn($panel = null): void {
    $list = self::columnList();
    $index = $list === false ? false : $list->getValue();
    if ($index === false || $index === '' || !isset(self::$columns[(int)$index])) {
      \SPTK\Elements\WarningPanel::forge('No field selected', 'Please select a field before deleting.');
      return;
    }
    $name = self::$columns[(int)$index]['name'] ?? '';
    array_splice(self::$columns, (int)$index, 1);
    self::setColumns();
    if ($name !== '') {
      self::$indexes = array_values(array_filter(self::$indexes, fn($index) => ($index['COLUMN_NAME'] ?? '') !== $name));
      self::$foreignKeys = array_values(array_filter(self::$foreignKeys, fn($foreignKey) => ($foreignKey['COLUMN_NAME'] ?? '') !== $name));
      self::setIndexes();
      self::setForeignKeys();
    }
    \SPTK\Element::refresh();
  }

  /** Updates Add/Delete button visibility for the selected SQLite editor tab. */
  public static function updateAddButton($tabs = null): void {
    if ($tabs === null || $tabs === false) {
      $tabs = self::tabs();
    }
    $contentName = $tabs === false ? false : $tabs->getCurrentTabContentName();
    $show = in_array($contentName, [
      'sqlite-table-column',
      'sqlite-table-index',
      'sqlite-table-foreign-key',
      'sqlite-table-trigger'
    ], true);
    foreach (['sqlite-table-add', 'sqlite-table-delete'] as $name) {
      $button = \SPTK\Element::byName($name, self::panel());
      if ($button !== false) {
        $show ? $button->show() : $button->hide();
      }
    }
    $panel = self::panel();
    $input = self::currentTabInputName($contentName);
    if ($panel !== false && $panel->isDisplayed() && $input !== false) {
      $panel->refreshInputList($input);
      \SPTK\Element::refresh();
    }
  }

  /** Adds an item for the active SQLite editor tab. */
  public static function add($panel = null): void {
    switch (self::currentTabName()) {
      case 'sqlite-table-column':
        self::addColumn($panel);
        return;
      case 'sqlite-table-index':
        self::addIndex($panel);
        return;
      case 'sqlite-table-foreign-key':
        self::addForeignKey($panel);
        return;
      case 'sqlite-table-trigger':
        self::addTrigger($panel);
        return;
    }
  }

  /** Deletes the selected item for the active SQLite editor tab. */
  public static function delete($panel = null): void {
    switch (self::currentTabName()) {
      case 'sqlite-table-column':
        self::deleteColumn($panel);
        return;
      case 'sqlite-table-index':
        self::deleteIndex();
        return;
      case 'sqlite-table-foreign-key':
        self::deleteForeignKey();
        return;
      case 'sqlite-table-trigger':
        self::deleteTrigger();
        return;
    }
  }

  /** Generates SQLite CREATE TABLE SQL. */
  public static function generate($panel = null): void {
    $connection = self::currentConnection();
    if ($connection === false || self::$schema === false || self::$schema === '') {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection and database before saving.');
      return;
    }
    $panel = $panel ?: self::panel();
    if ($panel === false) {
      return;
    }
    $values = $panel->getValue();
    $table = self::currentTableName($panel);
    if ($table === '') {
      \SPTK\Elements\WarningPanel::forge('No object name!', 'Please enter an object name before saving.');
      return;
    }
    self::syncColumnOrderFromList();
    try {
      if (self::$mode === 'modify') {
        $sql = self::buildAlterSql(
          self::$schema,
          (string)self::$originalTable,
          $table,
          self::$originalColumns,
          self::$columns,
          self::$originalIndexes,
          self::$indexes,
          self::$originalForeignKeys,
          self::$foreignKeys,
          self::$originalTriggers,
          self::$triggers
        );
      } else {
        $sql = self::buildCreateSql(self::$schema, $table, self::$columns, self::$indexes, self::$foreignKeys, self::$triggers);
      }
    } catch (\InvalidArgumentException $e) {
      \SPTK\Elements\WarningPanel::forge('Could not create SQL', $e->getMessage());
      return;
    }
    if (trim($sql) === '') {
      \SPTK\Elements\WarningPanel::forge('No changes', 'No table changes were detected.');
      return;
    }
    $action = self::$mode === 'modify' ? 'Modify SQLite table' : 'Create SQLite table';
    $verb = self::$mode === 'modify' ? 'ALTER' : 'CREATE';
    \MADB\Query\GeneratedQueryController::open([
      'title' => $action,
      'name' => $verb . ' ' . self::$schema . '.' . $table,
      'sql' => $sql,
      'connection' => $connection,
      'schema' => self::$schema,
      'table' => $table,
      'cacheKeys' => self::cacheKeys(self::$schema, $table, self::$originalTable),
      'refresh' => 'tables'
    ]);
  }

  /** Closes the SQLite table create panel. */
  public static function close($panel): void {
    $panel->hide();
    \MADB\Main\ScreenController::restoreFocusAfterPanelClose();
    \SPTK\Element::refresh();
  }

  /** Builds SQLite CREATE TABLE SQL from normalized column data. */
  public static function buildCreateSql(string $schema, string $table, array $columns, array $indexes = [], array $foreignKeys = [], array $triggers = []): string {
    $columns = array_values(array_filter(array_map(fn($column) => self::normalizeColumn($column), $columns), fn($column) => $column['name'] !== ''));
    if (empty($columns)) {
      throw new \InvalidArgumentException('Please add at least one field before generating SQL.');
    }
    $primaryColumns = array_values(array_filter($columns, fn($column) => $column['primary']));
    $autoColumns = array_values(array_filter($columns, fn($column) => $column['autoincrement']));
    if (count($autoColumns) > 1) {
      throw new \InvalidArgumentException('Only one SQLite field can use auto increment.');
    }
    if (count($autoColumns) === 1) {
      $auto = $autoColumns[0];
      if (!$auto['primary'] || strtoupper($auto['type']) !== 'INTEGER' || count($primaryColumns) !== 1) {
        throw new \InvalidArgumentException('SQLite auto increment requires one INTEGER primary key field.');
      }
    }
    $definitions = [];
    $tablePrimary = count($primaryColumns) > 1;
    foreach ($columns as $column) {
      $definitions[] = self::columnDefinition($column, !$tablePrimary);
    }
    if ($tablePrimary) {
      $definitions[] = 'PRIMARY KEY (' . implode(', ', array_map(fn($column) => self::quoteIdentifier($column['name']), $primaryColumns)) . ')';
    }
    foreach (self::groupForeignKeysFromRows($foreignKeys) as $foreignKey) {
      $definitions[] = self::foreignKeyDefinition($foreignKey);
    }
    $statements = ['CREATE TABLE ' . self::quoteQualifiedName($schema, $table) . " (\n  " . implode(",\n  ", $definitions) . "\n);"];
    foreach (self::groupIndexesFromRows($indexes) as $index) {
      $statements[] = self::indexCreateSql($schema, $table, $index);
    }
    foreach (self::normalizedTriggers($triggers) as $trigger) {
      $statements[] = self::triggerCreateSql($schema, $table, $trigger);
    }
    return implode("\n\n", $statements);
  }

  /** Builds native SQLite ALTER TABLE SQL from normalized old and new table state. */
  public static function buildAlterSql(
    string $schema,
    string $oldTable,
    string $newTable,
    array $originalColumns,
    array $columns,
    array $originalIndexes = [],
    array $indexes = [],
    array $originalForeignKeys = [],
    array $foreignKeys = [],
    array $originalTriggers = [],
    array $triggers = []
  ): string {
    $oldTable = trim($oldTable);
    $newTable = trim($newTable);
    if ($oldTable === '' || $newTable === '') {
      throw new \InvalidArgumentException('Please enter an object name before generating SQL.');
    }
    $originalColumns = self::namedColumns($originalColumns);
    $columns = self::namedColumns($columns);
    if (empty($columns)) {
      throw new \InvalidArgumentException('Please keep at least one field before generating SQL.');
    }
    $statements = [];
    $alterTable = $oldTable;
    if ($oldTable !== $newTable) {
      $statements[] = 'ALTER TABLE ' . self::quoteQualifiedName($schema, $oldTable) . ' RENAME TO ' . self::quoteIdentifier($newTable) . ';';
      $alterTable = $newTable;
    }
    foreach (self::columnAlterStatements($schema, $alterTable, $originalColumns, $columns) as $statement) {
      $statements[] = $statement;
    }
    if (self::normalizeForeignKeyGroups($originalForeignKeys) !== self::normalizeForeignKeyGroups($foreignKeys)) {
      throw new \InvalidArgumentException('Changing SQLite foreign keys requires rebuilding the table.');
    }
    foreach (self::indexAlterStatements($schema, $alterTable, $originalIndexes, $indexes) as $statement) {
      $statements[] = $statement;
    }
    foreach (self::triggerAlterStatements($schema, $alterTable, $originalTriggers, $triggers) as $statement) {
      $statements[] = $statement;
    }
    return implode("\n\n", $statements);
  }

  /** Quotes an SQLite identifier. */
  public static function quoteIdentifier(string $identifier): string {
    return '"' . str_replace('"', '""', $identifier) . '"';
  }

  /** Quotes an SQLite qualified object name. */
  public static function quoteQualifiedName(string $schema, string $name): string {
    return self::quoteIdentifier($schema) . '.' . self::quoteIdentifier($name);
  }

  /** Returns current connection data. */
  private static function currentConnection() {
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    return $connectionList->current;
  }

  /** Returns table create panel. */
  private static function panel() {
    return \SPTK\Element::byName('sqlite-table-create');
  }

  /** Returns column editor panel. */
  private static function columnPanel() {
    return \SPTK\Element::byName('sqlite-column-editor');
  }

  /** Returns column list. */
  private static function columnList() {
    return \SPTK\Element::byName('sqlite-table-columns', self::panel());
  }

  /** Returns SQLite table editor tabs. */
  private static function tabs() {
    return \SPTK\Element::byName('sqlite-table-tabs', self::panel());
  }

  /** Returns the active SQLite table editor tab name. */
  private static function currentTabName() {
    $tabs = self::tabs();
    return $tabs === false ? false : $tabs->getCurrentTabContentName();
  }

  /** Returns the focus target for one SQLite table editor tab. */
  private static function currentTabInputName($contentName = false) {
    if ($contentName === false) {
      $contentName = self::currentTabName();
    }
    $inputs = [
      'sqlite-table-main' => 'sqlite-table-name',
      'sqlite-table-column' => 'sqlite-table-columns',
      'sqlite-table-index' => 'sqlite-table-indexes',
      'sqlite-table-foreign-key' => 'sqlite-table-foreign-keys',
      'sqlite-table-trigger' => 'sqlite-table-triggers'
    ];
    return $inputs[$contentName] ?? false;
  }

  /** Returns a list inside the SQLite table editor panel. */
  private static function listElement(string $name) {
    return \SPTK\Element::byName($name, self::panel());
  }

  /** Returns an SQLite item editor panel. */
  private static function itemPanel(string $name) {
    return \SPTK\Element::byName($name);
  }

  /** Shows an SQLite item editor panel and focuses one control. */
  private static function showItemPanel($panel, string $inputName): void {
    if ($panel === false) {
      return;
    }
    $panel->show();
    if (method_exists($panel, 'activateInput')) {
      $panel->activateInput($inputName);
    }
    \SPTK\Element::refresh();
  }

  /** Resets active item editor state. */
  private static function resetItemEditors(): void {
    self::$editingIndex = false;
    self::$addingIndex = false;
    self::$editingForeignKey = false;
    self::$addingForeignKey = false;
    self::$editingTrigger = false;
    self::$addingTrigger = false;
  }

  /** Applies a placeholder to a SQLite table editor list. */
  private static function setPlaceholder(string $listName, string $message): void {
    $list = self::listElement($listName);
    if ($list === false) {
      return;
    }
    $list->clear();
    $list->addItem(['text' => $message]);
  }

  /** Returns the table name even when the Main tab is not active. */
  private static function currentTableName($panel = null): string {
    $input = \SPTK\Element::byName('sqlite-table-name', self::panel());
    if ($input !== false) {
      $value = trim(self::textValue($input->getValue()));
      if ($value !== '') {
        self::$table = $value;
        return $value;
      }
    }
    if ($panel !== null && method_exists($panel, 'getValue')) {
      $values = $panel->getValue();
      $value = trim(self::textValue($values['sqlite-table-name'] ?? ''));
      if ($value !== '') {
        self::$table = $value;
        return $value;
      }
    }
    return trim((string)(self::$table ?: self::$originalTable ?: ''));
  }

  /** Converts loaded table metadata into SQLite panel column state. */
  private static function columnsFromDefinition(array $definition): array {
    $table = $definition['table'] ?? [];
    $autoIncrementColumn = self::autoIncrementColumnName((string)($table['createSql'] ?? ''), $definition['columns'] ?? []);
    $columns = [];
    foreach (($definition['columns'] ?? []) as $column) {
      $name = trim((string)($column['COLUMN_NAME'] ?? ''));
      if ($name === '') {
        continue;
      }
      $columns[] = self::normalizeColumn([
        'name' => $name,
        'type' => self::normalizeSQLiteType((string)($column['COLUMN_TYPE'] ?? 'TEXT')),
        'primary' => ($column['COLUMN_KEY'] ?? '') === 'PRI',
        'notNull' => ($column['IS_NULLABLE'] ?? '') === 'NO',
        'autoincrement' => $autoIncrementColumn !== false && strcasecmp($autoIncrementColumn, $name) === 0,
        'default' => $column['COLUMN_DEFAULT'] ?? ''
      ]);
    }
    return $columns;
  }

  /** Converts loaded index metadata into SQLite panel index state. */
  private static function indexesFromDefinition(array $definition): array {
    return array_values(array_filter($definition['indexes'] ?? [], fn($index) => ($index['INDEX_NAME'] ?? '') !== 'PRIMARY'));
  }

  /** Converts loaded foreign-key metadata into SQLite panel state. */
  private static function foreignKeysFromDefinition(array $definition): array {
    return array_values($definition['foreignKeys'] ?? []);
  }

  /** Converts loaded trigger metadata into SQLite panel state. */
  private static function triggersFromDefinition(array $definition): array {
    return array_values($definition['triggers'] ?? []);
  }

  /** Returns selected list value. */
  private static function selectedListValue(string $listName) {
    $list = self::listElement($listName);
    return $list === false ? false : $list->getValue();
  }

  /** Warns when no item is selected. */
  private static function warnNoItemSelected(string $itemType): void {
    \SPTK\Elements\WarningPanel::forge('No ' . $itemType . ' selected', 'Please select a ' . $itemType . ' before deleting.');
  }

  /** Returns whether at least one named column exists. */
  private static function hasNamedColumns(): bool {
    return self::firstNamedColumn() !== '';
  }

  /** Returns first named SQLite column. */
  private static function firstNamedColumn(): string {
    foreach (self::$columns as $column) {
      $name = trim((string)($column['name'] ?? ''));
      if ($name !== '') {
        return $name;
      }
    }
    return '';
  }

  /** Warns about missing columns for a dependent editor. */
  private static function warnNoColumnsFor(string $itemType): void {
    \SPTK\Elements\WarningPanel::forge('No fields defined', 'Please add at least one field before adding ' . $itemType . '.');
  }

  /** Updates dependent index and FK rows when a SQLite column is renamed. */
  private static function renameDependentColumnReferences(string $oldName, string $newName): void {
    if ($oldName === '' || $newName === '' || $oldName === $newName) {
      return;
    }
    foreach (self::$indexes as &$index) {
      if (($index['COLUMN_NAME'] ?? '') === $oldName) {
        $index['COLUMN_NAME'] = $newName;
      }
    }
    unset($index);
    foreach (self::$foreignKeys as &$foreignKey) {
      if (($foreignKey['COLUMN_NAME'] ?? '') === $oldName) {
        $foreignKey['COLUMN_NAME'] = $newName;
      }
    }
    unset($foreignKey);
  }

  /** Normalizes an SPTK scalar/list value to a list of strings. */
  private static function arrayValue($value): array {
    if (!is_array($value)) {
      $value = $value === false || $value === '' ? [] : [$value];
    }
    return array_values(array_filter(array_map(fn($item) => trim((string)$item), $value), fn($item) => $item !== ''));
  }

  /** Populates the SQLite index column selector. */
  private static function setIndexColumnList(array $selectedColumns): void {
    $list = \SPTK\Element::byName('sqlite-index-columns', self::itemPanel('sqlite-index-editor'));
    if ($list === false) {
      return;
    }
    $items = [];
    foreach (self::$columns as $column) {
      $name = trim((string)($column['name'] ?? ''));
      if ($name !== '') {
        $items[] = ['value' => $name, 'selectable' => true, 'filterable' => true];
      }
    }
    $list->setItems($items);
    $list->setSelectedValues($selectedColumns);
  }

  /** Populates the SQLite foreign-key source column selector. */
  private static function setForeignKeySourceColumnList(array $selectedColumns): void {
    $list = \SPTK\Element::byName('sqlite-foreign-key-column', self::itemPanel('sqlite-foreign-key-editor'));
    if ($list === false) {
      return;
    }
    $items = [];
    foreach (self::$columns as $column) {
      $name = trim((string)($column['name'] ?? ''));
      if ($name !== '') {
        $items[] = ['value' => $name, 'selectable' => true, 'filterable' => true];
      }
    }
    $list->setItems($items);
    $list->setSelectedValues($selectedColumns);
  }

  /** Populates the SQLite foreign-key target table selector. */
  private static function setForeignKeyTargetTableOptions(string $selectedTable): void {
    $select = \SPTK\Element::byName('sqlite-foreign-key-target-table', self::itemPanel('sqlite-foreign-key-editor'));
    if ($select === false) {
      return;
    }
    $tables = array_values(array_filter(array_unique([self::currentTableName(), $selectedTable]), fn($table) => $table !== ''));
    $select->setOptions($tables);
    $select->setValue($selectedTable);
  }

  /** Populates the SQLite foreign-key target column selector. */
  private static function setForeignKeyTargetColumnList(array $selectedColumns): void {
    $list = \SPTK\Element::byName('sqlite-foreign-key-target-column', self::itemPanel('sqlite-foreign-key-editor'));
    if ($list === false) {
      return;
    }
    $items = [];
    $names = [];
    foreach (self::$columns as $column) {
      $name = trim((string)($column['name'] ?? ''));
      if ($name !== '') {
        $names[$name] = true;
      }
    }
    foreach ($selectedColumns as $name) {
      if ($name !== '') {
        $names[$name] = true;
      }
    }
    foreach (array_keys($names) as $name) {
      $items[] = ['value' => $name, 'selectable' => true, 'filterable' => true];
    }
    $list->setItems($items);
    $list->setSelectedValues($selectedColumns);
  }

  /** Groups SQLite index rows by name. */
  private static function groupIndexesFromRows(array $indexes): array {
    $groups = [];
    foreach ($indexes as $row) {
      $name = trim((string)($row['INDEX_NAME'] ?? ''));
      if ($name === '' || $name === 'PRIMARY') {
        continue;
      }
      if (!isset($groups[$name])) {
        $groups[$name] = [
          'name' => $name,
          'nonUnique' => (int)($row['NON_UNIQUE'] ?? 1),
          'columns' => []
        ];
      }
      $column = trim((string)($row['COLUMN_NAME'] ?? ''));
      if ($column !== '') {
        $groups[$name]['columns'][] = [
          'name' => $column,
          'collation' => ($row['COLLATION'] ?? '') === 'D' ? 'D' : 'A',
          'sequence' => (int)($row['SEQ_IN_INDEX'] ?? count($groups[$name]['columns']) + 1)
        ];
      }
    }
    foreach ($groups as &$group) {
      usort($group['columns'], fn($a, $b) => $a['sequence'] <=> $b['sequence']);
      $group['columns'] = array_map(fn($column) => ['name' => $column['name'], 'collation' => $column['collation']], $group['columns']);
    }
    unset($group);
    return $groups;
  }

  /** Replaces all rows for one index. */
  private static function replaceIndexRows(array $indexes, string $oldName, array $rows): array {
    $result = [];
    $inserted = false;
    foreach ($indexes as $index) {
      if (($index['INDEX_NAME'] ?? '') === $oldName) {
        if (!$inserted) {
          array_push($result, ...$rows);
          $inserted = true;
        }
        continue;
      }
      $result[] = $index;
    }
    if (!$inserted) {
      array_push($result, ...$rows);
    }
    return $result;
  }

  /** Groups SQLite foreign-key rows by name. */
  private static function groupForeignKeysFromRows(array $foreignKeys): array {
    $groups = [];
    foreach ($foreignKeys as $row) {
      $name = trim((string)($row['CONSTRAINT_NAME'] ?? ''));
      if ($name === '') {
        continue;
      }
      if (!isset($groups[$name])) {
        $groups[$name] = [
          'name' => $name,
          'targetTable' => (string)($row['REFERENCED_TABLE_NAME'] ?? ''),
          'updateRule' => (string)($row['UPDATE_RULE'] ?? ''),
          'deleteRule' => (string)($row['DELETE_RULE'] ?? ''),
          'columns' => []
        ];
      }
      $groups[$name]['columns'][] = [
        'source' => (string)($row['COLUMN_NAME'] ?? ''),
        'target' => (string)($row['REFERENCED_COLUMN_NAME'] ?? ''),
        'sequence' => (int)($row['ORDINAL_POSITION'] ?? count($groups[$name]['columns']) + 1)
      ];
    }
    foreach ($groups as &$group) {
      usort($group['columns'], fn($a, $b) => $a['sequence'] <=> $b['sequence']);
      $group['columns'] = array_map(fn($column) => ['source' => $column['source'], 'target' => $column['target']], $group['columns']);
    }
    unset($group);
    return $groups;
  }

  /** Normalizes grouped foreign keys for comparison. */
  private static function normalizeForeignKeyGroups(array $foreignKeys): array {
    $groups = array_values(self::groupForeignKeysFromRows($foreignKeys));
    usort($groups, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $groups;
  }

  /** Replaces all rows for one foreign key. */
  private static function replaceForeignKeyRows(array $foreignKeys, string $oldName, array $rows): array {
    $result = [];
    $inserted = false;
    foreach ($foreignKeys as $foreignKey) {
      if (($foreignKey['CONSTRAINT_NAME'] ?? '') === $oldName) {
        if (!$inserted) {
          array_push($result, ...$rows);
          $inserted = true;
        }
        continue;
      }
      $result[] = $foreignKey;
    }
    if (!$inserted) {
      array_push($result, ...$rows);
    }
    return $result;
  }

  /** Returns the first row index for a foreign key name. */
  private static function firstForeignKeyRowIndex(string $name) {
    foreach (self::$foreignKeys as $index => $foreignKey) {
      if (($foreignKey['CONSTRAINT_NAME'] ?? '') === $name) {
        return $index;
      }
    }
    return false;
  }

  /** Finds a trigger by name. */
  private static function findTrigger(string $name): array {
    foreach (self::$triggers as $index => $trigger) {
      if (($trigger['TRIGGER_NAME'] ?? '') === $name) {
        return [$index, $trigger];
      }
    }
    return [false, false];
  }

  /** Normalizes trigger rows. */
  private static function normalizedTriggers(array $triggers): array {
    $result = [];
    foreach ($triggers as $trigger) {
      $name = trim((string)($trigger['TRIGGER_NAME'] ?? ''));
      if ($name === '') {
        continue;
      }
      $result[$name] = [
        'name' => $name,
        'timing' => strtoupper(trim((string)($trigger['ACTION_TIMING'] ?? ''))),
        'event' => strtoupper(trim((string)($trigger['EVENT_MANIPULATION'] ?? ''))),
        'statement' => trim(self::textValue($trigger['ACTION_STATEMENT'] ?? ''))
      ];
    }
    ksort($result);
    return $result;
  }

  /** Applies title text to the SQLite table panel. */
  private static function setTitle(string $title): void {
    $panelTitle = \SPTK\Element::firstByType('PanelTitle', self::panel());
    if ($panelTitle !== false) {
      $panelTitle->setText($title);
    }
  }

  /** Opens and fills the SQLite column editor panel. */
  private static function openColumnPanel(array $column, string $input): void {
    $panel = self::columnPanel();
    if ($panel === false) {
      return;
    }
    self::$syncingColumnPanel = true;
    $panel->setValue([
      'sqlite-column-name' => $column['name'] ?? '',
      'sqlite-column-primary' => (bool)($column['primary'] ?? false),
      'sqlite-column-not-null' => (bool)($column['notNull'] ?? false),
      'sqlite-column-autoincrement' => (bool)($column['autoincrement'] ?? false),
      'sqlite-column-default' => $column['default'] ?? ''
    ]);
    self::selectColumnType((string)($column['type'] ?? 'TEXT'));
    self::$syncingColumnPanel = false;
    $panel->show();
    if (method_exists($panel, 'activateInput')) {
      $panel->activateInput($input);
    }
    \SPTK\Element::refresh();
  }

  /** Selects the column type in the type list. */
  private static function selectColumnType(string $type): void {
    $list = \SPTK\Element::byName('sqlite-column-type', self::columnPanel());
    if ($list === false) {
      return;
    }
    $type = strtoupper($type);
    foreach (['INTEGER', 'REAL', 'TEXT', 'BLOB', 'NUMERIC'] as $index => $option) {
      if ($option === $type && method_exists($list, 'moveCursor')) {
        $list->moveCursor($index);
        return;
      }
    }
  }

  /** Reads column data from the SQLite column editor panel. */
  private static function columnFromPanel($panel): array {
    $values = $panel->getValue();
    return self::normalizeColumn([
      'name' => self::textValue($values['sqlite-column-name'] ?? ''),
      'type' => self::textValue($values['sqlite-column-type'] ?? 'TEXT'),
      'primary' => (bool)($values['sqlite-column-primary'] ?? false),
      'notNull' => (bool)($values['sqlite-column-not-null'] ?? false),
      'autoincrement' => (bool)($values['sqlite-column-autoincrement'] ?? false),
      'default' => self::textValue($values['sqlite-column-default'] ?? '')
    ]);
  }

  /** Normalizes one SQLite column data row. */
  private static function normalizeColumn(array $column): array {
    $type = self::normalizeSQLiteType((string)($column['type'] ?? 'TEXT'));
    return [
      'name' => trim((string)($column['name'] ?? '')),
      'type' => $type,
      'primary' => (bool)($column['primary'] ?? false),
      'notNull' => (bool)($column['notNull'] ?? false),
      'autoincrement' => (bool)($column['autoincrement'] ?? false),
      'default' => trim((string)($column['default'] ?? ''))
    ];
  }

  /** Reduces an SQLite declared type to the panel-supported type list. */
  private static function normalizeSQLiteType(string $type): string {
    $type = strtoupper(trim($type));
    if (str_contains($type, 'INT')) {
      return 'INTEGER';
    }
    if (str_contains($type, 'CHAR') || str_contains($type, 'CLOB') || str_contains($type, 'TEXT')) {
      return 'TEXT';
    }
    if (str_contains($type, 'BLOB') || $type === '') {
      return 'BLOB';
    }
    if (str_contains($type, 'REAL') || str_contains($type, 'FLOA') || str_contains($type, 'DOUB')) {
      return 'REAL';
    }
    return 'NUMERIC';
  }

  /** Renders the SQLite column list. */
  private static function setColumns(): void {
    $list = self::columnList();
    if ($list === false) {
      return;
    }
    $list->clear();
    if (empty(self::$columns)) {
      self::setColumnPlaceholder('No fields defined yet.');
      return;
    }
    foreach (self::$columns as $index => $column) {
      $column = self::normalizeColumn($column);
      $attributes = [];
      if ($column['primary']) {
        $attributes[] = 'PRIMARY';
      }
      if ($column['notNull']) {
        $attributes[] = 'NOT NULL';
      }
      if ($column['autoincrement']) {
        $attributes[] = 'AUTO INC';
      }
      $list->addItem([
        'value' => (string)$index,
        'columns' => [
          $column['name'],
          $column['type'],
          implode(' ', $attributes),
          $column['default']
        ]
      ]);
    }
  }

  /** Shows a placeholder row in the SQLite column list. */
  private static function setColumnPlaceholder(string $message): void {
    $list = self::columnList();
    if ($list === false) {
      return;
    }
    $list->clear();
    $list->addItem(['text' => $message]);
  }

  /** Renders the SQLite index list. */
  private static function setIndexes(): void {
    $list = self::listElement('sqlite-table-indexes');
    if ($list === false) {
      return;
    }
    $list->clear();
    $groups = self::groupIndexesFromRows(self::$indexes);
    if (empty($groups)) {
      self::setPlaceholder('sqlite-table-indexes', 'No indices defined yet.');
      return;
    }
    foreach ($groups as $index) {
      $columns = array_map(fn($column) => (($column['collation'] ?? '') === 'D' ? '-' : '') . $column['name'], $index['columns']);
      $list->addItem([
        'value' => $index['name'],
        'columns' => [$index['name'], ((int)$index['nonUnique'] === 0 ? 'UNIQUE' : 'INDEX'), implode(', ', $columns)]
      ]);
    }
  }

  /** Renders the SQLite foreign-key list. */
  private static function setForeignKeys(): void {
    $list = self::listElement('sqlite-table-foreign-keys');
    if ($list === false) {
      return;
    }
    $list->clear();
    $groups = self::groupForeignKeysFromRows(self::$foreignKeys);
    if (empty($groups)) {
      self::setPlaceholder('sqlite-table-foreign-keys', 'No foreign keys defined yet.');
      return;
    }
    foreach ($groups as $foreignKey) {
      $sources = array_map(fn($column) => $column['source'], $foreignKey['columns']);
      $targets = array_map(fn($column) => $column['target'], $foreignKey['columns']);
      $list->addItem([
        'value' => $foreignKey['name'],
        'columns' => [
          $foreignKey['name'],
          implode(', ', $sources),
          $foreignKey['targetTable'] . '.' . implode(', ', $targets),
          'U:' . $foreignKey['updateRule'] . ' D:' . $foreignKey['deleteRule']
        ]
      ]);
    }
  }

  /** Renders the SQLite trigger list. */
  private static function setTriggers(): void {
    $list = self::listElement('sqlite-table-triggers');
    if ($list === false) {
      return;
    }
    $list->clear();
    $triggers = self::normalizedTriggers(self::$triggers);
    if (empty($triggers)) {
      self::setPlaceholder('sqlite-table-triggers', 'No triggers defined yet.');
      return;
    }
    foreach ($triggers as $trigger) {
      $list->addItem([
        'value' => $trigger['name'],
        'columns' => [$trigger['name'], $trigger['timing'], $trigger['event'], $trigger['statement']]
      ]);
    }
  }

  /** Adds a SQLite index. */
  public static function addIndex($panel = null): void {
    if (!self::hasNamedColumns()) {
      self::warnNoColumnsFor('indices');
      return;
    }
    $name = "\0new-index-" . count(self::$indexes);
    self::$indexes[] = [
      'INDEX_NAME' => $name,
      'NON_UNIQUE' => 1,
      'SEQ_IN_INDEX' => 1,
      'COLUMN_NAME' => '',
      'COLLATION' => 'A',
      'INDEX_TYPE' => 'BTREE'
    ];
    self::$editingIndex = $name;
    self::$addingIndex = $name;
    $panel = self::itemPanel('sqlite-index-editor');
    $panel->setValue([
      'sqlite-index-name' => '',
      'sqlite-index-type' => 'INDEX'
    ]);
    self::setIndexColumnList([]);
    self::showItemPanel($panel, 'sqlite-index-name');
  }

  /** Opens a SQLite index editor. */
  public static function openIndexEditor($item): void {
    $name = (string)$item->getValue();
    $group = self::groupIndexesFromRows(self::$indexes)[$name] ?? false;
    if ($group === false) {
      return;
    }
    self::$editingIndex = $name;
    self::$addingIndex = false;
    $panel = self::itemPanel('sqlite-index-editor');
    $panel->setValue([
      'sqlite-index-name' => $name,
      'sqlite-index-type' => ((int)$group['nonUnique'] === 0 ? 'UNIQUE' : 'INDEX')
    ]);
    self::setIndexColumnList(array_map(fn($column) => $column['name'], $group['columns']));
    self::showItemPanel($panel, 'sqlite-index-name');
  }

  /** Saves a SQLite index editor. */
  public static function saveIndexEditor($panel): void {
    if (self::$editingIndex === false) {
      return;
    }
    $values = $panel->getValue();
    $name = trim(self::textValue($values['sqlite-index-name'] ?? ''));
    if ($name === '') {
      \SPTK\Elements\WarningPanel::forge('No index name', 'Please enter an index name before saving.');
      return;
    }
    $columns = self::arrayValue($values['sqlite-index-columns'] ?? []);
    if (empty($columns)) {
      \SPTK\Elements\WarningPanel::forge('No fields selected', 'Please select at least one field before saving.');
      return;
    }
    $rows = [];
    foreach ($columns as $index => $column) {
      $rows[] = [
        'INDEX_NAME' => $name,
        'NON_UNIQUE' => strtoupper((string)($values['sqlite-index-type'] ?? 'INDEX')) === 'UNIQUE' ? 0 : 1,
        'SEQ_IN_INDEX' => $index + 1,
        'COLUMN_NAME' => $column,
        'COLLATION' => 'A',
        'INDEX_TYPE' => 'BTREE'
      ];
    }
    self::$indexes = self::replaceIndexRows(self::$indexes, self::$editingIndex, $rows);
    self::$editingIndex = false;
    self::$addingIndex = false;
    self::setIndexes();
    self::closeItemEditor($panel);
  }

  /** Deletes the selected SQLite index. */
  private static function deleteIndex(): void {
    $name = self::selectedListValue('sqlite-table-indexes');
    if ($name === false || $name === '') {
      self::warnNoItemSelected('index');
      return;
    }
    self::$indexes = array_values(array_filter(self::$indexes, fn($index) => ($index['INDEX_NAME'] ?? '') !== $name));
    self::setIndexes();
    \SPTK\Element::refresh();
  }

  /** Adds a SQLite foreign key. */
  public static function addForeignKey($panel = null): void {
    if (!self::hasNamedColumns()) {
      self::warnNoColumnsFor('foreign keys');
      return;
    }
    $source = self::firstNamedColumn();
    $name = "\0new-foreign-key-" . count(self::$foreignKeys);
    self::$foreignKeys[] = [
      'CONSTRAINT_NAME' => $name,
      'COLUMN_NAME' => $source,
      'REFERENCED_TABLE_SCHEMA' => self::$schema,
      'REFERENCED_TABLE_NAME' => '',
      'REFERENCED_COLUMN_NAME' => '',
      'UPDATE_RULE' => 'RESTRICT',
      'DELETE_RULE' => 'RESTRICT',
      'ORDINAL_POSITION' => 1
    ];
    self::$editingForeignKey = count(self::$foreignKeys) - 1;
    self::$addingForeignKey = self::$editingForeignKey;
    $panel = self::itemPanel('sqlite-foreign-key-editor');
    $panel->setValue([
      'sqlite-foreign-key-name' => '',
      'sqlite-foreign-key-table' => self::currentTableName(),
      'sqlite-foreign-key-update-rule' => 'RESTRICT',
      'sqlite-foreign-key-delete-rule' => 'RESTRICT'
    ]);
    self::setForeignKeySourceColumnList([$source]);
    self::setForeignKeyTargetTableOptions('');
    self::setForeignKeyTargetColumnList([]);
    self::showItemPanel($panel, 'sqlite-foreign-key-name');
  }

  /** Opens a SQLite foreign-key editor. */
  public static function openForeignKeyEditor($item): void {
    $name = (string)$item->getValue();
    $groups = self::groupForeignKeysFromRows(self::$foreignKeys);
    $group = $groups[$name] ?? false;
    if ($group === false) {
      return;
    }
    self::$editingForeignKey = self::firstForeignKeyRowIndex($name);
    self::$addingForeignKey = false;
    $panel = self::itemPanel('sqlite-foreign-key-editor');
    $panel->setValue([
      'sqlite-foreign-key-name' => $group['name'],
      'sqlite-foreign-key-table' => self::currentTableName(),
      'sqlite-foreign-key-target-table' => $group['targetTable'],
      'sqlite-foreign-key-update-rule' => $group['updateRule'],
      'sqlite-foreign-key-delete-rule' => $group['deleteRule']
    ]);
    self::setForeignKeySourceColumnList(array_map(fn($column) => $column['source'], $group['columns']));
    self::setForeignKeyTargetTableOptions($group['targetTable']);
    self::setForeignKeyTargetColumnList(array_map(fn($column) => $column['target'], $group['columns']));
    self::showItemPanel($panel, 'sqlite-foreign-key-name');
  }

  /** Updates target column options when target table changes. */
  public static function changeForeignKeyTargetTable($select): void {
    self::setForeignKeyTargetColumnList([]);
    \SPTK\Element::refresh();
  }

  /** Saves a SQLite foreign-key editor. */
  public static function saveForeignKeyEditor($panel): void {
    if (self::$editingForeignKey === false) {
      return;
    }
    $values = $panel->getValue();
    $sourceColumns = self::arrayValue($values['sqlite-foreign-key-column'] ?? []);
    $targetColumns = self::arrayValue($values['sqlite-foreign-key-target-column'] ?? []);
    if (empty($sourceColumns) || empty($targetColumns)) {
      \SPTK\Elements\WarningPanel::forge('Missing columns', 'Please select at least one source and referenced column.');
      return;
    }
    if (count($sourceColumns) !== count($targetColumns)) {
      \SPTK\Elements\WarningPanel::forge('Column count mismatch', 'Please select the same number of source and referenced columns.');
      return;
    }
    $name = trim(self::textValue($values['sqlite-foreign-key-name'] ?? ''));
    if ($name === '') {
      \SPTK\Elements\WarningPanel::forge('No foreign key name', 'Please enter a foreign key name before saving.');
      return;
    }
    $rows = [];
    foreach ($sourceColumns as $index => $sourceColumn) {
      $rows[] = [
        'CONSTRAINT_NAME' => $name,
        'COLUMN_NAME' => $sourceColumn,
        'REFERENCED_TABLE_SCHEMA' => self::$schema,
        'REFERENCED_TABLE_NAME' => self::textValue($values['sqlite-foreign-key-target-table'] ?? ''),
        'REFERENCED_COLUMN_NAME' => $targetColumns[$index],
        'UPDATE_RULE' => self::textValue($values['sqlite-foreign-key-update-rule'] ?? ''),
        'DELETE_RULE' => self::textValue($values['sqlite-foreign-key-delete-rule'] ?? ''),
        'ORDINAL_POSITION' => $index + 1
      ];
    }
    $oldName = self::$foreignKeys[self::$editingForeignKey]['CONSTRAINT_NAME'] ?? '';
    self::$foreignKeys = self::replaceForeignKeyRows(self::$foreignKeys, $oldName, $rows);
    self::$editingForeignKey = false;
    self::$addingForeignKey = false;
    self::setForeignKeys();
    self::closeItemEditor($panel);
  }

  /** Deletes the selected SQLite foreign key. */
  private static function deleteForeignKey(): void {
    $name = self::selectedListValue('sqlite-table-foreign-keys');
    if ($name === false || $name === '') {
      self::warnNoItemSelected('foreign key');
      return;
    }
    self::$foreignKeys = array_values(array_filter(self::$foreignKeys, fn($foreignKey) => ($foreignKey['CONSTRAINT_NAME'] ?? '') !== $name));
    self::setForeignKeys();
    \SPTK\Element::refresh();
  }

  /** Adds a SQLite trigger. */
  public static function addTrigger($panel = null): void {
    self::$triggers[] = [
      'TRIGGER_NAME' => '',
      'ACTION_TIMING' => 'BEFORE',
      'EVENT_MANIPULATION' => 'INSERT',
      'ACTION_STATEMENT' => ''
    ];
    self::$editingTrigger = count(self::$triggers) - 1;
    self::$addingTrigger = self::$editingTrigger;
    $panel = self::itemPanel('sqlite-trigger-editor');
    $panel->setValue([
      'sqlite-trigger-name' => '',
      'sqlite-trigger-timing' => 'BEFORE',
      'sqlite-trigger-event' => 'INSERT',
      'sqlite-trigger-statement' => ''
    ]);
    self::showItemPanel($panel, 'sqlite-trigger-name');
  }

  /** Opens a SQLite trigger editor. */
  public static function openTriggerEditor($item): void {
    [$index, $trigger] = self::findTrigger((string)$item->getValue());
    if ($trigger === false) {
      return;
    }
    self::$editingTrigger = $index;
    self::$addingTrigger = false;
    $panel = self::itemPanel('sqlite-trigger-editor');
    $panel->setValue([
      'sqlite-trigger-name' => $trigger['TRIGGER_NAME'] ?? '',
      'sqlite-trigger-timing' => $trigger['ACTION_TIMING'] ?? '',
      'sqlite-trigger-event' => $trigger['EVENT_MANIPULATION'] ?? '',
      'sqlite-trigger-statement' => $trigger['ACTION_STATEMENT'] ?? ''
    ]);
    self::showItemPanel($panel, 'sqlite-trigger-name');
  }

  /** Saves a SQLite trigger editor. */
  public static function saveTriggerEditor($panel): void {
    if (self::$editingTrigger === false || !isset(self::$triggers[self::$editingTrigger])) {
      return;
    }
    $values = $panel->getValue();
    $name = trim(self::textValue($values['sqlite-trigger-name'] ?? ''));
    if ($name === '') {
      \SPTK\Elements\WarningPanel::forge('No trigger name', 'Please enter a trigger name before saving.');
      return;
    }
    self::$triggers[self::$editingTrigger] = [
      'TRIGGER_NAME' => $name,
      'ACTION_TIMING' => self::textValue($values['sqlite-trigger-timing'] ?? ''),
      'EVENT_MANIPULATION' => self::textValue($values['sqlite-trigger-event'] ?? ''),
      'ACTION_STATEMENT' => self::textValue($values['sqlite-trigger-statement'] ?? '')
    ];
    self::$editingTrigger = false;
    self::$addingTrigger = false;
    self::setTriggers();
    self::closeItemEditor($panel);
  }

  /** Deletes the selected SQLite trigger. */
  private static function deleteTrigger(): void {
    [$index, $trigger] = self::findTrigger((string)self::selectedListValue('sqlite-table-triggers'));
    if ($trigger === false) {
      self::warnNoItemSelected('trigger');
      return;
    }
    array_splice(self::$triggers, $index, 1);
    self::setTriggers();
    \SPTK\Element::refresh();
  }

  /** Closes any SQLite item editor, removing pending additions. */
  public static function closeItemEditor($panel): void {
    if (self::$addingIndex !== false) {
      self::$indexes = array_values(array_filter(self::$indexes, fn($index) => ($index['INDEX_NAME'] ?? '') !== self::$addingIndex));
      self::setIndexes();
    }
    if (self::$addingForeignKey !== false && isset(self::$foreignKeys[self::$addingForeignKey])) {
      array_splice(self::$foreignKeys, self::$addingForeignKey, 1);
      self::setForeignKeys();
    }
    if (self::$addingTrigger !== false && isset(self::$triggers[self::$addingTrigger])) {
      array_splice(self::$triggers, self::$addingTrigger, 1);
      self::setTriggers();
    }
    self::resetItemEditors();
    $panel->hide();
    \SPTK\Element::refresh();
  }

  /** Synchronizes visual list order into SQLite column state. */
  private static function syncColumnOrderFromList(): void {
    $list = self::columnList();
    if ($list === false || !method_exists($list, 'getOrderValue')) {
      return;
    }
    $order = [];
    foreach ($list->getOrderValue() as $value) {
      if ($value === false || $value === '' || !is_numeric((string)$value)) {
        continue;
      }
      $order[] = (int)$value;
    }
    if (empty($order)) {
      return;
    }
    $columns = [];
    $added = [];
    foreach ($order as $index) {
      if (isset(self::$columns[$index]) && !isset($added[$index])) {
        $columns[] = self::$columns[$index];
        $added[$index] = true;
      }
    }
    foreach (self::$columns as $index => $column) {
      if (!isset($added[$index])) {
        $columns[] = $column;
      }
    }
    if ($columns === self::$columns) {
      return;
    }
    self::$columns = $columns;
    self::setColumns();
  }

  /** Builds one SQLite column definition. */
  private static function columnDefinition(array $column, bool $inlinePrimary): string {
    $sql = self::quoteIdentifier($column['name']) . ' ' . $column['type'];
    if ($inlinePrimary && $column['primary']) {
      $sql .= ' PRIMARY KEY';
      if ($column['autoincrement']) {
        $sql .= ' AUTOINCREMENT';
      }
    }
    if ($column['notNull'] && !($inlinePrimary && $column['primary'])) {
      $sql .= ' NOT NULL';
    }
    $default = self::defaultClause($column['default']);
    if ($default !== '') {
      $sql .= ' DEFAULT ' . $default;
    }
    return $sql;
  }

  /** Builds one SQLite index CREATE statement. */
  private static function indexCreateSql(string $schema, string $table, array $index): string {
    if (empty($index['columns'])) {
      throw new \InvalidArgumentException("Index '{$index['name']}' must contain at least one field.");
    }
    $columns = [];
    foreach ($index['columns'] as $column) {
      $columns[] = self::quoteIdentifier($column['name']) . (($column['collation'] ?? '') === 'D' ? ' DESC' : '');
    }
    $unique = ((int)($index['nonUnique'] ?? 1)) === 0 ? 'UNIQUE ' : '';
    return 'CREATE ' . $unique . 'INDEX ' . self::quoteQualifiedName($schema, $index['name']) .
      ' ON ' . self::quoteIdentifier($table) . ' (' . implode(', ', $columns) . ');';
  }

  /** Builds one SQLite foreign-key table constraint. */
  private static function foreignKeyDefinition(array $foreignKey): string {
    if (empty($foreignKey['columns'])) {
      throw new \InvalidArgumentException("Foreign key '{$foreignKey['name']}' must contain at least one field pair.");
    }
    $sourceColumns = [];
    $targetColumns = [];
    foreach ($foreignKey['columns'] as $column) {
      $sourceColumns[] = self::quoteIdentifier($column['source']);
      $targetColumns[] = self::quoteIdentifier($column['target']);
    }
    $sql = '';
    if (($foreignKey['name'] ?? '') !== '') {
      $sql .= 'CONSTRAINT ' . self::quoteIdentifier($foreignKey['name']) . ' ';
    }
    $sql .= 'FOREIGN KEY (' . implode(', ', $sourceColumns) . ')' .
      ' REFERENCES ' . self::quoteIdentifier($foreignKey['targetTable']) .
      ' (' . implode(', ', $targetColumns) . ')';
    if (($foreignKey['updateRule'] ?? '') !== '') {
      $sql .= ' ON UPDATE ' . $foreignKey['updateRule'];
    }
    if (($foreignKey['deleteRule'] ?? '') !== '') {
      $sql .= ' ON DELETE ' . $foreignKey['deleteRule'];
    }
    return $sql;
  }

  /** Builds one SQLite trigger CREATE statement. */
  private static function triggerCreateSql(string $schema, string $table, array $trigger): string {
    $statement = rtrim(trim((string)($trigger['statement'] ?? '')), ';');
    if ($statement === '') {
      throw new \InvalidArgumentException("Trigger '{$trigger['name']}' must have a statement.");
    }
    return 'CREATE TRIGGER ' . self::quoteQualifiedName($schema, $trigger['name']) . "\n" .
      $trigger['timing'] . ' ' . $trigger['event'] . ' ON ' . self::quoteIdentifier($table) . "\n" .
      "FOR EACH ROW\n" .
      $statement . ';';
  }

  /** Builds SQLite index ALTER-side statements. */
  private static function indexAlterStatements(string $schema, string $table, array $originalIndexes, array $indexes): array {
    $original = self::groupIndexesFromRows($originalIndexes);
    $current = self::groupIndexesFromRows($indexes);
    $statements = [];
    foreach ($original as $name => $index) {
      if (!isset($current[$name]) || $current[$name] !== $index) {
        $statements[] = 'DROP INDEX ' . self::quoteQualifiedName($schema, $name) . ';';
      }
    }
    foreach ($current as $name => $index) {
      if (!isset($original[$name]) || $original[$name] !== $index) {
        $statements[] = self::indexCreateSql($schema, $table, $index);
      }
    }
    return $statements;
  }

  /** Builds SQLite trigger ALTER-side statements. */
  private static function triggerAlterStatements(string $schema, string $table, array $originalTriggers, array $triggers): array {
    $original = self::normalizedTriggers($originalTriggers);
    $current = self::normalizedTriggers($triggers);
    $statements = [];
    foreach ($original as $name => $trigger) {
      if (!isset($current[$name]) || $current[$name] !== $trigger) {
        $statements[] = 'DROP TRIGGER ' . self::quoteQualifiedName($schema, $name) . ';';
      }
    }
    foreach ($current as $name => $trigger) {
      if (!isset($original[$name]) || $original[$name] !== $trigger) {
        $statements[] = self::triggerCreateSql($schema, $table, $trigger);
      }
    }
    return $statements;
  }

  /** Builds a SQLite DEFAULT expression from user input. */
  private static function defaultClause(string $value): string {
    if ($value === '') {
      return '';
    }
    $upper = strtoupper($value);
    if (in_array($upper, ['NULL', 'TRUE', 'FALSE', 'CURRENT_TIME', 'CURRENT_DATE', 'CURRENT_TIMESTAMP'], true)) {
      return $upper;
    }
    if (preg_match('/^-?(?:\d+|\d*\.\d+)$/', $value)) {
      return $value;
    }
    if (
      (str_starts_with($value, "'") && str_ends_with($value, "'")) ||
      (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
      (str_starts_with($value, '(') && str_ends_with($value, ')'))
    ) {
      return $value;
    }
    return "'" . str_replace("'", "''", $value) . "'";
  }

  /** Returns normalized, named columns. */
  private static function namedColumns(array $columns): array {
    return array_values(array_filter(array_map(fn($column) => self::normalizeColumn($column), $columns), fn($column) => $column['name'] !== ''));
  }

  /** Builds native SQLite ALTER TABLE column statements. */
  private static function columnAlterStatements(string $schema, string $table, array $originalColumns, array $columns): array {
    self::assertNoColumnOrderChange($originalColumns, $columns);
    $originalCount = count($originalColumns);
    $currentCount = count($columns);
    if ($currentCount === $originalCount) {
      return self::sameCountColumnAlterStatements($schema, $table, $originalColumns, $columns);
    }
    if ($currentCount > $originalCount) {
      return self::addedColumnAlterStatements($schema, $table, $originalColumns, $columns);
    }
    return self::droppedColumnAlterStatements($schema, $table, $originalColumns, $columns);
  }

  /** Builds rename-column statements when column count is unchanged. */
  private static function sameCountColumnAlterStatements(string $schema, string $table, array $originalColumns, array $columns): array {
    $statements = [];
    $originalNames = self::columnNames($originalColumns);
    $currentNames = self::columnNames($columns);
    if ($originalNames !== $currentNames && self::sameNameSet($originalNames, $currentNames)) {
      throw new \InvalidArgumentException(self::columnOrderWarning());
    }
    foreach ($originalColumns as $index => $original) {
      $column = $columns[$index];
      if (!self::sameColumnAttributes($original, $column)) {
        throw new \InvalidArgumentException("Changing SQLite field '{$original['name']}' requires rebuilding the table.");
      }
      if ($original['name'] === $column['name']) {
        continue;
      }
      if (in_array($column['name'], $originalNames, true)) {
        throw new \InvalidArgumentException('Changing SQLite field order or mixing field order with renames requires rebuilding the table.');
      }
      $statements[] = 'ALTER TABLE ' . self::quoteQualifiedName($schema, $table) . ' RENAME COLUMN ' . self::quoteIdentifier($original['name']) . ' TO ' . self::quoteIdentifier($column['name']) . ';';
    }
    return $statements;
  }

  /** Builds add-column statements for columns appended after existing columns. */
  private static function addedColumnAlterStatements(string $schema, string $table, array $originalColumns, array $columns): array {
    $statements = [];
    foreach ($originalColumns as $index => $original) {
      $column = $columns[$index] ?? false;
      if ($column === false || $original['name'] !== $column['name']) {
        $currentName = is_array($column) ? $column['name'] : '';
        if (in_array($currentName, self::columnNames($originalColumns), true)) {
          throw new \InvalidArgumentException(self::columnOrderWarning());
        }
        throw new \InvalidArgumentException('Adding SQLite fields before existing fields requires rebuilding the table.');
      }
      if (!self::sameColumnAttributes($original, $column)) {
        throw new \InvalidArgumentException("Changing SQLite field '{$original['name']}' requires rebuilding the table.");
      }
    }
    for ($index = count($originalColumns); $index < count($columns); $index++) {
      self::validateAddedColumn($columns[$index]);
      $statements[] = 'ALTER TABLE ' . self::quoteQualifiedName($schema, $table) . ' ADD COLUMN ' . self::columnDefinition($columns[$index], true) . ';';
    }
    return $statements;
  }

  /** Builds drop-column statements when remaining columns are unchanged and in order. */
  private static function droppedColumnAlterStatements(string $schema, string $table, array $originalColumns, array $columns): array {
    $statements = [];
    $matched = [];
    $searchFrom = 0;
    foreach ($columns as $column) {
      $match = false;
      for ($index = $searchFrom; $index < count($originalColumns); $index++) {
        if ($originalColumns[$index]['name'] === $column['name']) {
          $match = $index;
          break;
        }
      }
      if ($match === false) {
        if (in_array($column['name'], self::columnNames($originalColumns), true)) {
          throw new \InvalidArgumentException(self::columnOrderWarning());
        }
        throw new \InvalidArgumentException('Ambiguous SQLite field rename or delete detected.');
      }
      if (!self::sameColumnAttributes($originalColumns[$match], $column)) {
        throw new \InvalidArgumentException("Changing SQLite field '{$originalColumns[$match]['name']}' requires rebuilding the table.");
      }
      $matched[$match] = true;
      $searchFrom = $match + 1;
    }
    foreach ($originalColumns as $index => $original) {
      if (!isset($matched[$index])) {
        $statements[] = 'ALTER TABLE ' . self::quoteQualifiedName($schema, $table) . ' DROP COLUMN ' . self::quoteIdentifier($original['name']) . ';';
      }
    }
    return $statements;
  }

  /** Validates a column that will be appended with SQLite ADD COLUMN. */
  private static function validateAddedColumn(array $column): void {
    if ($column['primary'] || $column['autoincrement']) {
      throw new \InvalidArgumentException('SQLite ADD COLUMN cannot add primary key or auto increment fields.');
    }
    $default = strtoupper($column['default']);
    if ($column['notNull'] && ($default === '' || $default === 'NULL')) {
      throw new \InvalidArgumentException('SQLite ADD COLUMN requires a non-NULL default for NOT NULL fields.');
    }
    if (in_array($default, ['CURRENT_TIME', 'CURRENT_DATE', 'CURRENT_TIMESTAMP'], true) || str_starts_with($column['default'], '(')) {
      throw new \InvalidArgumentException('SQLite ADD COLUMN only supports literal defaults.');
    }
  }

  /** Detects retained SQLite columns whose relative order changed. */
  private static function assertNoColumnOrderChange(array $originalColumns, array $columns): void {
    $originalPositions = [];
    foreach ($originalColumns as $index => $column) {
      $originalPositions[$column['name']] = $index;
    }
    $previousPosition = -1;
    foreach ($columns as $column) {
      if (!array_key_exists($column['name'], $originalPositions)) {
        continue;
      }
      $position = $originalPositions[$column['name']];
      if ($position < $previousPosition) {
        throw new \InvalidArgumentException(self::columnOrderWarning());
      }
      $previousPosition = $position;
    }
  }

  /** Returns the user-facing warning for SQLite column order changes. */
  private static function columnOrderWarning(): string {
    return 'Changing SQLite field order requires rebuilding the table.';
  }

  /** Returns whether two columns differ only by name. */
  private static function sameColumnAttributes(array $a, array $b): bool {
    unset($a['name'], $b['name']);
    return $a === $b;
  }

  /** Returns column names in order. */
  private static function columnNames(array $columns): array {
    return array_map(fn($column) => $column['name'], $columns);
  }

  /** Returns whether two column-name lists contain the same names. */
  private static function sameNameSet(array $a, array $b): bool {
    sort($a);
    sort($b);
    return $a === $b;
  }

  /** Detects the single INTEGER PRIMARY KEY AUTOINCREMENT column from CREATE SQL. */
  private static function autoIncrementColumnName(string $createSql, array $columns) {
    if (stripos($createSql, 'AUTOINCREMENT') === false) {
      return false;
    }
    foreach ($columns as $column) {
      $name = trim((string)($column['COLUMN_NAME'] ?? ''));
      $type = self::normalizeSQLiteType((string)($column['COLUMN_TYPE'] ?? ''));
      if ($name !== '' && ($column['COLUMN_KEY'] ?? '') === 'PRI' && $type === 'INTEGER') {
        return $name;
      }
    }
    return false;
  }

  /** Returns cache keys affected by creating one SQLite table. */
  private static function cacheKeys(string $schema, string $table, string|false $originalTable = false): array {
    $tables = [$table];
    if ($originalTable !== false && $originalTable !== $table) {
      $tables[] = $originalTable;
    }
    $keys = ['TableList:' . $schema];
    foreach (array_unique($tables) as $name) {
      $keys[] = 'TableDefinition:' . $schema . ':' . $name;
      $keys[] = 'TableFields:' . $schema . ':' . $name;
      $keys[] = 'TableReferencedBy:' . $schema . ':' . $name;
    }
    return $keys;
  }

  /** Returns plain text from SPTK values. */
  private static function textValue($value): string {
    if (is_array($value)) {
      return implode("\n", $value);
    }
    return (string)$value;
  }

}
