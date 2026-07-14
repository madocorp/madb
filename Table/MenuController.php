<?php

namespace MADB\Table;

/**
 * Owns table menu state for the selected schema. It coordinates table selection, row templates, copy, and show-create actions.
 */
class MenuController {

  use MenuStateTrait;
  use MenuRowsTrait;
  use MenuCopyTrait;

  private static $currentSchema = false;
  private static $currentTable = false;
  private static $insertState = [];
  private static $deleteState = [];

}
