<?php

namespace MADB\Main;

use \SPTK\SDLWrapper\KeyCombo;
use \SPTK\SDLWrapper\Action;
use \SPTK\SDLWrapper\KeyCode;
use \SPTK\SDLWrapper\KeyModifier;
use \SPTK\SDLWrapper\ScanCode;
use \SPTK\SDLWrapper\SDL;
use \SPTK\Element;
use \MADB\Query\QueryList;
use \MADB\Query\ResultStore;
use \MADB\Query\SqlSplitter;

/**
 * Owns the main query workspace screen state. It composes query list, editor, execution, search, focus, and result panel behavior.
 */
class ScreenController {

  use ScreenBootstrapTrait;
  use ScreenSearchSupportTrait;
  use ScreenStateTrait;
  use ScreenListTrait;
  use ScreenSearchActionsTrait;
  use ScreenQueryEditTrait;
  use ScreenQueryClearTrait;
  use ScreenQueryFilesTrait;
  use ScreenExecutionTrait;
  use ScreenExecutionCallbacksTrait;
  use ScreenExecutionSupportTrait;
  use ScreenResultTrait;
  use ScreenResultFormatTrait;
  use ScreenFocusTrait;
  use ScreenKeyHandlerTrait;

  const EDITOR = 0;
  const RESULT = 1;
  const LIST = 2;
  const CLEAR_WARNING_RESULT_BYTES = 10485760;
  const CLEAR_WARNING_SECONDS = 10;

  private static $activeBox = self::EDITOR;
  private static $editorContainer;
  private static $resultContainer;
  private static $listContainer;
  private static $editor;
  private static $title;
  private static $result;
  private static $resultMessage;
  private static $resultStatus;
  private static $resultTable;
  private static $list;
  private static $connectionInfo;
  private static $queryName;
  private static $renamePanel;
  private static $searchPanel;
  private static $queryList;
  private static $connectionName = false;
  private static $updatingList = false;
  private static $suppressFocusChange = false;
  private static $editorStates = [];
  private static $loadedEditorStates = [];
  private static $resultHighlightKey = false;
  private static $searchSession = false;
  private static $searchPanelState = [
    'search' => '',
    'replaceEnabled' => false,
    'replace' => '',
    'regexp' => false,
    'caseSensitive' => false,
    'scopeAll' => false,
    'scopeNext' => true,
    'scopePrevious' => false,
    'scopeAfter' => false,
    'scopeBefore' => false
  ];
  private static $templates = [
    'SELECT current' => "SELECT [FIELDS]\nFROM [DB].[TABLE]\nWHERE 1\nLIMIT 1000;\n",
    'SELECT all' => "SELECT *\nFROM [DB].[TABLE]\nWHERE 1\nLIMIT 1000;\n",
    'INSERT' => "INSERT INTO [DB].[TABLE]\n([FIELDS])\nVALUES();\n",
    'UPDATE' => "UPDATE [DB].[TABLE]\nSET `field` = ''\nWHERE [PKEY] = -1;\n",
    'ON DUPLICATE' => "ON DUPLICATE KEY UPDATE `field` = ''\n",
    'JOIN' => "INNER JOIN [DB].[TABLE] AS `T` ON [PKEY] = `T`.`Id`\n",
    'DELETE' => "DELETE FROM [DB].[TABLE] WHERE [PKEY] = -1;\n",
    'GROUP CONCAT MAX LENGTH' => "SET SESSION group_concat_max_len = 1000000;\n"
  ];

}
