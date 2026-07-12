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
  use ScreenResultSearchTrait;
  use ScreenResultExportTrait;
  use ScreenFocusTrait;
  use ScreenKeyHandlerTrait;

  const EDITOR = 0;
  const RESULT = 1;
  const LIST = 2;
  const CLEAR_WARNING_RESULT_BYTES = 10485760;
  const CLEAR_WARNING_SECONDS = 10;
  const IMMEDIATE_RESULT_BYTES = 1048576;
  const DEFERRED_RESULT_IDLE_MS = 250;
  const TIMER_MS = 100;
  const HIGHLIGHT_SPLIT_MAX_BYTES = 262144;

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
  private static $resultSearchPanel;
  private static $resultExportPanel;
  private static $fieldValuePanel;
  private static $queryList;
  private static $connectionName = false;
  private static $updatingList = false;
  private static $suppressFocusChange = false;
  private static $editorStates = [];
  private static $loadedEditorStates = [];
  private static $resultHighlightKey = false;
  private static $pendingResultLoad = false;
  private static $pendingResultGeneration = 0;
  private static $searchSession = false;
  private static $resultSearchSession = false;
  private static $resultRowNumbers = true;
  private static $pendingResultExport = false;
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
  private static $resultSearchPanelState = [
    'result-search-text' => '',
    'result-search-fields' => false,
    'result-search-header' => false,
    'result-search-regexp' => false,
    'result-search-case-sensitive' => false,
    'result-search-scope-all' => false,
    'result-search-scope-next' => true,
    'result-search-scope-previous' => false
  ];
  private static $resultExportPanelState = [
    'result-export-target-file' => true,
    'result-export-target-clipboard' => false,
    'result-export-source-all' => true,
    'result-export-source-selection' => false,
    'result-export-source-rows' => false,
    'result-export-source-columns' => false,
    'result-export-format' => 'CSV/TSV',
    'result-export-file' => '',
    'result-export-max-rows' => '',
    'result-export-delimited-preset-csv' => true,
    'result-export-delimited-preset-tsv' => false,
    'result-export-delimited-preset-user' => false,
    'result-export-delimited-headers' => true,
    'result-export-delimited-null-text' => 'NULL',
    'result-export-delimited-separator' => ',',
    'result-export-delimited-string-delimiter' => '"',
    'result-export-delimited-line-end' => '\n',
    'result-export-delimited-escape-char' => '',
    'result-export-markdown-headers' => true,
    'result-export-markdown-line-collapse' => true,
    'result-export-markdown-line-br' => false,
    'result-export-markdown-line-literal' => false,
    'result-export-markdown-null-text' => 'NULL',
    'result-export-markdown-length' => '',
    'result-export-html-headers' => true,
    'result-export-html-document' => false,
    'result-export-html-multiline' => false,
    'result-export-html-null-text' => 'NULL',
    'result-export-html-title' => 'MADB result',
    'result-export-xml-declaration' => true,
    'result-export-xml-compact' => false,
    'result-export-xml-root' => 'result',
    'result-export-xml-row' => 'row',
    'result-export-xml-field' => '',
    'result-export-xml-null-text' => 'NULL',
    'result-export-json-headers' => true,
    'result-export-json-pretty' => true,
    'result-export-json-unescaped-unicode' => true,
    'result-export-json-unescaped-slashes' => true,
    'result-export-json-unescaped-lineterm' => false,
    'result-export-sql-schema' => '',
    'result-export-sql-table' => '',
    'result-export-sql-group-insert' => '',
    'result-export-sql-add-info' => false
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
