<?php

namespace MADB\Engine\Common\Database;

class Workspace implements \MADB\Engine\EngineWorkspaceInterface {

  public function mode(): string {
    return 'database-query';
  }

  public function paneLabels(): array {
    return [
      'editor' => 'Editor',
      'table' => 'Grid',
      'list' => 'List'
    ];
  }

  public function editorMode(): string {
    return 'editable-command';
  }

  public function editorTokenizer($connectionName) {
    if ($connectionName === false) {
      return false;
    }
    $engine = $this->connectionEngineType($connectionName);
    if ($engine === 'MongoDB') {
      return '\MADB\Tokenizer\MongoCommand';
    }
    return '\MADB\Tokenizer\Sql';
  }

  public function resultTableOnChange() {
    return '\MADB\Main\ScreenController::syncResultFastPreview';
  }

  public function listOnChange() {
    return '\MADB\List\QueryListController::selectQueryFromList';
  }

  public function defaultFocus(): string {
    return 'editor';
  }

  public function queryMenuItems($connectionName): array {
    return [
      ['text' => 'Execute', 'onOpen' => 'MADB\Query\QueryExecutionController::executeQuery'],
      ['text' => 'Execute one', 'onOpen' => 'MADB\Query\QueryExecutionController::executeCurrentQuery'],
      ['name' => 'menu-query-templates', 'text' => 'Templates', 'submenu' => true],
      ['text' => 'Edit', 'onOpen' => 'MADB\Query\QueryEditorController::editQuery'],
      ['text' => 'Format', 'onOpen' => 'MADB\Query\QueryEditorController::formatQuery'],
      ['text' => 'Revert', 'onOpen' => 'MADB\Query\QueryEditorController::revertQuery'],
      ['text' => 'Clear', 'onOpen' => 'MADB\Query\QueryEditorController::clearQuery'],
      ['text' => 'Import', 'onOpen' => 'MADB\Query\QueryEditorController::importQuery'],
      ['text' => 'Export', 'onOpen' => 'MADB\Query\QueryEditorController::exportQuery'],
      ['text' => 'Search', 'onOpen' => 'MADB\Query\QuerySearchController::searchQuery']
    ];
  }

  public function primarySelected($primary): bool {
    return false;
  }

  public function handleKeyPress($element, array $event): bool {
    return false;
  }

  private function connectionEngineType($connectionName): string {
    $connection = \MADB\Connection\ConnectionList::getInstance()->get($connectionName);
    return \MADB\Engine\EngineRegistry::connectionEngine($connection);
  }

}
