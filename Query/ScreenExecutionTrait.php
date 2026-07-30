<?php

namespace MADB\Query;

use \SPTK\SDLWrapper\KeyCombo;
use \SPTK\SDLWrapper\Action;
use \SPTK\SDLWrapper\KeyCode;
use \SPTK\SDLWrapper\KeyModifier;
use \SPTK\SDLWrapper\ScanCode;
use \SPTK\SDLWrapper\SDL;
use \SPTK\Element;
use \MADB\List\QueryList;
use \MADB\Result\ResultStore;
use \MADB\Query\SqlSplitter;

/**
 * Starts query execution from the editor and prepares batch state before handing work to the background job system.
 */
trait ScreenExecutionTrait {

  /** Coordinates execute query work in the query workspace. */
  public static function executeQuery() {
    self::confirmExecuteStatements(false);
  }

  /** Coordinates execute current query work in the query workspace. */
  public static function executeCurrentQuery() {
    self::confirmExecuteStatements(true);
  }

  /** Executes an existing query tab by id without opening the normal confirmation prompt. */
  public static function executeQueryById($connectionName, $queryId) {
    $connection = self::getCurrentConnection();
    if ($connection === false || ($connection['name'] ?? false) !== $connectionName) {
      self::loadConnection($connectionName);
    }
    if (self::$connectionName === false || self::$queryList->get(self::$connectionName, $queryId) === false) {
      return;
    }
    self::$queryList->setActive(self::$connectionName, $queryId);
    self::renderList();
    self::showQuery($queryId);
    self::confirmExecuteStatements(false);
  }

  /** Coordinates do execute query work in the query workspace. */
  public static function doExecuteQuery($confirmationPanel = null) {
    if ($confirmationPanel !== null) {
      $confirmationPanel->remove();
    }
    self::confirmExecuteStatements(false, true);
  }

  /** Coordinates do execute current query work in the query workspace. */
  public static function doExecuteCurrentQuery($confirmationPanel = null) {
    if ($confirmationPanel !== null) {
      $confirmationPanel->remove();
    }
    self::confirmExecuteStatements(true, true);
  }

  /** Continues full query execution after SQL safety confirmation. */
  public static function doExecuteQuerySafety($confirmationPanel = null) {
    if (!self::validateSqlSafetyConfirmation($confirmationPanel)) {
      return;
    }
    if ($confirmationPanel !== null) {
      $confirmationPanel->remove();
    }
    self::executeStatements(false, true);
  }

  /** Continues current-statement execution after SQL safety confirmation. */
  public static function doExecuteCurrentQuerySafety($confirmationPanel = null) {
    if (!self::validateSqlSafetyConfirmation($confirmationPanel)) {
      return;
    }
    if ($confirmationPanel !== null) {
      $confirmationPanel->remove();
    }
    self::executeStatements(true, true);
  }

  /** Opens or handles the execute statements confirmation step in the query workspace. */
  private static function confirmExecuteStatements($currentOnly, bool $clearConfirmed = false) {
    $connection = self::getCurrentConnection();
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before executing a query.');
      return;
    }
    if (self::$connectionName !== $connection['name']) {
      self::loadConnection($connection['name']);
    }
    $query = self::ensureActiveQuery();
    if ($query === false) {
      return;
    }
    if (self::isLocked($query)) {
      \SPTK\Elements\WarningPanel::forge('Query is locked', 'This query has already started running and cannot be executed again.');
      return;
    }
    if (!$clearConfirmed && self::hasResult($query) && self::shouldWarnBeforeClear($query)) {
      \SPTK\Elements\WarningPanel::forge(
        $currentOnly ? 'Execute query' : 'Execute queries',
        "Execute query '" . ($query['name'] ?? 'NEW') . "' and replace its result set?",
        [
          ['text' => 'Execute', 'hotKey' => 'RETURN', 'onPress' => $currentOnly ? '\MADB\Query\QueryExecutionController::doExecuteCurrentQuery' : '\MADB\Query\QueryExecutionController::doExecuteQuery'],
          ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close']
        ]
      );
      Element::refresh();
      return;
    }
    $statements = self::statementsForExecutionPreview($currentOnly);
    if ($statements === false) {
      return;
    }
    $issues = self::language()->safetyIssues($statements);
    if (!empty($issues)) {
      self::showLanguageSafetyConfirmation(
        $issues,
        $currentOnly ? '\MADB\Query\QueryExecutionController::doExecuteCurrentQuerySafety' : '\MADB\Query\QueryExecutionController::doExecuteQuerySafety'
      );
      return;
    }
    self::executeStatements($currentOnly, true);
  }

  /** Coordinates execute statements work in the query workspace. */
  private static function executeStatements($currentOnly, bool $safetyConfirmed = false) {
    $connection = self::getCurrentConnection();
    if ($connection === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before executing a query.');
      return;
    }
    if (self::$connectionName !== $connection['name']) {
      self::loadConnection($connection['name']);
    }
    $query = self::ensureActiveQuery();
    if ($query === false) {
      return;
    }
    if (self::isLocked($query)) {
      \SPTK\Elements\WarningPanel::forge('Query is locked', 'This query has already started running and cannot be executed again.');
      return;
    }
    $sql = self::editorText();
    $language = self::language();
    $allStatements = $language->split($sql);
    foreach ($allStatements as $index => $statement) {
      $allStatements[$index]['index'] = $index;
    }
    if (empty($allStatements)) {
      \SPTK\Elements\WarningPanel::forge('Query is empty', 'Please enter a query before executing it.');
      return;
    }
    $cursorOffset = self::byteOffsetFromCursorState($sql, self::captureEditorState());
    $activeStatement = 0;
    $statements = $allStatements;
    if ($currentOnly) {
      $statement = $language->statementAt($sql, $cursorOffset);
      if ($statement === false) {
        \SPTK\Elements\WarningPanel::forge('Query is empty', 'Please enter a query before executing it.');
        return;
      }
      foreach ($allStatements as $index => $candidate) {
        if (($candidate['start'] ?? false) === ($statement['start'] ?? null) && ($candidate['end'] ?? false) === ($statement['end'] ?? null)) {
          $activeStatement = $index;
          break;
        }
      }
      $statements = [$allStatements[$activeStatement]];
    }
    $sql = self::applyAutomaticSelectLimits($sql, $allStatements, $currentOnly ? [$activeStatement] : false);
    $allStatements = $language->split($sql);
    foreach ($allStatements as $index => $statement) {
      $allStatements[$index]['index'] = $index;
    }
    if ($currentOnly) {
      $statement = $language->statementAt($sql, $cursorOffset);
      foreach ($allStatements as $index => $candidate) {
        if (($candidate['start'] ?? false) === ($statement['start'] ?? null) && ($candidate['end'] ?? false) === ($statement['end'] ?? null)) {
          $activeStatement = $index;
          break;
        }
      }
      $statements = [$allStatements[$activeStatement]];
    } else {
      $statements = $allStatements;
    }
    $statements = $language->executionStatements($statements);
    if (!$safetyConfirmed) {
      $issues = $language->safetyIssues($statements);
      if (!empty($issues)) {
        self::showLanguageSafetyConfirmation(
          $issues,
          $currentOnly ? '\MADB\Query\QueryExecutionController::doExecuteCurrentQuerySafety' : '\MADB\Query\QueryExecutionController::doExecuteQuerySafety'
        );
        return;
      }
    }
    $pendingStatements = [];
    $startedAt = microtime(true);
    foreach ($allStatements as $statement) {
      $index = $statement['index'] ?? count($pendingStatements);
      $willRun = !$currentOnly || $index === $activeStatement;
      $statementSql = $willRun ? ($language->executionStatements([$statement])[0]['sql'] ?? '') : (string)($statement['sql'] ?? '');
      $pendingStatements[] = [
        'index' => $index,
        'sql' => trim($statementSql),
        'status' => $willRun ? 'PENDING' : 'NOT RUN',
        'startedAt' => $willRun ? $startedAt : false,
        'range' => [
          'start' => $statement['start'] ?? 0,
          'end' => $statement['end'] ?? 0
        ]
      ];
    }
    self::saveCurrentEditor();
    $query = self::$queryList->getActive(self::$connectionName);
    $schema = self::currentPrimary($query);
    $keptResults = [];
    if ($currentOnly) {
      foreach (($query['results'] ?? []) as $result) {
        if ((int) ($result['statementIndex'] ?? -1) === $activeStatement) {
          ResultStore::delete($result['file'] ?? false);
          continue;
        }
        $keptResults[] = $result;
      }
      $pendingStatements = self::preserveStatementResults($pendingStatements, $query['statements'] ?? [], $activeStatement);
    } else {
      ResultStore::delete($query['resultFile'] ?? false);
      ResultStore::deleteMany($query['results'] ?? []);
    }
    $resultFile = ResultStore::relativePath(self::$connectionName, $query['id']);
    $chunkSize = self::batchChunkSizeForStatementCount(count($statements));
    $query = self::$queryList->update(self::$connectionName, $query['id'], [
      'status' => 'running',
      'result' => false,
      'resultFile' => $resultFile,
      'statements' => $pendingStatements,
      'results' => $keptResults,
      'activeResult' => 0,
      'activeStatement' => $activeStatement,
      'unseenResult' => false,
      'error' => false,
      'info' => [
        'batch' => [
          'chunkSize' => $chunkSize,
          'currentOnly' => $currentOnly,
          'startedAt' => $startedAt,
          'schema' => $schema
        ]
      ]
    ]);
    self::renderList();
    self::showQuery($query['id']);
    self::dispatchNextQueryBatchChunk(self::$connectionName, $query['id'], $connection);
    Element::refresh();
  }

  /** Dispatches the next pending query batch chunk to the worker. */
  private static function dispatchNextQueryBatchChunk($connectionName, $queryId, $connection) {
    $query = self::$queryList->get($connectionName, $queryId);
    if ($query === false || ($query['status'] ?? 'new') !== 'running') {
      return false;
    }
    $info = is_array($query['info'] ?? false) ? $query['info'] : [];
    $batch = is_array($info['batch'] ?? false) ? $info['batch'] : [];
    $chunkSize = max(1, (int)($batch['chunkSize'] ?? self::QUERY_LARGE_BATCH_MIN_CHUNK_SIZE));
    $pending = self::pendingBatchStatements($query['statements'] ?? []);
    if (empty($pending)) {
      return false;
    }
    $chunk = array_slice($pending, 0, $chunkSize);
    $resultIndexBase = self::resultIndexBaseForChunk($query, $chunk);
    $resultFiles = self::resultFilesForChunk($connectionName, $queryId, $resultIndexBase, count($chunk));
    $jobId = \MADB\Job\JobHandler::startJob([
      'connection' => $connection,
      'command' => 'queryBatch',
      'arguments' => [$chunk, $resultFiles, $batch['schema'] ?? false],
      'queryId' => $queryId,
      'chunkResultIndexBase' => $resultIndexBase,
      'callback' => ['\MADB\Main\ScreenController', 'queryResult']
    ]);
    $info['jid'] = $jobId;
    self::$queryList->update($connectionName, $queryId, [
      'info' => $info
    ]);
    return true;
  }

  /** Returns statements that still need to be sent to a worker chunk. */
  private static function pendingBatchStatements($statements): array {
    $pending = [];
    foreach (is_array($statements) ? $statements : [] as $statement) {
      if (($statement['status'] ?? '') === 'PENDING') {
        $pending[] = $statement;
      }
    }
    return $pending;
  }

  /** Chooses chunk size for a batch using small-batch highlighting and large-batch dispatch bounds. */
  private static function batchChunkSizeForStatementCount(int $statementCount): int {
    if ($statementCount < self::QUERY_SMALL_BATCH_LIMIT) {
      return self::QUERY_SMALL_BATCH_CHUNK_SIZE;
    }
    return min(
      self::QUERY_LARGE_BATCH_MAX_CHUNK_SIZE,
      max(
        self::QUERY_LARGE_BATCH_MIN_CHUNK_SIZE,
        (int)ceil($statementCount / self::QUERY_LARGE_BATCH_CHUNK_DIVISOR)
      )
    );
  }

  /** Chooses the global result-file/result-index base for a dispatched chunk. */
  private static function resultIndexBaseForChunk($query, array $chunk): int {
    $batch = $query['info']['batch'] ?? [];
    if (!empty($batch['currentOnly']) && count($chunk) === 1) {
      return (int)($chunk[0]['index'] ?? 0);
    }
    return count(is_array($query['results'] ?? false) ? $query['results'] : []);
  }

  /** Builds absolute result files for table-producing statements in a chunk. */
  private static function resultFilesForChunk($connectionName, $queryId, int $resultIndexBase, int $count): array {
    $files = [];
    for ($index = 0; $index < $count; $index++) {
      $files[] = ResultStore::absolutePath(ResultStore::relativePathForResult($connectionName, $queryId, $resultIndexBase + $index));
    }
    return $files;
  }

  /** Opens a confirmation panel for interrupting or killing the active running query. */
  public static function showInterruptQueryPanel() {
    if (self::activeRunningQuery() === false) {
      return false;
    }
    \SPTK\Elements\WarningPanel::forge(
      'Interrupt query',
      "Stop the current query batch?\n\nStop lets the active chunk finish, then prevents following chunks from running.\nKill terminates the worker process immediately.",
      [
        ['text' => 'Stop', 'hotKey' => 'RETURN', 'onPress' => '\MADB\Main\ScreenController::stopRunningQuery'],
        ['text' => 'Kill', 'hotKey' => 'F10', 'onPress' => '\MADB\Main\ScreenController::killRunningQuery'],
        ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close']
      ]
    );
    return true;
  }

  /** Stops the active running query batch after the current statement finishes. */
  public static function stopRunningQuery($panel = null) {
    if ($panel !== null && method_exists($panel, 'remove')) {
      $panel->remove();
    }
    $stopped = self::interruptQuery();
    if ($stopped && self::$connectionName !== false) {
      $query = self::$queryList->getActive(self::$connectionName);
      if ($query !== false) {
        self::showQuery($query['id'], false, true);
      }
    }
    Element::refresh();
    return $stopped;
  }

  /** Kills the active running query worker process. */
  public static function killRunningQuery($panel = null) {
    if ($panel !== null && method_exists($panel, 'remove')) {
      $panel->remove();
    }
    $killed = self::killQueryWorker();
    Element::refresh();
    return $killed;
  }

  /** Requests that the active running query stops before dispatching another chunk. */
  private static function interruptQuery() {
    $query = self::activeRunningQuery();
    if ($query === false) {
      return false;
    }
    self::markRunningQueryControlRequested($query, 'interruptRequested');
    return true;
  }

  /** Kills the active running query worker when waiting for the current statement is not enough. */
  private static function killQueryWorker() {
    $query = self::activeRunningQuery();
    if ($query === false) {
      return false;
    }
    $target = self::activeRunningJobTarget($query);
    if ($target === false) {
      return false;
    }
    self::markRunningQueryControlRequested($query, 'killRequested');
    \MADB\Job\JobHandler::startJob([
      'connection' => self::getCurrentConnection(),
      'command' => 'killJob',
      'targetJid' => $target['jid'],
      'pid' => $target['pid']
    ]);
    return true;
  }

  /** Returns the active query only when it is currently running. */
  private static function activeRunningQuery() {
    if (self::$connectionName === false || self::$queryList === null) {
      return false;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false || ($query['status'] ?? 'new') !== 'running') {
      return false;
    }
    return $query;
  }

  /** Returns the active running job id/pid target values. */
  private static function activeRunningJobTarget($query) {
    $jid = $query['info']['jid'] ?? false;
    $pid = $query['info']['pid'] ?? false;
    if ($jid === false && $pid === false) {
      return false;
    }
    return [
      'jid' => $jid,
      'pid' => $pid
    ];
  }

  /** Records the user's interrupt/kill request in the query info state. */
  private static function markRunningQueryControlRequested($query, string $key): void {
    $info = is_array($query['info'] ?? false) ? $query['info'] : [];
    $info[$key] = true;
    self::$queryList->update(self::$connectionName, $query['id'], [
      'info' => $info
    ]);
  }

  /** Returns runnable statements for pre-execution checks without mutating query state. */
  private static function statementsForExecutionPreview(bool $currentOnly) {
    $sql = self::editorText();
    $language = self::language();
    $allStatements = $language->split($sql);
    foreach ($allStatements as $index => $statement) {
      $allStatements[$index]['index'] = $index;
    }
    if (empty($allStatements)) {
      \SPTK\Elements\WarningPanel::forge('Query is empty', 'Please enter a query before executing it.');
      return false;
    }
    if (!$currentOnly) {
      return $allStatements;
    }
    $statement = $language->statementAt($sql, self::byteOffsetFromCursorState($sql, self::captureEditorState()));
    if ($statement === false) {
      \SPTK\Elements\WarningPanel::forge('Query is empty', 'Please enter a query before executing it.');
      return false;
    }
    foreach ($allStatements as $candidate) {
      if (($candidate['start'] ?? false) === ($statement['start'] ?? null) && ($candidate['end'] ?? false) === ($statement['end'] ?? null)) {
        return [$candidate];
      }
    }
    return [$statement];
  }

  /** Applies automatic SELECT limits to the visible editor text before execution. */
  private static function applyAutomaticSelectLimits(string $sql, array $statements, $selectedIndexes = false): string {
    $limitedSql = self::language()->editorTextForExecution($sql, $statements, $selectedIndexes);
    if ($limitedSql === $sql) {
      return $sql;
    }
    self::replaceEditorText($limitedSql);
    self::saveCurrentEditor();
    return $limitedSql;
  }

  /** Returns statement records with MADB-only SQL markers removed for execution. */
  private static function executionStatements(array $statements): array {
    return self::language()->executionStatements($statements);
  }

  /** Returns SQL safety issues that should be confirmed before execution. */
  public static function sqlSafetyIssues(array $statements): array {
    $issues = [];
    foreach ($statements as $index => $statement) {
      $sql = trim((string)($statement['sql'] ?? ''));
      if ($sql === '') {
        continue;
      }
      $type = self::safetyStatementType($sql);
      if (($type === 'UPDATE' || $type === 'DELETE') && !self::safetyHasTopLevelKeyword($sql, 'WHERE')) {
        $issues[] = [
          'statement' => (int)($statement['index'] ?? $index) + 1,
          'level' => 'pin',
          'message' => $type . ' statement has no WHERE filter.'
        ];
      } else if ($type === 'TRUNCATE' || $type === 'DROP') {
        $issues[] = [
          'statement' => (int)($statement['index'] ?? $index) + 1,
          'level' => 'pin',
          'message' => $type . ' statement is destructive.'
        ];
      }
    }
    return $issues;
  }

  /** Shows a warning or PIN-code confirmation for SQL safety issues. */
  public static function showLanguageSafetyConfirmation(array $issues, string $callback): void {
    $requiresPin = self::language()->safetyRequiresPin($issues);
    $lines = [];
    foreach ($issues as $issue) {
      $lines[] = 'Statement ' . (int)$issue['statement'] . ': ' . $issue['message'];
    }
    if ($requiresPin) {
      $lines[] = '%CONFIRMATION%';
    }
    \SPTK\Elements\WarningPanel::forge(
      $requiresPin ? 'Confirm unsafe query' : 'Query warning',
      implode("\n", $lines),
      [
        ['text' => 'Execute', 'hotKey' => 'RETURN', 'onPress' => $callback],
        ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close']
      ]
    );
    Element::refresh();
  }

  /** Returns whether any SQL safety issue requires PIN-code confirmation. */
  public static function sqlSafetyRequiresPin(array $issues): bool {
    foreach ($issues as $issue) {
      if (($issue['level'] ?? '') === 'pin') {
        return true;
      }
    }
    return false;
  }

  /** Validates PIN-code confirmation when the panel contains one. */
  private static function validateSqlSafetyConfirmation($panel): bool {
    if ($panel === null || !method_exists($panel, 'getValue')) {
      return true;
    }
    $values = $panel->getValue();
    return !array_key_exists('confirmed', $values) || $values['confirmed'] === true;
  }

  /** Returns the primary SQL statement type used by safety checks. */
  private static function safetyStatementType(string $sql): string {
    $words = self::safetyTopLevelWords($sql);
    if (($words[0]['upper'] ?? '') === 'WITH') {
      foreach ($words as $word) {
        if (in_array($word['upper'], ['SELECT', 'UPDATE', 'DELETE'], true)) {
          return $word['upper'];
        }
      }
    }
    foreach ($words as $word) {
      if ($word['upper'] === 'WITH') {
        continue;
      }
      return $word['upper'];
    }
    return '';
  }

  /** Returns whether a keyword appears at top level in a SQL statement. */
  private static function safetyHasTopLevelKeyword(string $sql, string $keyword): bool {
    $keyword = strtoupper($keyword);
    foreach (self::safetyTopLevelWords($sql) as $word) {
      if ($word['upper'] === $keyword) {
        return true;
      }
    }
    return false;
  }

  /** Yields top-level SQL words while ignoring strings, identifiers, comments, and nested expressions. */
  private static function safetyTopLevelWords(string $sql): array {
    $words = [];
    $depth = 0;
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
      $char = $sql[$i];
      if ($char === "'" || $char === '"' || $char === '`') {
        $i = self::safetySkipQuotedSql($sql, $i, $char);
      } else if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
        $i = self::safetySkipLineComment($sql, $i + 2);
      } else if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
        $i = self::safetySkipBlockComment($sql, $i + 2);
      } else if ($char === '(') {
        $depth++;
      } else if ($char === ')') {
        $depth = max(0, $depth - 1);
      } else if ($depth === 0 && preg_match('/[A-Za-z_]/', $char)) {
        $offset = $i;
        while ($i + 1 < $length && preg_match('/[A-Za-z0-9_$]/', $sql[$i + 1])) {
          $i++;
        }
        $words[] = [
          'upper' => strtoupper(substr($sql, $offset, $i - $offset + 1)),
          'offset' => $offset
        ];
      }
    }
    return $words;
  }

  /** Skips a quoted SQL string or identifier. */
  private static function safetySkipQuotedSql(string $sql, int $offset, string $quote): int {
    $length = strlen($sql);
    for ($i = $offset + 1; $i < $length; $i++) {
      if ($sql[$i] === '\\' && $quote !== '`') {
        $i++;
      } else if ($sql[$i] === $quote) {
        if (($sql[$i + 1] ?? '') === $quote) {
          $i++;
          continue;
        }
        return $i;
      }
    }
    return $length - 1;
  }

  /** Skips a line SQL comment. */
  private static function safetySkipLineComment(string $sql, int $offset): int {
    $end = strpos($sql, "\n", $offset);
    return $end === false ? strlen($sql) - 1 : $end;
  }

  /** Skips a block SQL comment. */
  private static function safetySkipBlockComment(string $sql, int $offset): int {
    $end = strpos($sql, '*/', $offset);
    return $end === false ? strlen($sql) - 1 : $end + 1;
  }

}
