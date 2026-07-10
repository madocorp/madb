<?php

namespace MADB\Table;

/**
 * Owns the table editor panel state. It composes table metadata loading, column/index/foreign-key/trigger editors, deletion, and SQL generation.
 */
class EditController {

  use EditUiTrait;
  use EditColumnStateTrait;
  use EditOptionsTrait;
  use EditDefinitionTrait;
  use EditColumnTypeTrait;
  use EditOpenLoadTrait;
  use EditForeignKeyOptionsLoadTrait;
  use EditListStateTrait;
  use EditDeleteTrait;
  use EditColumnIndexTrait;
  use EditForeignKeyTriggerTrait;
  use EditSqlTrait;

  private const TAB_MAIN = 0;
  private const TAB_COLUMN = 1;
  private const TAB_INDEX = 2;
  private const TAB_FOREIGN_KEY = 3;
  private const TAB_TRIGGER = 4;

  private static $mode = 'edit';
  private static $schema = false;
  private static $table = false;
  private static $definition = false;
  private static $columns = [];
  private static $indexes = [];
  private static $foreignKeys = [];
  private static $triggers = [];
  private static $editingItem = false;
  private static $addingItem = false;
  private static $charsets = [];
  private static $collations = [];
  private static $engines = [];
  private static $characterOptionsConnection = false;
  private static $foreignKeySchemas = [];
  private static $foreignKeyTables = [];
  private static $foreignKeyTablesSchema = false;
  private static $foreignKeyPendingTable = '';
  private static $foreignKeyTargetFields = [];
  private static $foreignKeyTargetFieldsSchema = false;
  private static $foreignKeyTargetFieldsTable = false;
  private static $foreignKeyPendingTargetColumns = [];
  private static $foreignKeyOptionsConnection = false;

}
