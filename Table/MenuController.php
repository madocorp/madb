<?php

namespace MADB\Table;

/**
 * Owns table menu state for the selected schema. It coordinates table item context, row templates, copy, and show-create actions.
 */
class MenuController {

  use MenuStateTrait;
  use MenuRowsTrait;
  use MenuCopyTrait;
  use MenuDropTrait;

  private static $currentSchema = false;
  private static $currentTable = false;
  private static $currentTableType = false;
  private static $tableTypes = [];
  private static $insertState = [];
  private static $deleteState = [];

}
