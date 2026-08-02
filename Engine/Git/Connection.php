<?php

namespace MADB\Engine\Git;

class Connection extends \MADB\Connection\Connection {

  private string $path = '';
  private array|false $serverInfo = false;

  public static function getDefaults() {
    return [
      'name' => 'new',
      'path' => '',
      'remote' => 'origin',
      'limit' => '500'
    ];
  }

  public static function supportsOperation($operation): bool {
    return in_array($operation, [
      'schemaList'
    ], true);
  }

  public function connect() {
    $path = $this->expandPath(trim((string)($this->data['path'] ?? '')));
    if ($path === '') {
      throw new \Exception('Missing Git repository directory.');
    }
    if (!is_dir($path)) {
      throw new \Exception("Repository directory does not exist: {$path}");
    }
    $root = $this->runGit(['rev-parse', '--show-toplevel'], $path);
    $this->path = trim($root);
    if ($this->path === '' || !is_dir($this->path)) {
      throw new \Exception("Not a Git repository: {$path}");
    }
    $this->serverInfo = $this->detectServerInfo();
  }

  public function test() {
    return [
      'message' => 'The Git repository was opened successfully.',
      'serverInfo' => $this->getServerInfo()
    ];
  }

  public function getServerInfo() {
    return $this->serverInfo;
  }

  public function schemaList() {
    $branches = [];
    foreach (explode("\n", trim($this->runGit(['branch', '--format=%(refname:short)']))) as $branch) {
      $branch = trim($branch);
      if ($branch !== '') {
        $branches[] = $branch;
      }
    }
    $this->queryTime = microtime(true);
    return $branches;
  }

  public function tableList($schema) {
    $this->queryTime = microtime(true);
    return [];
  }

  public function commitLog($branch, $resultFile, $relativeFile = false) {
    $columns = self::commitColumns();
    $rows = $this->commitRows((string)$branch);
    $this->writeTsvFile((string)$resultFile, $columns, $rows);
    $this->queryTime = microtime(true);
    return [
      'columns' => $columns,
      'rowCount' => count($rows),
      'file' => $relativeFile === false ? $resultFile : $relativeFile
    ];
  }

  public function commitFiles($commit) {
    $commit = $this->normalizeCommit($commit);
    $output = $this->runGit(['diff-tree', '--no-commit-id', '--name-status', '-r', $commit]);
    $files = [];
    foreach (explode("\n", trim($output)) as $line) {
      if ($line === '') {
        continue;
      }
      $parts = explode("\t", $line);
      $status = array_shift($parts);
      $path = implode("\t", $parts);
      if ($path !== '') {
        $files[] = [
          'status' => $status,
          'path' => $path
        ];
      }
    }
    $this->queryTime = microtime(true);
    return $files;
  }

  public function fileDiff($commit, $file, $context = 3) {
    $commit = $this->normalizeCommit($commit);
    $file = (string)$file;
    if ($file === '') {
      throw new \Exception('Missing file path.');
    }
    $context = max(0, min(10000, (int)$context));
    $this->queryTime = microtime(true);
    return $this->runGit(['show', '--format=', '--patch', '--unified=' . $context, $commit, '--', $file]);
  }

  public function fetchRemote() {
    $remote = trim((string)($this->data['remote'] ?? ''));
    if ($remote === '') {
      throw new \Exception('Missing Git remote name.');
    }
    $this->queryTime = microtime(true);
    return trim($this->runGit(['fetch', $remote]));
  }

  public function createSchema($schema) {
    throw new \Exception('Git branch creation is not implemented in this read-only prototype.');
  }

  public function schemaInfo($schema) {
    return ['commits' => count($this->commitRows((string)$schema))];
  }

  public function renameSchemaInfo($schema, $targetSchema) {
    throw new \Exception('Git branch rename is not implemented in this read-only prototype.');
  }

  public function renameSchema($schema, $targetSchema) {
    throw new \Exception('Git branch rename is not implemented in this read-only prototype.');
  }

  public function dropSchema($schema) {
    throw new \Exception('Git branch deletion is not implemented in this read-only prototype.');
  }

  public function characterSetsAndCollations() {
    return ['charsets' => [], 'collations' => [], 'engines' => []];
  }

  public function tableFields($schema, $table) {
    return [];
  }

  public function tableDefinition($schema, $table) {
    return [];
  }

  public function tableReferencedBy($schema, $table) {
    return [];
  }

  public function rowEditorDefinition($schema, $table) {
    return [];
  }

  public function query($sql) {
    throw new \Exception('Free-form Git command execution is not implemented.');
  }

  public function queryBatch($statements, $resultFiles = [], $schema = false, $progress = false, $cancelled = false) {
    throw new \Exception('Free-form Git command execution is not implemented.');
  }

  private function commitRows(string $branch): array {
    if ($branch === '') {
      return [];
    }
    $limit = max(1, min(5000, (int)($this->data['limit'] ?? 500)));
    $format = implode('%x1f', [
      '%H',
      '%h',
      '%P',
      '%an',
      '%ae',
      '%ad',
      '%cn',
      '%ce',
      '%cd',
      '%D',
      '%B'
    ]) . '%x1e';
    $output = $this->runGit([
      'log',
      '--date=iso-strict',
      '--pretty=format:' . $format,
      '--max-count=' . $limit,
      $branch
    ]);
    $rows = [];
    foreach (explode("\x1e", $output) as $record) {
      $record = trim($record, "\r\n");
      if ($record === '') {
        continue;
      }
      $parts = array_pad(explode("\x1f", $record, 11), 11, '');
      $description = trim($parts[10]);
      $authorDate = $parts[5];
      $rows[] = [
        'hash' => $this->commitHashField($parts[1], $parts[0], $parts[2], $parts[9]),
        'author_date' => $authorDate,
        'author' => $this->commitAuthorField($parts[3], $parts[4], $parts[6], $parts[7]),
        'date' => $this->commitDateField($authorDate, $parts[8]),
        'description' => $description
      ];
    }
    return $rows;
  }

  private static function commitColumns(): array {
    return [
      'hash',
      'description',
      'author',
      'date'
    ];
  }

  private function commitHashField(string $short, string $hash, string $parents, string $refs): string {
    $lines = [];
    if ($short !== '') {
      $lines[] = $short;
    }
    if ($hash !== '') {
      $lines[] = 'Hash: ' . $hash;
    }
    if ($parents !== '') {
      $lines[] = 'Parents: ' . $parents;
    }
    if ($refs !== '') {
      $lines[] = 'Refs: ' . $refs;
    }
    return implode("\n", $lines);
  }

  private function commitDateField(string $authorDate, string $committerDate): string {
    $lines = [];
    if ($authorDate !== '') {
      $lines[] = $this->formatDate($authorDate);
    }
    if ($committerDate !== '') {
      $lines[] = 'Committer date: ' . $this->formatDate($committerDate);
    }
    return implode("\n", $lines);
  }

  private function formatDate(string $date): string {
    $time = strtotime($date);
    if ($time === false) {
      return $date;
    }
    return date('Y-m-d H:i:s', $time);
  }

  private function commitAuthorField(string $authorName, string $authorEmail, string $committerName, string $committerEmail): string {
    $lines = [];
    if ($authorName !== '') {
      $lines[] = $authorName;
    }
    if ($authorEmail !== '') {
      $lines[] = 'Author email: ' . $authorEmail;
    }
    if ($committerName !== '') {
      $lines[] = 'Committer: ' . $committerName;
    }
    if ($committerEmail !== '') {
      $lines[] = 'Committer email: ' . $committerEmail;
    }
    return implode("\n", $lines);
  }

  private function detectServerInfo(): array {
    $version = trim($this->runGit(['--version']));
    $branch = trim($this->runGit(['branch', '--show-current']));
    return [
      'vendorLabel' => 'Git',
      'version' => $version,
      'database' => $this->path,
      'branch' => $branch
    ];
  }

  private function runGit(array $arguments, string $cwd = null): string {
    $cwd = $cwd ?? $this->path;
    $command = 'git';
    foreach ($arguments as $argument) {
      $command .= ' ' . escapeshellarg((string)$argument);
    }
    $descriptorSpec = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w']
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
    if (!is_resource($process)) {
      throw new \Exception('Could not start git.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
      throw new \Exception(trim($stderr) === '' ? 'Git command failed.' : trim($stderr));
    }
    return (string)$stdout;
  }

  private function writeTsvFile(string $file, array $columns, array $rows): void {
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
      throw new \Exception('Could not create result directory.');
    }
    $handle = fopen($file, 'wb');
    if ($handle === false) {
      throw new \Exception('Could not create result file.');
    }
    try {
      $this->writeTsvLine($handle, $columns);
      foreach ($rows as $row) {
        $values = [];
        foreach ($columns as $column) {
          $values[] = $row[$column] ?? '';
        }
        $this->writeTsvLine($handle, $values);
      }
    } finally {
      fclose($handle);
    }
  }

  private function writeTsvLine($handle, array $values): void {
    $fields = [];
    foreach ($values as $value) {
      $fields[] = str_replace(
        ["\\", "\t", "\n", "\r"],
        ["\\\\", "\\t", "\\n", "\\r"],
        (string)$value
      );
    }
    if (fwrite($handle, implode("\t", $fields) . "\n") === false) {
      throw new \Exception('Could not write result file.');
    }
  }

  private function normalizeCommit($commit): string {
    $commit = trim((string)$commit);
    if (!preg_match('/^[0-9a-fA-F]{4,40}$/', $commit)) {
      throw new \Exception('Invalid commit hash.');
    }
    return $commit;
  }

  private function expandPath(string $path): string {
    if ($path === '~' || strpos($path, '~/') === 0) {
      $home = getenv('HOME');
      if ($home !== false && $home !== '') {
        return $home . substr($path, 1);
      }
    }
    return $path;
  }

}
