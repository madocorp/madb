<?php

namespace MADB\Table;

/** Owns the SQLite-specific view create panel and SQL generation. */
class SQLiteViewCreateController {

  private static $schema = false;

  /** Opens the SQLite view create panel. */
  public static function openCreate(): void {
    $connection = self::currentConnection();
    self::$schema = \MADB\Table\MenuController::getCurrentSchema();
    $currentTable = \MADB\Table\MenuController::getCurrentTable();
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection from the menu before preforming this operation.');
      return;
    }
    if (self::$schema === false || self::$schema === '') {
      \SPTK\Elements\WarningPanel::forge('No database selected!', 'Please select a database before preforming this operation.');
      return;
    }
    if (!\MADB\Connection\MenuController::requireOperation('viewCreate', 'Creating views', $connection)) {
      return;
    }
    $panel = self::panel();
    if ($panel === false) {
      return;
    }
    self::setTitle('Create SQLite view in ' . self::$schema);
    $panel->setValue([
      'sqlite-view-name' => '',
      'sqlite-view-definition' => self::defaultCreateDefinition(self::$schema, $currentTable)
    ]);
    $panel->show();
    if (method_exists($panel, 'activateInput')) {
      $panel->activateInput('sqlite-view-name');
    }
    \SPTK\Element::refresh();
  }

  /** Generates SQLite CREATE VIEW SQL. */
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
    $name = trim(self::textValue($values['sqlite-view-name'] ?? ''));
    $definition = trim(self::textValue($values['sqlite-view-definition'] ?? ''));
    if ($name === '') {
      \SPTK\Elements\WarningPanel::forge('No view name!', 'Please enter a view name before generating SQL.');
      return;
    }
    if ($definition === '') {
      \SPTK\Elements\WarningPanel::forge('No view definition!', 'Please enter a SELECT statement before generating SQL.');
      return;
    }
    $sql = self::buildCreateSql(self::$schema, $name, $definition);
    \MADB\Query\GeneratedQueryController::open([
      'title' => 'Create SQLite view',
      'name' => 'CREATE VIEW ' . self::$schema . '.' . $name,
      'sql' => $sql,
      'connection' => $connection,
      'schema' => self::$schema,
      'table' => $name,
      'cacheKeys' => [
        'TableList:' . self::$schema,
        'TableFields:' . self::$schema . ':' . $name,
        'TableDefinition:' . self::$schema . ':' . $name
      ],
      'refresh' => 'tables'
    ]);
  }

  /** Builds SQLite CREATE VIEW SQL. */
  public static function buildCreateSql(string $schema, string $name, string $definition): string {
    $definition = preg_replace('/;\s*$/', '', rtrim($definition));
    return 'CREATE VIEW ' . \MADB\Table\SQLiteTableCreateController::quoteQualifiedName($schema, $name) . " AS\n" . $definition . ';';
  }

  /** Returns current connection data. */
  private static function currentConnection() {
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    return $connectionList->current;
  }

  /** Returns the SQLite view panel. */
  private static function panel() {
    return \SPTK\Element::byName('sqlite-view-create');
  }

  /** Applies title text to the SQLite view panel. */
  private static function setTitle(string $title): void {
    $panelTitle = \SPTK\Element::firstByType('PanelTitle', self::panel());
    if ($panelTitle !== false) {
      $panelTitle->setText($title);
    }
  }

  /** Builds a default SQLite SELECT for create-view mode. */
  private static function defaultCreateDefinition($schema, $table): string {
    if ($schema !== false && $table !== false && $table !== '') {
      return "SELECT\n  *\nFROM " . \MADB\Table\SQLiteTableCreateController::quoteQualifiedName($schema, $table);
    }
    return "SELECT\n  *\nFROM ";
  }

  /** Returns plain text from SPTK values. */
  private static function textValue($value): string {
    if (is_array($value)) {
      return implode("\n", $value);
    }
    return (string)$value;
  }

}
