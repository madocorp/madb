<?php

define('SPTK\DEBUG', true);
define('APP_DIR', __DIR__);

require_once 'Connection/Connection.php';
require_once 'Connection/ConnectionMySQL.php';
require_once 'Connection/ConnectionMongoDB.php';
require_once 'Job/Message.php';
require_once 'Job/Cache.php';
require_once 'Job/Worker.php';
require_once 'Job/WorkerHandler.php';
require_once 'Job/JobDirector.php';
require_once 'Job/JobHandler.php';
MADB\Job\JobHandler::init(); // Has to be done as soon as possible because of forking
require_once 'SPTK/App.php';
require_once 'Connection/ConnectionList.php';
require_once 'Connection/MenuController.php';
require_once 'Connection/EditController.php';
require_once 'Connection/SortController.php';
require_once 'Connection/StatusController.php';
require_once 'Schema/MenuController.php';
require_once 'Config/Init.php';
require_once 'Config/XML.php';
require_once 'Config/ConfigDir.php';
require_once 'Config/MenuController.php';
require_once 'Main/ScreenController.php';
require_once 'Query/QueryList.php';

new SPTK\App(
  'Layout/madb.xml',
  'Layout/style.xss',
  ['MADB\Config\Init', 'callback'],
  false,
  false,
  ['MADB\Job\JobHandler', 'getResults']
);
