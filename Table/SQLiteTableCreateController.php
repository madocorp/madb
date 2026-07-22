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
  private static int|false $editingColumn = false;
  private static int|false $addingColumn = false;
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
    self::$columns = [];
    self::$editingColumn = false;
    self::$addingColumn = false;
    $panel = self::panel();
    if ($panel === false) {
      return;
    }
    self::setTitle('Create SQLite table in ' . self::$schema);
    $panel->setValue([
      'sqlite-table-name' => ''
    ]);
    self::setColumns();
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
    self::$columns = [];
    self::$editingColumn = false;
    self::$addingColumn = false;
    $panel = self::panel();
    if ($panel === false) {
      return;
    }
    self::setTitle('Modify SQLite table ' . self::$schema . '.' . self::$table);
    $panel->setValue([
      'sqlite-table-name' => self::$table
    ]);
    self::setColumnPlaceholder('Loading...');
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
    self::$columns = self::$originalColumns;
    $panel = self::panel();
    if ($panel !== false) {
      self::setTitle('Modify SQLite table ' . self::$schema . '.' . self::$originalTable);
      $panel->setValue([
        'sqlite-table-name' => self::$originalTable
      ]);
    }
    self::setColumns();
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
    self::$columns[self::$editingColumn] = $column;
    self::$addingColumn = false;
    self::setColumns();
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
    array_splice(self::$columns, (int)$index, 1);
    self::setColumns();
    \SPTK\Element::refresh();
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
    $table = trim(self::textValue($values['sqlite-table-name'] ?? ''));
    if ($table === '') {
      \SPTK\Elements\WarningPanel::forge('No object name!', 'Please enter an object name before saving.');
      return;
    }
    self::syncColumnOrderFromList();
    try {
      if (self::$mode === 'modify') {
        $sql = self::buildAlterSql(self::$schema, (string)self::$originalTable, $table, self::$originalColumns, self::$columns);
      } else {
        $sql = self::buildCreateSql(self::$schema, $table, self::$columns);
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
  public static function buildCreateSql(string $schema, string $table, array $columns): string {
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
    return 'CREATE TABLE ' . self::quoteQualifiedName($schema, $table) . " (\n  " . implode(",\n  ", $definitions) . "\n);";
  }

  /** Builds native SQLite ALTER TABLE SQL from normalized old and new table state. */
  public static function buildAlterSql(string $schema, string $oldTable, string $newTable, array $originalColumns, array $columns): string {
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
