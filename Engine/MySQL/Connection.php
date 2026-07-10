<?php

namespace MADB\Engine\MySQL;

use \PDO;

/**
 * Implements the MySQL database connection used by MADB. Engine behavior is split between bootstrap, schema, table, query, and copy traits.
 */
class Connection extends \MADB\Connection\Connection {

  use ConnectionBootstrapTrait;
  use SchemaCopyTrait;
  use SchemaOperationsTrait;
  use TableInspectionTrait;
  use QueryRunnerTrait;

  public $pdo;

}
