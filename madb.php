<?php

define('SPTK\DEBUG', true);

require_once 'Connection' . DIRECTORY_SEPARATOR . 'Worker.php';
require_once 'Connection' . DIRECTORY_SEPARATOR . 'WorkerHandler.php';
require_once 'Connection' . DIRECTORY_SEPARATOR . 'JobDirector.php';
MADB\Connection\JobDirector::init(); // We must do it as soon as possible because of forking
require_once 'SPTK' . DIRECTORY_SEPARATOR . 'App.php';
require_once 'Connection' . DIRECTORY_SEPARATOR . 'ConnectionList.php';
require_once 'Connection' . DIRECTORY_SEPARATOR . 'Connection.php';
require_once 'Connection' . DIRECTORY_SEPARATOR . 'MenuController.php';
require_once 'Connection' . DIRECTORY_SEPARATOR . 'EditController.php';
require_once 'Config' . DIRECTORY_SEPARATOR . 'Init.php';
require_once 'Config' . DIRECTORY_SEPARATOR . 'XML.php';
require_once 'Config' . DIRECTORY_SEPARATOR . 'ConfigDir.php';
require_once 'Config' . DIRECTORY_SEPARATOR . 'MenuController.php';

new SPTK\App(
  'Layout' . DIRECTORY_SEPARATOR . 'madb.xml',
  'Layout' . DIRECTORY_SEPARATOR . 'style.xss',
  ['MADB\Config\Init', 'callback'],
  null,
  null,
  ['MADB\Connection\JobDirector', 'getStatus']
);
