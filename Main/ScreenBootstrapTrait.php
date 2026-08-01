<?php

namespace MADB\Main;

use \SPTK\SDLWrapper\SDL;
use \SPTK\Element;
use \MADB\List\QueryList;

/** Initializes query workspace widgets, templates, and current connection state when the screen opens. */
trait ScreenBootstrapTrait {

  /** Coordinates init work in the query workspace. */
  public static function init() {
    self::$editorContainer = Element::byName('query-editor-container');
    self::$resultContainer = Element::byName('query-result-container');
    self::$listContainer = Element::byName('query-list-container');
    self::$editor = Element::byName('query-editor');
    self::$title = Element::byName('query-title');
    self::$result = Element::byName('query-result');
    self::$resultMessage = Element::byName('query-result-message');
    self::$resultStatus = Element::byName('query-result-status');
    self::$resultPreview = Element::byName('query-result-preview');
    self::$resultPreviewText = Element::byName('query-result-preview-text');
    self::$resultTable = Element::byName('query-result-table');
    self::$list = Element::byName('query-list');
    self::$connectionInfo = Element::byName('connection-info');
    self::$queryName = Element::byName('query-name');
    self::$renamePanel = Element::byName('query-rename');
    self::$searchPanel = Element::byName('query-search');
    self::$resultSearchPanel = Element::byName('result-search');
    self::$resultExportPanel = Element::byName('result-export');
    self::$fieldValuePanel = Element::byName('query-field-value');
    self::$mongoDocumentEditorPanel = Element::byName('mongodb-document-editor');
    self::$queryList = QueryList::getInstance();
    self::resetTemporaryResultViewState();
    if (self::$resultTable !== false && method_exists(self::$resultTable, 'setOnChange')) {
      self::$resultTable->setOnChange('\MADB\Main\ScreenController::syncResultFastPreview');
    }
    if (SDL::$instance !== null) {
      SDL::$instance->setTimer(self::TIMER_MS);
    }
    self::$list->clear();
    self::$list->setOnChange('\MADB\List\QueryListController::selectQueryFromList');
    self::loadConnection(false);
  }

  /** Returns current connection data used by the query workspace. */
  private static function getCurrentConnection() {
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    return $connectionList->current;
  }

  /** Returns current connection name data used by the query workspace. */
  private static function getCurrentConnectionName() {
    $connection = self::getCurrentConnection();
    if ($connection === false) {
      return false;
    }
    return $connection['name'];
  }

}
