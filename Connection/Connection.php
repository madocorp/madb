<?php

namespace MADB\Connection;

/** Defines the shared database connection contract used by menu controllers and background jobs. */
abstract class Connection {

  public $queryTime;

  /** Initializes connection menu state. */
  public function __construct($data) {
    $defaults = static::getDefaults();
    $this->data = [];
    foreach ($defaults as $key => $default) {
      if (isset($data[$key])) {
        $this->data[$key] = $data[$key];
      } else {
        $this->data[$key] = $default;
      }
    }
  }

  /** Returns defaults data used by the connection menu. */
  abstract static public function getDefaults();
  /** Returns menu labels data used by the connection menu. */
  public static function getMenuLabels() {
    return [
      'schema' => 'Schema',
      'table' => 'Table'
    ];
  }

  /** Returns whether an optional UI operation is supported by this engine. */
  public static function supportsOperation($operation): bool {
    return true;
  }

  /** Coordinates connect work in the connection menu. */
  abstract public function connect();
  /** Coordinates test work in the connection menu. */
  abstract public function test();
  /** Coordinates schema list work in the connection menu. */
  abstract public function schemaList();
  /** Creates schema data for the connection menu. */
  abstract public function createSchema($schema);
  /** Coordinates schema info work in the connection menu. */
  abstract public function schemaInfo($schema);
  /** Coordinates rename schema info work in the connection menu. */
  abstract public function renameSchemaInfo($schema, $targetSchema);
  /** Coordinates rename schema work in the connection menu. */
  abstract public function renameSchema($schema, $targetSchema);
  /** Coordinates drop schema work in the connection menu. */
  abstract public function dropSchema($schema);
  /** Coordinates character sets and collations work in the connection menu. */
  abstract public function characterSetsAndCollations();
  /** Coordinates table list work in the connection menu. */
  abstract public function tableList($schema);
  /** Coordinates table fields work in the connection menu. */
  abstract public function tableFields($schema, $table);
  /** Coordinates table definition work in the connection menu. */
  abstract public function tableDefinition($schema, $table);
  /** Coordinates row editor table metadata work in the connection menu. */
  abstract public function rowEditorDefinition($schema, $table);
  /** Runs query through the connection menu. */
  abstract public function query($sql);

}
