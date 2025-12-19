<?php

define('SPTK\DEBUG', true);

require_once 'Connection/Connection.php';
require_once 'Connection/ConnectionMySQL.php';
require_once 'Connection/ConnectionMongoDB.php';
require_once 'Connection/Worker.php';
require_once 'Connection/WorkerHandler.php';
require_once 'Connection/JobDirector.php';
MADB\Connection\JobDirector::init(); // We must do it as soon as possible because of forking
require_once 'SPTK/App.php';
require_once 'Connection/ConnectionList.php';
require_once 'Connection/MenuController.php';
require_once 'Connection/EditController.php';
require_once 'Schema/MenuController.php';
require_once 'Config/Init.php';
require_once 'Config/XML.php';
require_once 'Config/ConfigDir.php';
require_once 'Config/MenuController.php';

new SPTK\App(
  'Layout/madb.xml',
  'Layout/style.xss',
  ['MADB\Config\Init', 'callback'],
  null,
  null,
  ['MADB\Connection\JobDirector', 'getStatus']
);
