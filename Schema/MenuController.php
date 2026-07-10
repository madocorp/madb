<?php

namespace MADB\Schema;

/** Owns schema menu state and composes create, rename, and drop callbacks for the active connection. */
class MenuController {

  use MenuCreateTrait;
  use MenuRenameTrait;
  use MenuDropTrait;

  private static $selectAfterLoad = false;
  private static $currentSchema = false;
  private static $dropSchema = false;
  private static $renameSchema = false;
  private static $renameTargetSchema = false;

}
