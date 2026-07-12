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
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    $connection = $connectionList->current;
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'tableFields',
      'arguments' => [self::$currentSchema, self::$currentTable],
      'callback' => ['\MADB\Table\MenuController', 'selectedRows'],
      'schema' => self::$currentSchema,
      'table' => self::$currentTable,
      'cache' => 'TableFields:' . self::$currentSchema . ':' . self::$currentTable
    ]);
  }

  /** Selects rows and refreshes related table menu state. */
  public static function selectedRows($response) {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not inspect table', $response['result']);
      return;
    }
    $schema = $response['schema'];
    $table = $response['table'];
    $name = 'SELECT ' . $schema . '.' . $table;
    \MADB\Main\ScreenController::addTemplateQuery('SELECT current', $name, $response['connection']['name'], $schema, $table, $response['result']);
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
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'tableDefinition',
      'arguments' => [$resultContext['schema'], $resultContext['table']],
      'callback' => ['\MADB\Table\RowsController', 'openInsertRow'],
      'schema' => $resultContext['schema'],
      'table' => $resultContext['table']
    ]);
  }

  /** Builds and shows the insert-row panel after table metadata loads. */
  public static function openInsertRow($response): void {
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
      'activeColumnIndex' => 0,
      'syncingFieldList' => false,
      'primaryColumns' => $primaryColumns,
      'resultContext' => $resultContext
    ];
    self::showInsertPanel($schema, $table, $columns);
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
    \MADB\Job\JobHandler::startJob([
      'connection' => self::$insertState['connection'],
      'command' => 'insertTableRow',
      'arguments' => [self::$insertState['schema'], self::$insertState['table'], $values],
      'callback' => ['\MADB\Table\RowsController', 'insertRowSaved'],
      'schema' => self::$insertState['schema'],
      'table' => self::$insertState['table']
    ]);
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

  /** Closes the insert panel without saving. */
  public static function closeInsertPanel($panel): void {
    $panel->remove();
    \SPTK\Element::refresh();
  }

  /** Closes the insert success panel. */
  public static function closeInsertSuccess($panel): void {
    $panel->remove();
    \SPTK\Element::refresh();
  }

  /** Refreshes the active query after an insert succeeds. */
  public static function refreshAfterInsert($panel): void {
    $panel->remove();
    \MADB\Main\QueryExecutionController::executeQuery();
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
    \MADB\Main\QueryExecutionController::executeQuery();
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
    $schema = self::quoteIdentifier(self::$currentSchema);
    $table = self::quoteIdentifier(self::$currentTable);
    $sql = "SHOW CREATE TABLE {$schema}.{$table}";
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'query',
      'arguments' => [$sql],
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
    $result = $response['result'];
    $row = $result['rows'][0] ?? false;
    if ($row === false) {
      \SPTK\Elements\ErrorPanel::forge('Could not get SHOW CREATE TABLE', 'The query returned no rows.');
      return;
    }
    $createSql = false;
    foreach ($row as $column => $value) {
      if (strpos($column, 'Create ') === 0) {
        $createSql = $value;
        break;
      }
    }
    if ($createSql === false) {
      \SPTK\Elements\ErrorPanel::forge('Could not get SHOW CREATE TABLE', 'The query result did not contain a CREATE statement.');
      return;
    }
    $schema = $response['schema'];
    $table = $response['table'];
    $name = 'CREATE ' . $schema . '.' . $table;
    \MADB\Main\ScreenController::addQuery($name, $createSql, $response['connection']['name'], $schema, $table);
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

  /** Builds the dynamic insert-row panel. */
  private static function showInsertPanel(string $schema, string $table, array $columns): void {
    self::removePanelByName('table-insert');
    $window = \SPTK\Element::firstByType('Window');
    if ($window === false) {
      \SPTK\Elements\ErrorPanel::forge('Could not open insert panel', 'No application window was found.');
      return;
    }
    $panel = new \SPTK\Elements\Panel($window, 'table-insert');
    $panel->setReturnAction(['\MADB\Table\RowsController', 'saveInsertRow']);
    $title = new \SPTK\Element($panel, null, null, 'PanelTitle');
    $title->addText('Insert row: ' . $schema . '.' . $table);
    $content = new \SPTK\Element($panel, 'table-insert-content', null, 'PanelContent');
    $fields = new \SPTK\Elements\ListBox($content, 'table-insert-fields');
    $fields->setOnChange(['\MADB\Table\RowsController', 'selectInsertField']);
    foreach (array_values($columns) as $index => $column) {
      self::addInsertFieldItem($fields, $index, $column);
    }
    new \SPTK\Element($content, 'table-insert-detail', null, 'Box');
    $buttons = new \SPTK\Element($content, 'table-insert-buttons', null, 'ButtonBox');
    self::addPanelButton($buttons, 'ESCAPE', 'MADB\Table\RowsController::closeInsertPanel', 'Cancel');
    new \SPTK\Elements\Space($buttons);
    self::addPanelButton($buttons, 'RETURN', 'MADB\Table\RowsController::saveInsertRow', 'Insert');
    self::renderInsertFieldDetail(0);
    $panel->show();
    if (method_exists($panel, 'activateInput')) {
      $panel->activateInput('table-insert-fields');
    }
    \SPTK\Element::refresh();
  }

  /** Adds one table-column item to the insert panel field list. */
  private static function addInsertFieldItem($parent, int $index, array $column): void {
    $name = (string)($column['COLUMN_NAME'] ?? '');
    $type = (string)($column['COLUMN_TYPE'] ?? '');
    $item = new \SPTK\Elements\ListItem($parent);
    $item->setValue((string)$index);
    $item->setText($name);
    $item->setRight($type);
  }

  /** Saves the visible insert detail field and renders the newly active field. */
  public static function selectInsertField($list): void {
    $panel = \SPTK\Element::byName('table-insert');
    if ($panel === false || empty(self::$insertState)) {
      return;
    }
    if (!empty(self::$insertState['syncingFieldList'])) {
      return;
    }
    self::saveVisibleInsertField($panel);
    $active = $list->getActive();
    $index = $active === false ? 0 : (int)$active->getValue();
    self::$insertState['activeColumnIndex'] = $index;
    self::renderInsertFieldDetail($index);
    self::$insertState['syncingFieldList'] = true;
    $panel->refreshInputList($list);
    self::$insertState['syncingFieldList'] = false;
    \SPTK\Element::refresh();
  }

  /** Rebuilds the right-hand insert editor for one table field. */
  private static function renderInsertFieldDetail(int $index): void {
    $detail = \SPTK\Element::byName('table-insert-detail');
    if ($detail === false || !isset(self::$insertState['columns'][$index])) {
      return;
    }
    $detail->clear();
    $column = self::$insertState['columns'][$index];
    $name = (string)($column['COLUMN_NAME'] ?? '');
    $type = (string)($column['COLUMN_TYPE'] ?? '');
    $nullable = strtoupper((string)($column['IS_NULLABLE'] ?? '')) === 'YES';
    $header = new \SPTK\Element($detail, null, 'table-insert-detail-header', 'Label');
    $header->addText($name . ' ' . $type);
    if ($nullable) {
      new \SPTK\Elements\Space($header);
      $null = new \SPTK\Elements\CheckBox($header, 'table-insert-null');
      $null->setValue((bool)(self::$insertState['nulls'][$index] ?? false));
      $null->addText('NULL');
    }
    new \SPTK\Element($detail, null, null, 'NL');
    if (self::isLongTextColumn($column)) {
      $input = new \SPTK\Elements\TextEditor($detail, 'table-insert-field', 'table-insert-text');
    } else {
      $input = new \SPTK\Elements\Input($detail, 'table-insert-field', 'table-insert-input');
    }
    $input->setValue(self::$insertState['values'][$index] ?? '');
  }

  /** Saves the currently visible right-hand insert editor into insert state. */
  private static function saveVisibleInsertField($panel): void {
    if (empty(self::$insertState)) {
      return;
    }
    $index = (int)(self::$insertState['activeColumnIndex'] ?? 0);
    if (!isset(self::$insertState['columns'][$index])) {
      return;
    }
    $input = \SPTK\Element::byName('table-insert-field', $panel);
    if ($input !== false) {
      self::$insertState['values'][$index] = self::textValue($input->getValue());
    }
    $null = \SPTK\Element::byName('table-insert-null', $panel);
    if ($null !== false) {
      self::$insertState['nulls'][$index] = $null->getValue() === true;
    } else {
      self::$insertState['nulls'][$index] = false;
    }
  }

  /** Adds a hotkey button to a dynamic panel. */
  private static function addPanelButton($parent, string $hotKey, string $callback, string $text): void {
    $button = new \SPTK\Elements\Button($parent);
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

  /** Checks whether a blank insert field can be omitted from INSERT. */
  private static function canOmitInsertColumn(array $column): bool {
    return strtoupper((string)($column['IS_NULLABLE'] ?? '')) === 'YES' ||
      array_key_exists('COLUMN_DEFAULT', $column) && $column['COLUMN_DEFAULT'] !== null ||
      stripos((string)($column['EXTRA'] ?? ''), 'auto_increment') !== false;
  }

  /** Checks whether a column should use a multiline editor. */
  private static function isLongTextColumn(array $column): bool {
    $type = strtolower((string)($column['COLUMN_TYPE'] ?? ''));
    foreach (['text', 'blob', 'json'] as $needle) {
      if (str_contains($type, $needle)) {
        return true;
      }
    }
    return false;
  }

  /** Shows the insert success panel with OK and Refresh actions. */
  private static function showInsertSuccessPanel(string $text): void {
    self::removePanelByName('table-insert-success');
    $window = \SPTK\Element::firstByType('Window');
    if ($window === false) {
      return;
    }
    $panel = new \SPTK\Elements\Panel($window, 'table-insert-success');
    $panel->setReturnAction(['\MADB\Table\RowsController', 'refreshAfterInsert']);
    $title = new \SPTK\Element($panel, null, null, 'PanelTitle');
    $title->addText('Insert completed');
    $content = new \SPTK\Element($panel, null, null, 'PanelContent');
    $content->addText($text);
    $buttons = new \SPTK\Element($content, null, null, 'ButtonBox');
    self::addPanelButton($buttons, 'ESCAPE', 'MADB\Table\RowsController::closeInsertSuccess', 'OK');
    new \SPTK\Elements\Space($buttons);
    self::addPanelButton($buttons, 'RETURN', 'MADB\Table\RowsController::refreshAfterInsert', 'Refresh');
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
