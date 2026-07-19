<?php

namespace MADB\Table;

/** Owns the view editor panel state and SQL generation. */
class ViewController {

  private static $schema = false;
  private static $view = false;
  private static string $mode = 'modify';

  /** Opens the create panel for a new view. */
  public static function openCreate() {
    self::$schema = \MADB\Table\MenuController::getCurrentSchema();
    $currentTable = \MADB\Table\MenuController::getCurrentTable();
    self::$view = false;
    self::$mode = 'create';
    $connection = self::currentConnection();
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    if (self::$schema === false) {
      \SPTK\Elements\WarningPanel::forge('No schema selected!', 'Please select a schema before preforming this operation.');
      return;
    }
    if (!\MADB\Connection\MenuController::requireOperation('viewCreate', 'Creating views', $connection)) {
      return;
    }
    $panel = self::panel();
    if ($panel === false) {
      return;
    }
    self::setTitle('Create view in ' . self::$schema);
    $panel->setValue([
      'view-name' => '',
      'view-algorithm' => '',
      'view-definer' => '',
      'view-security' => '',
      'view-definition' => self::defaultCreateDefinition(self::$schema, $currentTable),
      'view-check-option' => ''
    ]);
    $panel->show();
    if (method_exists($panel, 'activateInput')) {
      $panel->activateInput('view-name');
    }
    \SPTK\Element::refresh();
  }

  /** Opens the modify panel for the selected view. */
  public static function openModify() {
    self::$schema = \MADB\Table\MenuController::getCurrentSchema();
    self::$view = \MADB\Table\MenuController::getCurrentTable();
    self::$mode = 'modify';
    $connection = self::currentConnection();
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    if (self::$schema === false || self::$view === false) {
      \SPTK\Elements\WarningPanel::forge('No view selected!', 'Please select a view before preforming this operation.');
      return;
    }
    if (!\MADB\Connection\MenuController::requireOperation('viewModify', 'Modifying views', $connection)) {
      return;
    }
    $panel = self::panel();
    if ($panel === false) {
      return;
    }
    self::setTitle('Modify view ' . self::$schema . '.' . self::$view);
    $panel->setValue([
      'view-name' => self::$view,
      'view-algorithm' => '',
      'view-definer' => '',
      'view-security' => '',
      'view-definition' => 'Loading...',
      'view-check-option' => ''
    ]);
    $panel->show();
    if (method_exists($panel, 'activateInput')) {
      $panel->activateInput('view-definition');
    }
    \SPTK\Element::refresh();

    $sql = 'SHOW CREATE VIEW ' . self::quoteQualifiedName(self::$schema, self::$view);
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'query',
      'arguments' => [$sql],
      'callback' => ['\MADB\Table\ViewController', 'setDefinition'],
      'schema' => self::$schema,
      'table' => self::$view,
      'cache' => 'ViewDefinition:' . self::$schema . ':' . self::$view
    ]);
  }

  /** Applies SHOW CREATE VIEW data to the view editor panel. */
  public static function setDefinition($response) {
    if ($response['status'] !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not get SHOW CREATE VIEW', $response['result']);
      return;
    }
    $row = $response['result']['rows'][0] ?? false;
    if ($row === false) {
      \SPTK\Elements\ErrorPanel::forge('Could not get SHOW CREATE VIEW', 'The query returned no rows.');
      return;
    }
    $createSql = self::createSqlFromRow($row);
    if ($createSql === false) {
      \SPTK\Elements\ErrorPanel::forge('Could not get SHOW CREATE VIEW', 'The query result did not contain a CREATE VIEW statement.');
      return;
    }
    self::$schema = $response['schema'];
    self::$view = $response['table'];
    $values = self::parseCreateView(\MADB\Query\SqlFormatter\SqlFormatter::format($createSql));
    $values['view-name'] = self::$view;
    $panel = self::panel();
    if ($panel === false) {
      return;
    }
    $panel->setValue($values);
    if (method_exists($panel, 'activateInput')) {
      $panel->activateInput('view-definition');
    }
    \SPTK\Element::refresh();
  }

  /** Generates CREATE OR REPLACE VIEW SQL into the shared generated SQL panel. */
  public static function generate($panel = null) {
    $connection = self::currentConnection();
    if ($connection === false || self::$schema === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection and schema before saving.');
      return;
    }
    $panel = $panel ?: self::panel();
    if ($panel === false) {
      return;
    }
    $values = $panel->getValue();
    $name = trim(self::textValue($values['view-name'] ?? ''));
    $definition = trim(self::textValue($values['view-definition'] ?? ''));
    if ($name === '') {
      \SPTK\Elements\WarningPanel::forge('No view name!', 'Please enter a view name before generating SQL.');
      return;
    }
    if ($definition === '' || $definition === 'Loading...') {
      \SPTK\Elements\WarningPanel::forge('No view definition!', 'Please enter a SELECT statement before generating SQL.');
      return;
    }
    $sql = self::generateSql(
      $name,
      trim(self::textValue($values['view-algorithm'] ?? '')),
      trim(self::textValue($values['view-definer'] ?? '')),
      trim(self::textValue($values['view-security'] ?? '')),
      $definition,
      trim(self::textValue($values['view-check-option'] ?? ''))
    );
    $action = self::$mode === 'create' ? 'Create view' : 'Modify view';
    \MADB\Query\GeneratedQueryController::open([
      'title' => $action,
      'name' => 'CREATE VIEW ' . self::$schema . '.' . $name,
      'sql' => $sql,
      'connection' => $connection,
      'schema' => self::$schema,
      'table' => $name,
      'cacheKeys' => [
        'TableList:' . self::$schema,
        'TableFields:' . self::$schema . ':' . $name,
        'TableDefinition:' . self::$schema . ':' . $name,
        'ViewDefinition:' . self::$schema . ':' . $name
      ],
      'refresh' => 'tables'
    ]);
  }

  /** Returns current connection data for the view editor. */
  private static function currentConnection() {
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    return $connectionList->current;
  }

  /** Returns the view editor panel. */
  private static function panel() {
    return \SPTK\Element::byName('view-editor');
  }

  /** Applies title text to the view editor. */
  private static function setTitle($title) {
    $panelTitle = \SPTK\Element::firstByType('PanelTitle', self::panel());
    if ($panelTitle !== false) {
      $panelTitle->setText($title);
    }
  }

  /** Finds CREATE VIEW SQL in a SHOW CREATE VIEW result row. */
  private static function createSqlFromRow(array $row) {
    foreach ($row as $column => $value) {
      if (strpos($column, 'Create ') === 0) {
        return $value;
      }
    }
    return false;
  }

  /** Parses formatted CREATE VIEW SQL into panel fields. */
  private static function parseCreateView(string $sql): array {
    $values = [
      'view-name' => self::$view,
      'view-algorithm' => '',
      'view-definer' => '',
      'view-security' => '',
      'view-definition' => '',
      'view-check-option' => ''
    ];
    if (preg_match('/^CREATE\s+ALGORITHM\s*=\s*([A-Z]+)/mi', $sql, $match)) {
      $values['view-algorithm'] = $match[1];
    }
    if (preg_match('/^DEFINER\s*=\s*(.+)$/mi', $sql, $match)) {
      $values['view-definer'] = trim($match[1]);
    }
    if (preg_match('/^SQL\s+SECURITY\s+([A-Z]+)/mi', $sql, $match)) {
      $values['view-security'] = $match[1];
    }
    if (preg_match('/^VIEW\s+.+?\s+AS\s*\R(.+)$/mis', $sql, $match)) {
      $definition = rtrim($match[1]);
      $definition = preg_replace('/;\s*$/', '', $definition);
      if (preg_match('/\RWITH\s+(CASCADED|LOCAL)\s+CHECK\s+OPTION\s*$/i', $definition, $optionMatch)) {
        $values['view-check-option'] = strtoupper($optionMatch[1]);
        $definition = preg_replace('/\RWITH\s+(CASCADED|LOCAL)\s+CHECK\s+OPTION\s*$/i', '', $definition);
      }
      $values['view-definition'] = rtrim($definition);
    }
    return $values;
  }

  /** Builds CREATE OR REPLACE VIEW SQL from panel values. */
  private static function generateSql($name, $algorithm, $definer, $security, $definition, $checkOption) {
    $lines = ['CREATE OR REPLACE'];
    if ($algorithm !== '') {
      $lines[] = 'ALGORITHM = ' . strtoupper($algorithm);
    }
    if ($definer !== '') {
      $lines[] = 'DEFINER = ' . $definer;
    }
    if ($security !== '') {
      $lines[] = 'SQL SECURITY ' . strtoupper($security);
    }
    $definition = preg_replace('/;\s*$/', '', rtrim($definition));
    $sql = implode("\n", $lines) . "\nVIEW " . self::quoteQualifiedName(self::$schema, $name) . " AS\n" . $definition;
    $sql = rtrim(\MADB\Query\SqlFormatter\SqlFormatter::format($sql . ';'), ';');
    if ($checkOption !== '') {
      $sql .= "\nWITH " . strtoupper($checkOption) . " CHECK OPTION";
    }
    return $sql . ';';
  }

  /** Escapes qualified schema object names. */
  private static function quoteQualifiedName($schema, $name) {
    return self::quoteIdentifier($schema) . '.' . self::quoteIdentifier($name);
  }

  /** Escapes identifier text. */
  private static function quoteIdentifier($identifier) {
    return '`' . str_replace('`', '``', $identifier) . '`';
  }

  /** Builds a useful default SELECT for create-view mode. */
  private static function defaultCreateDefinition($schema, $table): string {
    if ($schema !== false && $table !== false && $table !== '') {
      return "SELECT\n  *\nFROM " . self::quoteQualifiedName($schema, $table);
    }
    return "SELECT\n  *\nFROM ";
  }

  /** Returns plain text from SPTK values. */
  private static function textValue($value) {
    if (is_array($value)) {
      return implode("\n", $value);
    }
    return (string)$value;
  }

}
