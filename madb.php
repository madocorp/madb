#!/usr/bin/env php
<?php

define('SPTK\DEBUG', false);
define('APP_PATH', __FILE__);
define('APP_NAMESPACE', 'MADB');

require_once 'SPTK/Autoload.php';

MADB\Job\JobHandler::init(); // Has to be done as soon as possible because of forking

new SPTK\App(
  'Layout/madb.xml',
  'Layout/style.xss',
  ['\MADB\Config\Init', 'callback'],
  ['\MADB\Job\JobHandler', 'getResults'],
  ['\MADB\Main\ScreenController', 'timer'],
  null
);
