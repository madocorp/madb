<?php

namespace MADB\Engine\Git;

class WorkspaceController {

  private static array $selectedCommit = [];
  private static string $selectedFile = '';
  private static int $diffContext = 3;

  public static function showBranchCommits(): void {
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    $connection = $connectionList->current;
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No repository selected', 'Please select a Git repository connection first.');
      return;
    }
    $branch = \MADB\Table\MenuController::getCurrentSchema();
    if ($branch === false || $branch === '') {
      \SPTK\Elements\WarningPanel::forge('No branch selected', 'Please select a branch before showing commits.');
      return;
    }
    $query = \MADB\Main\ScreenController::openWorkspaceResult(
      'Git log ' . $branch,
      'git log ' . $branch,
      $connection['name'],
      $branch
    );
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'commitLog',
      'arguments' => [$branch, $query['absoluteFile'], $query['relativeFile']],
      'callback' => ['\MADB\Engine\Git\WorkspaceController', 'commitsLoaded'],
      'schema' => $branch,
      'queryId' => $query['queryId']
    ]);
  }

  public static function commitsLoaded($response): void {
    $connectionName = $response['connection']['name'] ?? false;
    $queryId = $response['queryId'] ?? false;
    if (($response['status'] ?? 'ERROR') !== 'OK') {
      \MADB\Main\ScreenController::finishWorkspaceResult($connectionName, $queryId, false, $response['result'] ?? 'Could not load commits.');
      return;
    }
    \MADB\Main\ScreenController::finishWorkspaceResult($connectionName, $queryId, $response['result']);
  }

  public static function selectCommitFromResult($table = null): bool {
    $row = \MADB\Main\ScreenController::activeResultRowAssoc();
    if ($row === false || empty($row['hash'])) {
      return false;
    }
    $hash = self::commitHashFromField((string)$row['hash']);
    if ($hash === '') {
      return false;
    }
    self::loadCommit($hash, self::commitShortFromHashField((string)$row['hash']));
    return true;
  }

  public static function showCommitFromMenu(): void {
    self::selectCommitFromResult();
  }

  public static function selectFile($list): bool {
    $active = $list->getActive();
    if ($active === false) {
      return false;
    }
    $file = (string)$active->getValue();
    $commit = self::$selectedCommit['hash'] ?? '';
    if ($commit === '' || $file === '') {
      return false;
    }
    $connection = \MADB\Connection\ConnectionList::getInstance()->current;
    if ($connection === false) {
      return false;
    }
    self::$selectedFile = $file;
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'fileDiff',
      'arguments' => [$commit, $file, self::$diffContext],
      'callback' => ['\MADB\Engine\Git\WorkspaceController', 'diffLoaded'],
      'table' => $file
    ]);
    return true;
  }

  public static function filesLoaded($response): void {
    if (($response['status'] ?? 'ERROR') !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not load commit files', $response['result'] ?? 'Unknown Git error.');
      return;
    }
    $items = [];
    foreach (($response['result'] ?? []) as $file) {
      $path = (string)($file['path'] ?? '');
      if ($path === '') {
        continue;
      }
      $items[] = [
        'value' => $path,
        'text' => $path,
        'left' => (string)($file['status'] ?? ''),
        'leftReserve' => 4,
        'filterable' => true
      ];
    }
    \MADB\Main\ScreenController::setWorkspaceListItems($items, '\MADB\Engine\Git\WorkspaceController::selectFile');
    if (!empty($items)) {
      self::selectFile(\SPTK\Element::byName('query-list'));
    }
  }

  public static function diffLoaded($response): void {
    if (($response['status'] ?? 'ERROR') !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not load diff', $response['result'] ?? 'Unknown Git error.');
      return;
    }
    $file = (string)($response['table'] ?? '');
    $title = 'Diff ' . (self::$selectedCommit['short'] ?? '') . ($file === '' ? '' : ' ' . $file);
    \MADB\Main\ScreenController::setWorkspaceEditorText($title, (string)$response['result'], true);
  }

  public static function searchDiff(): void {
    \MADB\Main\ScreenController::searchQuery();
  }

  public static function exportDiff(): void {
    if (trim(\MADB\Main\ScreenController::workspaceEditorText()) === '') {
      \SPTK\Elements\WarningPanel::forge('No diff loaded', 'Please select a changed file before exporting the diff.');
      return;
    }
    \MADB\Main\ScreenController::openWorkspaceFilePanel(self::defaultDiffExportPath(), true, ['\MADB\Engine\Git\WorkspaceController', 'doExportDiff']);
  }

  public static function doExportDiff($path): void {
    if (is_dir($path)) {
      \SPTK\Elements\WarningPanel::forge('Missing file name', 'Please enter a file name before exporting the diff.');
      \SPTK\Element::refresh();
      return;
    }
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
      \SPTK\Elements\ErrorPanel::forge('Could not export diff', "The target directory is not writable:\n{$dir}");
      \SPTK\Element::refresh();
      return;
    }
    if (file_put_contents($path, \MADB\Main\ScreenController::workspaceEditorText()) === false) {
      \SPTK\Elements\ErrorPanel::forge('Could not export diff', "The selected file could not be saved:\n{$path}");
      \SPTK\Element::refresh();
      return;
    }
    \SPTK\Elements\Panel::forge('Diff exported', "Diff saved to:\n{$path}");
    \SPTK\Element::refresh();
  }

  public static function contextPanel(): void {
    $panel = \SPTK\Element::byName('git-diff-context');
    if ($panel === false) {
      return;
    }
    $panel->setValue(['context' => (string)self::$diffContext]);
    $panel->show();
    if (method_exists($panel, 'activateInput')) {
      $panel->activateInput('context');
    }
    \SPTK\Element::refresh();
  }

  public static function saveContext($panel): void {
    $values = $panel->getValue();
    $context = trim((string)($values['context'] ?? ''));
    if ($context === '' || !preg_match('/^\d+$/', $context)) {
      \SPTK\Elements\WarningPanel::forge('Invalid context', 'Please enter a non-negative number of context lines.');
      return;
    }
    self::$diffContext = max(0, min(10000, (int)$context));
    $panel->hide();
    if (self::$selectedFile !== '') {
      self::reloadSelectedFile();
    }
    \SPTK\Element::refresh();
  }

  public static function fetch(): void {
    $connection = \MADB\Connection\ConnectionList::getInstance()->current;
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No repository selected', 'Please select a Git repository connection first.');
      return;
    }
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'fetchRemote',
      'callback' => ['\MADB\Engine\Git\WorkspaceController', 'fetchDone']
    ]);
  }

  public static function fetchDone($response): void {
    if (($response['status'] ?? 'ERROR') !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Fetch failed', $response['result'] ?? 'Unknown Git error.');
      return;
    }
    \SPTK\Elements\Panel::forge('Fetch complete', trim((string)($response['result'] ?? '')) ?: 'Remote fetch completed.');
  }

  private static function loadCommit(string $hash, string $short): void {
    $connection = \MADB\Connection\ConnectionList::getInstance()->current;
    if ($connection === false) {
      return;
    }
    self::$selectedCommit = [
      'hash' => $hash,
      'short' => $short
    ];
    self::$selectedFile = '';
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'commitFiles',
      'arguments' => [$hash],
      'callback' => ['\MADB\Engine\Git\WorkspaceController', 'filesLoaded']
    ]);
  }

  private static function reloadSelectedFile(): void {
    $list = \SPTK\Element::byName('query-list');
    if ($list !== false) {
      self::selectFile($list);
    }
  }

  private static function defaultDiffExportPath(): string {
    $home = getenv('HOME');
    $dir = ($home !== false && $home !== '') ? $home : getcwd();
    $short = self::$selectedCommit['short'] ?? 'commit';
    $file = self::$selectedFile === '' ? 'diff' : preg_replace('/[^A-Za-z0-9_.-]+/', '-', self::$selectedFile);
    $file = trim((string)$file, '-');
    return rtrim($dir, '/') . '/' . $short . '-' . ($file === '' ? 'diff' : $file) . '.patch';
  }

  private static function commitHashFromField(string $hashField): string {
    foreach (preg_split('/\R/', $hashField) ?: [] as $line) {
      $line = trim($line);
      if (preg_match('/^Hash:\s*([0-9a-fA-F]{4,40})$/', $line, $matches)) {
        return $matches[1];
      }
    }
    $firstLine = trim((string)strtok($hashField, "\n"));
    return preg_match('/^[0-9a-fA-F]{4,40}$/', $firstLine) ? $firstLine : '';
  }

  private static function commitShortFromHashField(string $hashField): string {
    $firstLine = trim((string)strtok($hashField, "\n"));
    return $firstLine === '' ? self::commitHashFromField($hashField) : $firstLine;
  }

}
