<?php

namespace MADB\Engine;

interface EngineConnectionInterface {

  public static function getDefaults();
  public function connect();
  public function test();
  public function getServerInfo();
  public function queryBatch($statements, $resultFiles = [], $schema = false, $progress = false);

}
