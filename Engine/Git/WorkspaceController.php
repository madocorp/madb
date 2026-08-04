<?php

namespace MADB\Engine\Git;

class WorkspaceController {

  private static array $selectedCommit = [];
  private static string $selectedFile = '';
  private static int $diffContext = 3;
  private static bool $preserveMenuFocus = false;

  public static function showBranchCommits($preserveFocus = false): void {
    $preserveFocus = is_bool($preserveFocus) ? $preserveFocus : false;
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
    self::clearSelection();
    self::$preserveMenuFocus = $preserveFocus;
    $query = \MADB\Main\ScreenController::openWorkspaceResult(
      'Git log ' . $branch,
      'git log ' . $branch,
      $connection['name'],
      $branch,
      '',
      $preserveFocus
    );
    \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'commitLog',
      'arguments' => [$branch, $query['absoluteFile'], $query['relativeFile']],
      'callback' => ['\MADB\Engine\Git\WorkspaceController', 'commitsLoaded'],
      'schema' => $branch,
      'queryId' => $query['queryId'],
      'preserveFocus' => $preserveFocus
    ]);
  }

  public static function commitsLoaded($response): void {
    $connectionName = $response['connection']['name'] ?? false;
    $queryId = $response['queryId'] ?? false;
    $preserveFocus = !empty($response['preserveFocus']);
    if (($response['status'] ?? 'ERROR') !== 'OK') {
      \MADB\Main\ScreenController::finishWorkspaceResult($connectionName, $queryId, false, $response['result'] ?? 'Could not load commits.', $preserveFocus);
      return;
    }
    \MADB\Main\ScreenController::finishWorkspaceResult($connectionName, $queryId, $response['result'], false, $preserveFocus);
    $current = \MADB\Connection\ConnectionList::getInstance()->current;
    if (($current['name'] ?? false) === $connectionName) {
      self::selectCommitFromResult(null, true, $preserveFocus);
      self::focusCommitGridWhenReady($preserveFocus);
    }
  }

  public static function selectCommitFromResult($table = null, bool $force = false, bool $preserveFocus = false): bool {
    $previewUpdated = $preserveFocus ? false : \MADB\Main\ScreenController::syncResultFastPreview($table);
    $row = \MADB\Main\ScreenController::activeResultRowAssoc();
    if ($row === false || empty($row['hash'])) {
      if ($preserveFocus) {
        \MADB\Main\ScreenController::suspendWorkspaceFocusForPrimaryMenu();
      }
      return $previewUpdated;
    }
    $hash = self::commitHashFromField((string)$row['hash']);
    if ($hash === '') {
      if ($preserveFocus) {
        \MADB\Main\ScreenController::suspendWorkspaceFocusForPrimaryMenu();
      }
      return $previewUpdated;
    }
    if (!$force && (self::$selectedCommit['hash'] ?? '') === $hash) {
      if ($preserveFocus) {
        \MADB\Main\ScreenController::suspendWorkspaceFocusForPrimaryMenu();
      }
      return $previewUpdated;
    }
    self::loadCommit($hash, self::commitShortFromHashField((string)$row['hash']));
    if ($preserveFocus) {
      \MADB\Main\ScreenController::suspendWorkspaceFocusForPrimaryMenu();
    }
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
      'commit' => $commit,
      'preserveFocus' => self::$preserveMenuFocus,
      'table' => $file
    ]);
    return true;
  }

  public static function selectFileByShortcut(int $shortcut): bool {
    $list = \SPTK\Element::byName('query-list');
    if ($list === false || !method_exists($list, 'getItems') || !method_exists($list, 'moveCursor')) {
      return false;
    }
    $items = $list->getItems();
    if (empty($items)) {
      return false;
    }
    $index = $shortcut === 0 ? count($items) - 1 : $shortcut - 1;
    if ($index < 0 || $index >= count($items)) {
      return false;
    }
    $list->moveCursor($index);
    \SPTK\Element::refresh();
    return true;
  }

  public static function moveFileSelection(int $direction): bool {
    if ($direction === 0) {
      return false;
    }
    $list = \SPTK\Element::byName('query-list');
    if ($list === false || !method_exists($list, 'getItems') || !method_exists($list, 'moveCursor')) {
      return false;
    }
    if (empty($list->getItems())) {
      return false;
    }
    $list->moveCursor($direction < 0 ? -1 : 1, true);
    \SPTK\Element::refresh();
    return true;
  }

  public static function filesLoaded($response): void {
    if (!self::isCurrentResponse($response)) {
      return;
    }
    $preserveFocus = !empty($response['preserveFocus']);
    $commit = (string)($response['commit'] ?? '');
    if ($commit !== '' && $commit !== (self::$selectedCommit['hash'] ?? '')) {
      return;
    }
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
    } else {
      \MADB\Main\ScreenController::setWorkspaceEditorText('Diff ' . (self::$selectedCommit['short'] ?? ''), '', true);
    }
    if ($preserveFocus && \MADB\Main\ScreenController::hasOpenMenuBox()) {
      \MADB\Main\ScreenController::suspendWorkspaceFocusForPrimaryMenu();
    }
  }

  public static function diffLoaded($response): void {
    if (!self::isCurrentResponse($response)) {
      return;
    }
    $preserveFocus = !empty($response['preserveFocus']);
    $commit = (string)($response['commit'] ?? '');
    if ($commit !== '' && $commit !== (self::$selectedCommit['hash'] ?? '')) {
      return;
    }
    if (($response['status'] ?? 'ERROR') !== 'OK') {
      \SPTK\Elements\ErrorPanel::forge('Could not load diff', $response['result'] ?? 'Unknown Git error.');
      return;
    }
    $file = (string)($response['table'] ?? '');
    $title = 'Diff ' . (self::$selectedCommit['short'] ?? '') . ($file === '' ? '' : ' ' . $file);
    \MADB\Main\ScreenController::setWorkspaceEditorText($title, (string)$response['result'], true);
    if ($preserveFocus && \MADB\Main\ScreenController::hasOpenMenuBox()) {
      \MADB\Main\ScreenController::suspendWorkspaceFocusForPrimaryMenu();
    }
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
      'callback' => ['\MADB\Engine\Git\WorkspaceController', 'filesLoaded'],
      'commit' => $hash,
      'preserveFocus' => self::$preserveMenuFocus
    ]);
  }

  private static function reloadSelectedFile(): void {
    $list = \SPTK\Element::byName('query-list');
    if ($list !== false) {
      self::selectFile($list);
    }
  }

  private static function clearSelection(): void {
    self::$selectedCommit = [];
    self::$selectedFile = '';
  }

  private static function isCurrentResponse($response): bool {
    $connectionName = $response['connection']['name'] ?? false;
    $current = \MADB\Connection\ConnectionList::getInstance()->current;
    return $connectionName !== false && ($current['name'] ?? false) === $connectionName;
  }

  private static function focusCommitGridWhenReady(bool $preserveFocus): void {
    if (!$preserveFocus || !\MADB\Main\ScreenController::hasOpenMenuBox()) {
      \MADB\Main\ScreenController::activateResult();
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
