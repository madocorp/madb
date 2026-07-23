<?php

namespace MADB\Table;

/** Creates query workspace templates for selecting rows and showing CREATE SQL from the selected table. */
trait MenuRowsTrait {

  /** Selects rows and refreshes related table menu state. */
  public static function selectRows() {
    if (self::$currentSchema === false) {
      \SPTK\Elements\WarningPanel::forge('No ' . self::schemaLabel() . ' selected!', 'Please select a ' . self::schemaLabel() . ' before preforming this operation.');
      return;
    }
    if (self::$currentTable === false) {
      \SPTK\Elements\WarningPanel::forge('No table selected!', 'Please select a table before preforming this operation.');
      return;
    }
    $connection = \MADB\Connection\ConnectionList::getInstance()->current;
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    self::openSelectRowsQuery([
      'connection' => $connection,
      'schema' => self::$currentSchema,
      'table' => self::$currentTable
    ]);
  }

  /** Selects rows and refreshes related table menu state. */
  public static function selectedRows($response) {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not inspect table', $response['result']);
      return;
    }
    self::openSelectRowsQuery($response);
  }

  /** Opens the generated SELECT query for a table. */
  private static function openSelectRowsQuery(array $response): void {
    $schema = $response['schema'];
    $table = $response['table'];
    $name = 'SELECT ' . $schema . '.' . $table;
    $sql = \MADB\Query\SqlFormatter\SqlFormatter::format(
      'SELECT *' . "\nFROM " . self::quoteQualifiedTable($schema, $table) . "\nLIMIT 1000;"
    );
    \MADB\Query\GeneratedQueryController::open([
      'title' => 'Select rows',
      'name' => $name,
      'sql' => $sql,
      'connection' => $response['connection'],
      'schema' => $schema,
      'table' => $table,
      'expectsResult' => true
    ]);
  }

  /** Opens the insert-row panel for the active table result. */
  public static function insertRow() {
    $resultContext = \MADB\Main\ScreenController::activeResultTableContext();
    if ($resultContext === false) {
      \SPTK\Elements\WarningPanel::forge('No active table result', 'Please activate a result that belongs to one table before inserting a row.');
      return;
    }
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    $connection = $connectionList->current;
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    if (!\MADB\Connection\MenuController::requireOperation('rowInsert', 'Inserting rows from the row editor', $connection)) {
      return;
    }
    $jobId = \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'rowEditorDefinition',
      'arguments' => [$resultContext['schema'], $resultContext['table']],
      'callback' => ['\MADB\Table\RowsController', 'openInsertRow'],
      'schema' => $resultContext['schema'],
      'table' => $resultContext['table'],
      'cache' => 'RowEditorDefinition:' . $resultContext['schema'] . ':' . $resultContext['table']
    ]);
    if ($jobId !== -1) {
      self::showRowMetadataProgress('Insert row', $resultContext['schema'], $resultContext['table']);
    }
  }

  /** Opens the row update panel for the active result row and column. */
  public static function updateRow() {
    $rowContext = \MADB\Main\ScreenController::activeResultRowContext();
    if ($rowContext === false) {
      \SPTK\Elements\WarningPanel::forge('No active table row', 'Please activate a result row that belongs to one table before updating a field.');
      return;
    }
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    $connection = $connectionList->current;
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    if (!\MADB\Connection\MenuController::requireOperation('rowUpdate', 'Updating rows from the row editor', $connection)) {
      return;
    }
    $jobId = \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'rowEditorDefinition',
      'arguments' => [$rowContext['schema'], $rowContext['table']],
      'callback' => ['\MADB\Table\RowsController', 'openUpdateRow'],
      'schema' => $rowContext['schema'],
      'table' => $rowContext['table'],
      'rowContext' => $rowContext,
      'cache' => 'RowEditorDefinition:' . $rowContext['schema'] . ':' . $rowContext['table']
    ]);
    if ($jobId !== -1) {
      self::showRowMetadataProgress('Update row', $rowContext['schema'], $rowContext['table']);
    }
  }

  /** Opens the delete preview panel for the selected result rows. */
  public static function deleteRows() {
    $deleteContext = \MADB\Main\ScreenController::activeResultRowsContext();
    if ($deleteContext === false) {
      \SPTK\Elements\WarningPanel::forge('No selected table rows', 'Please select result row(s) that belong to one table before deleting.');
      return;
    }
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    $connection = $connectionList->current;
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    if (!\MADB\Connection\MenuController::requireOperation('rowDelete', 'Deleting rows from the row editor', $connection)) {
      return;
    }
    $jobId = \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'rowEditorDefinition',
      'arguments' => [$deleteContext['schema'], $deleteContext['table']],
      'callback' => ['\MADB\Table\RowsController', 'openDeleteRows'],
      'schema' => $deleteContext['schema'],
      'table' => $deleteContext['table'],
      'deleteContext' => $deleteContext,
      'cache' => 'RowEditorDefinition:' . $deleteContext['schema'] . ':' . $deleteContext['table']
    ]);
    if ($jobId !== -1) {
      self::showRowMetadataProgress('Delete rows', $deleteContext['schema'], $deleteContext['table']);
    }
  }

  /** Builds and shows the insert-row panel after table metadata loads. */
  public static function openInsertRow($response): void {
    self::removePanelByName('table-row-metadata-progress');
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not inspect table', $response['result']);
      return;
    }
    $schema = $response['schema'];
    $table = $response['table'];
    $definition = $response['result'];
    $columns = $definition['columns'] ?? [];
    $primaryColumns = self::primaryColumnNames($columns);
    if (empty($primaryColumns)) {
      \SPTK\Elements\WarningPanel::forge('No primary key', "Table '{$schema}.{$table}' does not have a primary key.");
      return;
    }
    $resultContext = \MADB\Main\ScreenController::activeTableResultContext($schema, $table);
    if ($resultContext === false) {
      \SPTK\Elements\WarningPanel::forge('No active table result', 'Please keep the table result active before inserting a row.');
      return;
    }
    $missing = array_values(array_filter($primaryColumns, fn($column) => !in_array($column, $resultContext['columns'], true)));
    if (!empty($missing)) {
      \SPTK\Elements\WarningPanel::forge('Primary key not selected', 'The active result must include primary key field(s): ' . implode(', ', $missing));
      return;
    }

    self::$insertState = [
      'connection' => $response['connection'],
      'schema' => $schema,
      'table' => $table,
      'columns' => array_values($columns),
      'values' => [],
      'nulls' => [],
      'originalValues' => [],
      'originalNulls' => [],
      'activeColumnIndex' => 0,
      'syncingFieldList' => false,
      'primaryColumns' => $primaryColumns,
      'resultContext' => $resultContext,
      'mode' => 'insert'
    ];
    self::showInsertPanel($schema, $table, $columns);
  }

  /** Builds and shows the update-row panel after table metadata loads. */
  public static function openUpdateRow($response): void {
    self::removePanelByName('table-row-metadata-progress');
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not inspect table', $response['result']);
      return;
    }
    $schema = $response['schema'];
    $table = $response['table'];
    $definition = $response['result'];
    $columns = array_values($definition['columns'] ?? []);
    $primaryColumns = self::primaryColumnNames($columns);
    if (empty($primaryColumns)) {
      \SPTK\Elements\WarningPanel::forge('No primary key', "Table '{$schema}.{$table}' does not have a primary key.");
      return;
    }
    $rowContext = $response['rowContext'] ?? false;
    if (!is_array($rowContext) || ($rowContext['schema'] ?? '') !== $schema || ($rowContext['table'] ?? '') !== $table) {
      \SPTK\Elements\WarningPanel::forge('No active table row', 'Please activate a result row that belongs to this table before updating a field.');
      return;
    }
    $resultColumns = array_values($rowContext['columns'] ?? []);
    $missing = array_values(array_filter($primaryColumns, fn($column) => !in_array($column, $resultColumns, true)));
    if (!empty($missing)) {
      \SPTK\Elements\WarningPanel::forge('Primary key not selected', 'The active result must include primary key field(s): ' . implode(', ', $missing));
      return;
    }

    $row = $rowContext['row'] ?? [];
    $values = [];
    $nulls = [];
    foreach ($columns as $index => $column) {
      $name = (string)($column['COLUMN_NAME'] ?? '');
      $resultIndex = array_search($name, $resultColumns, true);
      $value = $resultIndex === false ? '' : ($row[$resultIndex] ?? null);
      $values[$index] = $value === null ? '' : (string)$value;
      $nulls[$index] = $value === null;
    }
    $activeColumnIndex = max(0, self::columnIndexByName($columns, (string)($rowContext['field'] ?? '')));
    self::$insertState = [
      'connection' => $response['connection'],
      'schema' => $schema,
      'table' => $table,
      'columns' => $columns,
      'values' => $values,
      'nulls' => $nulls,
      'originalValues' => $values,
      'originalNulls' => $nulls,
      'activeColumnIndex' => $activeColumnIndex,
      'syncingFieldList' => false,
      'primaryColumns' => $primaryColumns,
      'resultContext' => $rowContext,
      'mode' => 'update'
    ];
    self::showInsertPanel($schema, $table, $columns, $activeColumnIndex, true);
  }

  /** Builds and shows the delete preview panel after table metadata loads. */
  public static function openDeleteRows($response): void {
    self::removePanelByName('table-row-metadata-progress');
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not inspect table', $response['result']);
      return;
    }
    $schema = $response['schema'];
    $table = $response['table'];
    $definition = $response['result'];
    $columns = array_values($definition['columns'] ?? []);
    $primaryColumns = self::primaryColumnNames($columns);
    if (empty($primaryColumns)) {
      \SPTK\Elements\WarningPanel::forge('No primary key', "Table '{$schema}.{$table}' does not have a primary key.");
      return;
    }
    $deleteContext = $response['deleteContext'] ?? false;
    if (!is_array($deleteContext) || ($deleteContext['schema'] ?? '') !== $schema || ($deleteContext['table'] ?? '') !== $table) {
      \SPTK\Elements\WarningPanel::forge('No selected table rows', 'Please select result row(s) that belong to this table before deleting.');
      return;
    }
    $pkRows = self::primaryKeyRowsFromResult($deleteContext, $primaryColumns);
    if ($pkRows === false) {
      return;
    }
    self::$deleteState = [
      'connection' => $response['connection'],
      'schema' => $schema,
      'table' => $table,
      'primaryRows' => $pkRows,
      'resultContext' => $deleteContext
    ];
    self::openGeneratedRowQuery('Delete row(s)', self::formattedDeleteSql($pkRows), self::$deleteState);
  }


  /** Saves the insert-row panel values. */
  public static function saveInsertRow($panel): void {
    if (empty(self::$insertState)) {
      \SPTK\Elements\WarningPanel::forge('Insert is not ready', 'Please open the insert panel again.');
      return;
    }
    $values = self::insertValuesFromPanel($panel);
    if ($values === false) {
      return;
    }
    self::openGeneratedRowQuery('Insert row', self::formattedInsertSql($values), self::$insertState);
  }

  /** Saves updates from the row update panel. */
  public static function saveUpdateRow($panel): void {
    if (empty(self::$insertState)) {
      \SPTK\Elements\WarningPanel::forge('Update is not ready', 'Please open the update panel again.');
      return;
    }
    $changes = self::updateChangesFromPanel($panel);
    if ($changes === false) {
      return;
    }
    $where = self::updateWhereValues();
    if ($where === false) {
      return;
    }
    self::openGeneratedRowQuery('Update row', self::formattedUpdateSql($changes, $where), self::$insertState);
  }

  /** Executes the delete statement from the delete preview panel. */
  public static function saveDeleteRows($panel): void {
    if (empty(self::$deleteState)) {
      \SPTK\Elements\WarningPanel::forge('Delete is not ready', 'Please open the delete preview again.');
      return;
    }
    self::openGeneratedRowQuery('Delete row(s)', self::formattedDeleteSql(self::$deleteState['primaryRows']), self::$deleteState);
  }

  /** Shows insert status after the background insert finishes. */
  public static function insertRowSaved($response): void {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not insert row', $response['result']);
      return;
    }
    self::removePanelByName('table-insert');
    $result = is_array($response['result']) ? $response['result'] : [];
    $lines = [
      'Inserted row into ' . $response['schema'] . '.' . $response['table'] . '.',
      'Affected rows: ' . (int)($result['affectedRows'] ?? 0)
    ];
    if (($result['lastInsertId'] ?? '') !== '' && (string)($result['lastInsertId'] ?? '0') !== '0') {
      $lines[] = 'Last insert id: ' . $result['lastInsertId'];
    }
    self::showInsertSuccessPanel(implode("\n", $lines));
  }

  /** Shows update status after the background update finishes. */
  public static function updateRowSaved($response): void {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not update row', $response['result']);
      return;
    }
    self::removePanelByName('table-insert');
    $result = is_array($response['result']) ? $response['result'] : [];
    $lines = [
      'Updated row in ' . $response['schema'] . '.' . $response['table'] . '.',
      'Affected rows: ' . (int)($result['affectedRows'] ?? 0)
    ];
    self::showInsertSuccessPanel(implode("\n", $lines));
  }

  /** Shows delete status after the background delete finishes. */
  public static function deleteRowsSaved($response): void {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not delete rows', $response['result']);
      return;
    }
    self::removePanelByName('table-delete-preview');
    self::$deleteState = [];
    $result = is_array($response['result']) ? $response['result'] : [];
    $lines = [
      'Deleted row(s) from ' . $response['schema'] . '.' . $response['table'] . '.',
      'Affected rows: ' . (int)($result['affectedRows'] ?? 0)
    ];
    self::showInsertSuccessPanel(implode("\n", $lines));
  }

  /** Closes the insert panel without saving. */
  public static function closeInsertPanel($panel): void {
    self::removePanelByName('table-insert-field-editor');
    $panel->remove();
    \MADB\Main\ScreenController::restoreFocusAfterPanelClose();
    \SPTK\Element::refresh();
  }

  /** Closes the insert success panel. */
  public static function closeInsertSuccess($panel): void {
    $panel->remove();
    \MADB\Main\ScreenController::restoreFocusAfterPanelClose();
    \SPTK\Element::refresh();
  }

  /** Shows the formatted INSERT statement generated from the current insert panel. */
  public static function previewInsertRow($panel): void {
    if (empty(self::$insertState)) {
      \SPTK\Elements\WarningPanel::forge('Insert is not ready', 'Please open the insert panel again.');
      return;
    }
    if ((self::$insertState['mode'] ?? 'insert') === 'update') {
      $changes = self::updateChangesFromPanel($panel);
      if ($changes === false) {
        return;
      }
      $where = self::updateWhereValues();
      if ($where === false) {
        return;
      }
      self::openGeneratedRowQuery('Update row', self::formattedUpdateSql($changes, $where), self::$insertState);
    } else {
      $values = self::insertValuesFromPanel($panel);
      if ($values === false) {
        return;
      }
      self::openGeneratedRowQuery('Insert row', self::formattedInsertSql($values), self::$insertState);
    }
  }

  /** Closes the insert preview panel. */
  public static function closeInsertPreview($panel): void {
    $panel->remove();
    \MADB\Main\ScreenController::restoreFocusAfterPanelClose();
    \SPTK\Element::refresh();
  }

  /** Closes the delete preview panel. */
  public static function closeDeletePreview($panel): void {
    self::$deleteState = [];
    $panel->remove();
    \MADB\Main\ScreenController::restoreFocusAfterPanelClose();
    \SPTK\Element::refresh();
  }

  /** Refreshes the active query after an insert succeeds. */
  public static function refreshAfterInsert($panel): void {
    $panel->remove();
    \MADB\Main\ScreenController::restoreFocusAfterPanelClose();
    \MADB\Query\QueryExecutionController::executeQuery();
  }

  /** Coordinates show rows work in the table menu. */
  public static function showRows() {
    if (self::$currentSchema === false) {
      \SPTK\Elements\WarningPanel::forge('No ' . self::schemaLabel() . ' selected!', 'Please select a ' . self::schemaLabel() . ' before preforming this operation.');
      return;
    }
    if (self::$currentTable === false) {
      \SPTK\Elements\WarningPanel::forge('No table selected!', 'Please select a table before preforming this operation.');
      return;
    }
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    $connection = $connectionList->current;
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    $name = 'SHOW ' . self::$currentSchema . '.' . self::$currentTable;
    \MADB\Main\ScreenController::addTemplateQuery('SELECT all', $name, $connection['name'], self::$currentSchema, self::$currentTable);
    \MADB\Query\QueryExecutionController::executeQuery();
  }

  /** Coordinates show create work in the table menu. */
  public static function showCreate() {
    if (self::$currentSchema === false) {
      \SPTK\Elements\WarningPanel::forge('No ' . self::schemaLabel() . ' selected!', 'Please select a ' . self::schemaLabel() . ' before preforming this operation.');
      return;
    }
    if (self::$currentTable === false) {
      \SPTK\Elements\WarningPanel::forge('No table selected!', 'Please select a table before preforming this operation.');
      return;
    }
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    $connection = $connectionList->current;
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'showCreateTable',
      'arguments' => [self::$currentSchema, self::$currentTable],
      'callback' => ['\MADB\Table\MenuController', 'showCreated'],
      'schema' => self::$currentSchema,
      'table' => self::$currentTable
    ]);
  }

  /** Coordinates show created work in the table menu. */
  public static function showCreated($response) {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not get SHOW CREATE TABLE', $response['result']);
      return;
    }
    $result = is_array($response['result']) ? $response['result'] : [];
    $createSql = $result['sql'] ?? false;
    if ($createSql === false || trim((string)$createSql) === '') {
      \SPTK\Elements\ErrorPanel::forge('Could not get SHOW CREATE TABLE', 'The query result did not contain a CREATE statement.');
      return;
    }
    $createSql = \MADB\Query\SqlFormatter\SqlFormatter::format($createSql);
    $schema = $response['schema'];
    $table = $response['table'];
    $name = 'CREATE ' . $schema . '.' . $table;
    \MADB\Query\GeneratedQueryController::open([
      'title' => 'Show create',
      'name' => $name,
      'sql' => $createSql,
      'connection' => $response['connection'],
      'schema' => $schema,
      'table' => $table,
      'cacheKeys' => self::tableCacheKeys($schema, [$table]),
      'refresh' => 'tables',
      'primaryAction' => 'copy'
    ]);
  }

  /** Returns primary-key column names from table definition columns. */
  private static function primaryColumnNames(array $columns): array {
    $primary = [];
    foreach ($columns as $column) {
      if (($column['COLUMN_KEY'] ?? '') === 'PRI') {
        $primary[] = (string)($column['COLUMN_NAME'] ?? '');
      }
    }
    return array_values(array_filter($primary, fn($name) => $name !== ''));
  }

  /** Builds the row editor field-list panel. */
  private static function showInsertPanel(string $schema, string $table, array $columns, int $activeColumnIndex = 0, bool $openFieldEditor = false): void {
    self::removePanelByName('table-insert');
    self::removePanelByName('table-insert-field-editor');
    $window = \SPTK\Element::firstByType('Window');
    if ($window === false) {
      \SPTK\Elements\ErrorPanel::forge('Could not open row panel', 'No application window was found.');
      return;
    }
    $mode = self::$insertState['mode'] ?? 'insert';
    $actionText = $mode === 'update' ? 'Update' : 'Insert';
    $saveCallback = $mode === 'update'
      ? 'MADB\Table\RowsController::saveUpdateRow'
      : 'MADB\Table\RowsController::saveInsertRow';
    $panel = new \SPTK\Elements\Panel($window, 'table-insert');
    $panel->addEvent('KeyPress', ['\MADB\Table\RowsController', 'insertPanelKeyPress']);
    $title = new \SPTK\Element($panel, null, null, 'PanelTitle');
    $title->addText($actionText . ' row: ' . $schema . '.' . $table);
    $content = new \SPTK\Element($panel, 'table-insert-content', null, 'PanelContent');
    $fields = new \SPTK\Elements\ListBox($content, 'table-insert-fields');
    $fields->setOnChange(['\MADB\Table\RowsController', 'selectInsertField']);
    $fields->setOnSelect(['\MADB\Table\RowsController', 'openInsertFieldEditor']);
    foreach (array_values($columns) as $index => $column) {
      self::addInsertFieldItem($fields, $index, $column);
    }
    $buttons = new \SPTK\Element($content, 'table-insert-buttons', null, 'ButtonBox');
    self::addPanelButton($buttons, 'RETURN', $saveCallback, $actionText, 'table-insert-save');
    new \SPTK\Elements\Space($buttons);
    self::addPanelButton($buttons, 'ESCAPE', 'MADB\Table\RowsController::closeInsertPanel', 'Cancel');
    self::$insertState['syncingFieldList'] = true;
    $fields->moveCursor($activeColumnIndex);
    self::$insertState['syncingFieldList'] = false;
    $panel->show();
    if (method_exists($panel, 'activateInput')) {
      $panel->activateInput('table-insert-fields');
    }
    if ($openFieldEditor) {
      self::showInsertFieldEditor($activeColumnIndex);
    }
    \SPTK\Element::refresh();
  }

  /** Handles row-editor list panel keys. */
  public static function insertPanelKeyPress($panel, $event): bool {
    $action = \SPTK\SDLWrapper\KeyCombo::resolve($event['mod'], $event['scancode'], $event['key']);
    if ($action === \SPTK\SDLWrapper\Action::DO_IT || $action === \SPTK\SDLWrapper\Action::SELECT_ITEM) {
      $fields = \SPTK\Element::byName('table-insert-fields', $panel);
      if ($fields !== false && $fields->hasVariant('active')) {
        self::openInsertFieldEditor($fields->getActive());
        return true;
      }
    }
    return $panel->keyPressHandler($panel, $event);
  }

  /** Adds one table-column item to the insert panel field list. */
  private static function addInsertFieldItem($parent, int $index, array $column): void {
    $parent->addItem([
      'value' => (string)$index,
      'text' => self::insertFieldListName($index),
      'right' => self::insertFieldListValue($index),
      'rightAlign' => 'left',
      'classes' => ['table-insert-list-value']
    ]);
  }

  /** Tracks the active row-editor field list item. */
  public static function selectInsertField($list): void {
    $panel = \SPTK\Element::byName('table-insert');
    if ($panel === false || empty(self::$insertState)) {
      return;
    }
    if (!empty(self::$insertState['syncingFieldList'])) {
      return;
    }
    $listFocused = $list->hasVariant('active');
    if (!$listFocused) {
      self::syncInsertFieldList();
    }
    $active = $list->getActive();
    $index = $active === false ? 0 : (int)$active->getValue();
    self::$insertState['activeColumnIndex'] = $index;
    if ($listFocused) {
      return;
    }
    self::$insertState['syncingFieldList'] = true;
    $panel->refreshInputList($list);
    self::$insertState['syncingFieldList'] = false;
    \SPTK\Element::refresh();
  }

  /** Opens the field editor panel for a selected row-editor field. */
  public static function openInsertFieldEditor($item = null): void {
    if ($item !== null && $item !== false && method_exists($item, 'getValue')) {
      self::$insertState['activeColumnIndex'] = (int)$item->getValue();
    }
    self::showInsertFieldEditor((int)(self::$insertState['activeColumnIndex'] ?? 0));
  }

  /** Builds the separate row-editor field panel. */
  private static function showInsertFieldEditor(int $index): void {
    if (empty(self::$insertState) || !isset(self::$insertState['columns'][$index])) {
      return;
    }
    self::removePanelByName('table-insert-field-editor');
    $window = \SPTK\Element::firstByType('Window');
    if ($window === false) {
      return;
    }
    self::$insertState['activeColumnIndex'] = $index;
    $column = self::$insertState['columns'][$index];
    $name = (string)($column['COLUMN_NAME'] ?? '');
    $nullable = strtoupper((string)($column['IS_NULLABLE'] ?? '')) === 'YES';

    $panel = new \SPTK\Elements\Panel($window, 'table-insert-field-editor');
    $panel->addEvent('KeyPress', ['\MADB\Table\RowsController', 'insertFieldPanelKeyPress']);
    $title = new \SPTK\Element($panel, null, null, 'PanelTitle');
    $title->addText('Field editor');
    $content = new \SPTK\Element($panel, 'table-insert-field-content', null, 'PanelContent');

    $nameLabel = new \SPTK\Element($content, null, null, 'Label');
    $nameLabel->addText('Name: ');
    $nameField = new \SPTK\Elements\Field($nameLabel, 'table-insert-field-name');
    $nameField->setValue($name);

    $typeLabel = new \SPTK\Element($content, null, null, 'Label');
    $typeLabel->addText('Type: ');
    $typeField = new \SPTK\Elements\Field($typeLabel, 'table-insert-field-type');
    $typeField->setValue(self::insertFieldDefinition($column));

    $valueLabel = new \SPTK\Element($content, null, 'table-insert-field-label', 'Label');
    $valueLabel->addText('Value:');
    new \SPTK\Element($valueLabel, null, null, 'NL');
    if (self::isLongTextColumn($column)) {
      $input = new \SPTK\Elements\TextEditor($valueLabel, 'table-insert-field', 'table-insert-text');
    } else {
      $input = new \SPTK\Elements\Input($valueLabel, 'table-insert-field', 'table-insert-input');
    }
    $input->addEvent('KeyPress', ['\MADB\Table\RowsController', 'insertFieldInputKeyPress']);
    $input->setValue(self::$insertState['values'][$index] ?? '');

    if ($nullable) {
      $null = new \SPTK\Elements\CheckBox($content, 'table-insert-null');
      $null->setValue((bool)(self::$insertState['nulls'][$index] ?? false));
      $null->addText('NULL');
    }

    $buttons = new \SPTK\Element($content, null, null, 'ButtonBox');
    self::addPanelButton($buttons, 'RETURN', 'MADB\Table\RowsController::saveInsertFieldEditor', 'OK', 'table-insert-field-save');
    new \SPTK\Elements\Space($buttons);
    self::addPanelButton($buttons, 'ESCAPE', 'MADB\Table\RowsController::closeInsertFieldEditor', 'Cancel');
    $panel->show();
    if (method_exists($panel, 'activateInput')) {
      $panel->activateInput('table-insert-field');
    }
    \SPTK\Element::refresh();
  }

  /** Handles explicit Return/Esc behavior in the field editor panel. */
  public static function insertFieldPanelKeyPress($panel, $event): bool {
    $action = \SPTK\SDLWrapper\KeyCombo::resolve($event['mod'], $event['scancode'], $event['key']);
    if ($action === \SPTK\SDLWrapper\Action::DO_IT) {
      self::saveInsertFieldEditor($panel);
      return true;
    }
    if ($action === \SPTK\SDLWrapper\Action::CLOSE) {
      self::closeInsertFieldEditor($panel);
      return true;
    }
    return $panel->keyPressHandler($panel, $event);
  }

  /** Handles Return/Esc before the active value control consumes them. */
  public static function insertFieldInputKeyPress($input, $event): bool {
    $action = \SPTK\SDLWrapper\KeyCombo::resolve($event['mod'], $event['scancode'], $event['key']);
    if ($action === \SPTK\SDLWrapper\Action::DO_IT) {
      $panel = \SPTK\Element::byName('table-insert-field-editor');
      if ($panel !== false) {
        self::saveInsertFieldEditor($panel);
        return true;
      }
    }
    if ($action === \SPTK\SDLWrapper\Action::CLOSE) {
      $panel = \SPTK\Element::byName('table-insert-field-editor');
      if ($panel !== false) {
        self::closeInsertFieldEditor($panel);
        return true;
      }
    }
    return $input->keyPressHandler($input, $event);
  }

  /** Saves the field editor value into row-editor memory. */
  public static function saveInsertFieldEditor($panel): void {
    self::saveVisibleInsertField($panel);
    $panel->remove();
    self::syncInsertFieldList();
    $listPanel = \SPTK\Element::byName('table-insert');
    if ($listPanel !== false && method_exists($listPanel, 'activateInput')) {
      $listPanel->activateInput('table-insert-fields');
    }
    \SPTK\Element::refresh();
  }

  /** Closes the field editor without changing row-editor memory. */
  public static function closeInsertFieldEditor($panel): void {
    $panel->remove();
    $listPanel = \SPTK\Element::byName('table-insert');
    if ($listPanel !== false && method_exists($listPanel, 'activateInput')) {
      $listPanel->activateInput('table-insert-fields');
    }
    \SPTK\Element::refresh();
  }

  /** Saves the currently visible field editor into insert state. */
  private static function saveVisibleInsertField($panel): void {
    if (empty(self::$insertState)) {
      return;
    }
    $index = (int)(self::$insertState['activeColumnIndex'] ?? 0);
    if (!isset(self::$insertState['columns'][$index])) {
      return;
    }
    $input = \SPTK\Element::byName('table-insert-field', $panel);
    $null = \SPTK\Element::byName('table-insert-null', $panel);
    if ($input === false && $null === false) {
      return;
    }
    if ($input !== false) {
      self::$insertState['values'][$index] = self::textValue($input->getValue());
    }
    if ($null !== false) {
      self::$insertState['nulls'][$index] = $null->getValue() === true;
    } else {
      self::$insertState['nulls'][$index] = false;
    }
    self::syncInsertFieldList();
  }

  /** Refreshes insert field list value previews from current insert state. */
  private static function syncInsertFieldList(): void {
    $list = \SPTK\Element::byName('table-insert-fields');
    if ($list === false || empty(self::$insertState)) {
      return;
    }
    foreach ($list->getItems() as $item) {
      $index = (int)$item->getValue();
      if (isset(self::$insertState['columns'][$index]) && method_exists($item, 'setRight')) {
        $item->setRight(self::insertFieldListValue($index));
      }
    }
  }

  /** Returns the padded row-editor field name so value previews start after the widest name plus two spaces. */
  private static function insertFieldListName(int $index): string {
    $name = (string)(self::$insertState['columns'][$index]['COLUMN_NAME'] ?? '');
    $spaces = max(2, self::insertFieldNameWidth() - mb_strlen($name) + 2);
    return $name . str_repeat(' ', $spaces);
  }

  /** Returns the widest row-editor field name. */
  private static function insertFieldNameWidth(): int {
    $width = 0;
    foreach ((array)(self::$insertState['columns'] ?? []) as $column) {
      $width = max($width, mb_strlen((string)($column['COLUMN_NAME'] ?? '')));
    }
    return $width;
  }

  /** Returns the compact value preview for one insert field list row. */
  private static function insertFieldListValue(int $index): string {
    if ((self::$insertState['nulls'][$index] ?? false) === true) {
      return 'NULL';
    }
    return self::shortInsertFieldValue(self::textValue(self::$insertState['values'][$index] ?? ''));
  }

  /** Shortens a value for the insert field list using the UI truncation marker. */
  private static function shortInsertFieldValue(string $value): string {
    $value = str_replace(["\r\n", "\r", "\n", "\t"], [' ', ' ', ' ', ' '], $value);
    if (strlen($value) <= 60) {
      return $value;
    }
    return substr($value, 0, 59) . '~';
  }

  /** Formats the field definition shown beside the insert field name. */
  private static function insertFieldDefinition(array $column): string {
    $parts = [(string)($column['COLUMN_TYPE'] ?? '')];
    if (($column['IS_NULLABLE'] ?? '') === 'NO') {
      $parts[] = 'NOT NULL';
    }
    if (($column['COLUMN_DEFAULT'] ?? null) !== null) {
      $parts[] = 'DEFAULT ' . (string)$column['COLUMN_DEFAULT'];
    }
    if (($column['EXTRA'] ?? '') !== '') {
      $parts[] = (string)$column['EXTRA'];
    }
    return trim(implode(' ', array_filter($parts, fn($part) => $part !== '')));
  }

  /** Adds a hotkey button to a dynamic panel. */
  private static function addPanelButton($parent, string $hotKey, string $callback, string $text, string $name = null): void {
    $button = new \SPTK\Elements\Button($parent, $name);
    $button->setHotKey($hotKey);
    $button->setOnPress($callback);
    $button->addText($text);
  }

  /** Collects and validates insert values from the dynamic insert panel. */
  private static function insertValuesFromPanel($panel) {
    self::saveVisibleInsertField($panel);
    $values = [];
    foreach (self::$insertState['columns'] as $index => $column) {
      $name = (string)($column['COLUMN_NAME'] ?? '');
      if ((self::$insertState['nulls'][$index] ?? false) === true) {
        $values[$name] = null;
        continue;
      }
      $value = self::textValue(self::$insertState['values'][$index] ?? '');
      if ($value === '') {
        if (self::canOmitInsertColumn($column)) {
          continue;
        }
        \SPTK\Elements\WarningPanel::forge('Missing value', "Field '{$name}' is required and has no default.");
        return false;
      }
      $values[$name] = $value;
    }
    return $values;
  }

  /** Collects changed update values from the dynamic row panel. */
  private static function updateChangesFromPanel($panel) {
    self::saveVisibleInsertField($panel);
    $changes = [];
    foreach (self::$insertState['columns'] as $index => $column) {
      $name = (string)($column['COLUMN_NAME'] ?? '');
      $isNull = (self::$insertState['nulls'][$index] ?? false) === true;
      $value = $isNull ? null : self::textValue(self::$insertState['values'][$index] ?? '');
      $originalNull = (self::$insertState['originalNulls'][$index] ?? false) === true;
      $originalValue = $originalNull ? null : self::textValue(self::$insertState['originalValues'][$index] ?? '');
      if ($value !== $originalValue) {
        $changes[$name] = $value;
      }
    }
    if (empty($changes)) {
      \SPTK\Elements\WarningPanel::forge('No changes', 'No field values were changed.');
      return false;
    }
    return $changes;
  }

  /** Builds primary-key WHERE values from the original active result row. */
  private static function updateWhereValues() {
    $where = [];
    $primaryColumns = self::$insertState['primaryColumns'] ?? [];
    foreach ($primaryColumns as $primaryColumn) {
      $index = self::columnIndexByName(self::$insertState['columns'], $primaryColumn);
      if ($index < 0) {
        \SPTK\Elements\WarningPanel::forge('Primary key not available', "Primary key field '{$primaryColumn}' is not available.");
        return false;
      }
      $where[$primaryColumn] = (self::$insertState['originalNulls'][$index] ?? false) === true
        ? null
        : self::textValue(self::$insertState['originalValues'][$index] ?? '');
    }
    return $where;
  }

  /** Builds primary-key rows from selected result rows. */
  private static function primaryKeyRowsFromResult(array $deleteContext, array $primaryColumns) {
    $resultColumns = array_values($deleteContext['columns'] ?? []);
    $missing = array_values(array_filter($primaryColumns, fn($column) => !in_array($column, $resultColumns, true)));
    if (!empty($missing)) {
      \SPTK\Elements\WarningPanel::forge('Primary key not selected', 'The active result must include primary key field(s): ' . implode(', ', $missing));
      return false;
    }

    $rows = [];
    $seen = [];
    foreach (($deleteContext['rows'] ?? []) as $row) {
      if (!is_array($row)) {
        continue;
      }
      $primaryRow = [];
      foreach ($primaryColumns as $primaryColumn) {
        $index = array_search($primaryColumn, $resultColumns, true);
        $primaryRow[$primaryColumn] = $index === false ? null : ($row[$index] ?? null);
      }
      $key = serialize($primaryRow);
      if (!isset($seen[$key])) {
        $rows[] = $primaryRow;
        $seen[$key] = true;
      }
    }
    if (empty($rows)) {
      \SPTK\Elements\WarningPanel::forge('No selected table rows', 'Please select result row(s) that belong to this table before deleting.');
      return false;
    }
    return $rows;
  }

  /** Builds a readable INSERT statement for preview. */
  private static function formattedInsertSql(array $values): string {
    $schema = self::quoteIdentifier(self::$insertState['schema']);
    $table = self::quoteIdentifier(self::$insertState['table']);
    if (empty($values)) {
      return \MADB\Query\SqlFormatter\SqlFormatter::format("INSERT INTO {$schema}.{$table} () VALUES ();");
    }
    $columns = [];
    $literals = [];
    foreach ($values as $column => $value) {
      $columns[] = self::quoteIdentifier($column);
      $literals[] = self::insertSqlLiteral($value);
    }
    $sql = "INSERT INTO {$schema}.{$table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $literals) . ");";
    return \MADB\Query\SqlFormatter\SqlFormatter::format($sql);
  }

  /** Opens the shared generated SQL panel for row mutations. */
  private static function openGeneratedRowQuery(string $title, string $sql, array $state): void {
    $panel = \SPTK\Element::byName('table-insert');
    if ($panel !== false) {
      $panel->remove();
    }
    self::removePanelByName('table-insert-field-editor');
    $refreshQueryId = false;
    if (isset($state['resultContext']) && is_array($state['resultContext'])) {
      $refreshQueryId = $state['resultContext']['queryId'] ?? false;
    }
    \MADB\Query\GeneratedQueryController::open([
      'title' => $title,
      'name' => $title . ' ' . $state['schema'] . '.' . $state['table'],
      'sql' => $sql,
      'connection' => $state['connection'],
      'schema' => $state['schema'],
      'table' => $state['table'],
      'expectsResult' => false,
      'allowNoRefreshRun' => true,
      'refreshQueryId' => $refreshQueryId
    ]);
  }

  /** Returns cache keys affected by generated table SQL. */
  private static function tableCacheKeys(string $schema, array $tables = []): array {
    $keys = ['TableList:' . $schema];
    foreach (array_unique(array_filter($tables, fn($table) => $table !== false && $table !== '')) as $table) {
      $keys[] = 'TableDefinition:' . $schema . ':' . $table;
      $keys[] = 'TableFields:' . $schema . ':' . $table;
      $keys[] = 'TableReferencedBy:' . $schema . ':' . $table;
      $keys[] = 'ViewDefinition:' . $schema . ':' . $table;
    }
    return $keys;
  }

  /** Builds a readable UPDATE statement for preview. */
  private static function formattedUpdateSql(array $changes, array $where): string {
    $schema = self::quoteIdentifier(self::$insertState['schema']);
    $table = self::quoteIdentifier(self::$insertState['table']);
    $sets = [];
    foreach ($changes as $column => $value) {
      $sets[] = self::quoteIdentifier($column) . ' = ' . self::insertSqlLiteral($value);
    }
    $conditions = [];
    foreach ($where as $column => $value) {
      $conditions[] = self::quoteIdentifier($column) . ($value === null ? ' IS NULL' : ' = ' . self::insertSqlLiteral($value));
    }
    $sql = "UPDATE {$schema}.{$table} SET " . implode(', ', $sets) . " WHERE " . implode(' AND ', $conditions) . ';';
    return \MADB\Query\SqlFormatter\SqlFormatter::format($sql);
  }

  /** Builds a readable DELETE statement for preview. */
  private static function formattedDeleteSql(array $primaryRows): string {
    $schema = self::quoteIdentifier(self::$deleteState['schema']);
    $table = self::quoteIdentifier(self::$deleteState['table']);
    $groups = [];
    foreach ($primaryRows as $primaryRow) {
      $conditions = [];
      foreach ($primaryRow as $column => $value) {
        $conditions[] = self::quoteIdentifier($column) . ($value === null ? ' IS NULL' : ' = ' . self::insertSqlLiteral($value));
      }
      $groups[] = '(' . implode(' AND ', $conditions) . ')';
    }
    $sql = "DELETE FROM {$schema}.{$table} WHERE " . implode(' OR ', $groups) . ';';
    return \MADB\Query\SqlFormatter\SqlFormatter::format($sql);
  }

  /** Formats one insert preview value as a SQL literal. */
  private static function insertSqlLiteral(mixed $value): string {
    if ($value === null) {
      return 'NULL';
    }
    return "'" . str_replace("'", "''", (string)$value) . "'";
  }

  /** Shows a separate panel containing the generated INSERT preview SQL. */
  private static function showInsertPreviewPanel(string $sql): void {
    self::removePanelByName('table-insert-preview');
    $window = \SPTK\Element::firstByType('Window');
    if ($window === false) {
      return;
    }
    $panel = new \SPTK\Elements\Panel($window, 'table-insert-preview');
    $title = new \SPTK\Element($panel, null, null, 'PanelTitle');
    $title->addText('Insert preview');
    $content = new \SPTK\Element($panel, null, null, 'PanelContent');
    $preview = new \SPTK\Elements\TextBox($content, 'table-insert-preview-text');
    $preview->setTokenizer(false);
    $preview->setValue($sql);
    $buttons = new \SPTK\Element($content, null, null, 'ButtonBox');
    self::addPanelButton($buttons, 'RETURN', 'MADB\Table\RowsController::closeInsertPreview', 'OK');
    $panel->show();
    if (method_exists($panel, 'activateInput')) {
      $panel->activateInput('table-insert-preview-text');
    }
    \SPTK\Element::refresh();
  }

  /** Shows a separate panel containing the generated DELETE preview SQL. */
  private static function showDeletePreviewPanel(string $sql, int $rowCount): void {
    self::removePanelByName('table-delete-preview');
    $window = \SPTK\Element::firstByType('Window');
    if ($window === false) {
      return;
    }
    $panel = new \SPTK\Elements\Panel($window, 'table-delete-preview');
    $title = new \SPTK\Element($panel, null, null, 'PanelTitle');
    $title->addText('Delete preview: ' . $rowCount . ' row' . ($rowCount === 1 ? '' : 's'));
    $content = new \SPTK\Element($panel, null, null, 'PanelContent');
    $preview = new \SPTK\Elements\TextBox($content, 'table-delete-preview-text');
    $preview->setTokenizer(false);
    $preview->setValue($sql);
    $buttons = new \SPTK\Element($content, null, null, 'ButtonBox');
    self::addPanelButton($buttons, 'RETURN', 'MADB\Table\RowsController::saveDeleteRows', 'Delete');
    new \SPTK\Elements\Space($buttons);
    self::addPanelButton($buttons, 'ESCAPE', 'MADB\Table\RowsController::closeDeletePreview', 'Cancel');
    $panel->show();
    if (method_exists($panel, 'activateInput')) {
      $panel->activateInput('table-delete-preview-text');
    }
    \SPTK\Element::refresh();
  }

  /** Returns the table-definition column index for a field name. */
  private static function columnIndexByName(array $columns, string $name): int {
    foreach ($columns as $index => $column) {
      if ((string)($column['COLUMN_NAME'] ?? '') === $name) {
        return (int)$index;
      }
    }
    return -1;
  }

  /** Shows immediate feedback while row-editor table metadata is loading. */
  private static function showRowMetadataProgress(string $action, string $schema, string $table): void {
    self::removePanelByName('table-row-metadata-progress');
    $window = \SPTK\Element::firstByType('Window');
    if ($window === false) {
      return;
    }
    $panel = new \SPTK\Elements\Panel($window, 'table-row-metadata-progress');
    $title = new \SPTK\Element($panel, null, null, 'PanelTitle');
    $title->addText($action);
    $content = new \SPTK\Element($panel, null, null, 'PanelContent');
    $content->addText("Loading table metadata for {$schema}.{$table}...\nFetching columns and primary key details.");
    $buttons = new \SPTK\Element($content, null, null, 'ButtonBox');
    self::addPanelButton($buttons, 'ESCAPE', 'MADB\Table\RowsController::closeRowMetadataProgress', 'Hide');
    $panel->show();
    \SPTK\Element::refresh();
  }

  /** Closes the row metadata progress panel. */
  public static function closeRowMetadataProgress($panel): void {
    $panel->remove();
    \MADB\Main\ScreenController::restoreFocusAfterPanelClose();
    \SPTK\Element::refresh();
  }

  /** Checks whether a blank insert field can be omitted from INSERT. */
  private static function canOmitInsertColumn(array $column): bool {
    return strtoupper((string)($column['IS_NULLABLE'] ?? '')) === 'YES' ||
      array_key_exists('COLUMN_DEFAULT', $column) && $column['COLUMN_DEFAULT'] !== null ||
      stripos((string)($column['EXTRA'] ?? ''), 'auto_increment') !== false;
  }

  /** Checks whether a column should use a multiline editor. */
  private static function isLongTextColumn(array $column): bool {
    $type = strtolower((string)($column['COLUMN_TYPE'] ?? ''));
    $type = trim(preg_replace('/\s*\(.*/', '', $type));
    $type = preg_replace('/\s+/', ' ', $type);
    return in_array($type, [
      'tinytext',
      'text',
      'mediumtext',
      'longtext',
      'clob',
      'blob',
      'tinyblob',
      'mediumblob',
      'longblob',
      'json'
    ], true);
  }

  /** Shows the insert success panel with OK and Refresh actions. */
  private static function showInsertSuccessPanel(string $text): void {
    self::removePanelByName('table-insert-success');
    $window = \SPTK\Element::firstByType('Window');
    if ($window === false) {
      return;
    }
    $panel = new \SPTK\Elements\Panel($window, 'table-insert-success');
    $title = new \SPTK\Element($panel, null, null, 'PanelTitle');
    $title->addText('Insert completed');
    $content = new \SPTK\Element($panel, null, null, 'PanelContent');
    $content->addText($text);
    $buttons = new \SPTK\Element($content, null, null, 'ButtonBox');
    self::addPanelButton($buttons, 'RETURN', 'MADB\Table\RowsController::refreshAfterInsert', 'Refresh');
    new \SPTK\Elements\Space($buttons);
    self::addPanelButton($buttons, 'ESCAPE', 'MADB\Table\RowsController::closeInsertSuccess', 'OK');
    $panel->show();
    \SPTK\Element::refresh();
  }

  /** Removes a dynamic panel by name when it already exists. */
  private static function removePanelByName(string $name): void {
    $panel = \SPTK\Element::byName($name);
    if ($panel !== false) {
      $panel->remove();
    }
  }

}
