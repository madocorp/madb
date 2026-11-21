<?php

namespace MADB\App;

use SPTK\Core\AppData;

/** Persist named query tabs and their active selection for each saved connection. */
final class QueryList {

  private array $connections;

  /** Load saved tabs, importing the former execution history once if needed. */
  public function __construct() {
    $file = AppData::file('query-list.json');
    $data = AppData::loadJson('query-list.json');
    $this->connections = is_array($data['connections'] ?? null) ? $data['connections'] : [];
    if (!is_file($file)) {
      $this->importHistory();
    }
  }

  /** Ensure a connection has an active blank query using its saved default database. */
  public function ensure(string $connectionId, string $defaultDatabase = ''): array {
    $active = $this->active($connectionId);
    return $active ?? $this->create($connectionId, database: $defaultDatabase);
  }

  /** Give older saved queries the connection's starting database once. */
  public function initializeDatabases(string $connectionId, string $defaultDatabase): void {
    $changed = false;
    foreach ($this->connections[$connectionId]['queries'] ?? [] as $index => $entry) {
      if (!is_string($entry['database'] ?? null)) {
        $this->connections[$connectionId]['queries'][$index]['database'] = $defaultDatabase;
        $changed = true;
      }
    }
    if ($changed) {
      $this->save();
    }
  }

  /** Return stored queries in display order. */
  public function entries(string $connectionId): array {
    return array_values(array_filter($this->connections[$connectionId]['queries'] ?? [], static fn(mixed $entry): bool => is_array($entry)
      && is_string($entry['id'] ?? null) && is_string($entry['name'] ?? null) && is_string($entry['text'] ?? null)));
  }

  /** Return the active query identifier, if present. */
  public function activeId(string $connectionId): ?string {
    return $this->connections[$connectionId]['active'] ?? null;
  }

  /** Return a saved query by identifier. */
  public function get(string $connectionId, string $queryId): ?array {
    foreach ($this->entries($connectionId) as $entry) {
      if ($entry['id'] === $queryId) {
        return $entry;
      }
    }
    return null;
  }

  /** Return the active query. */
  public function active(string $connectionId): ?array {
    return $this->get($connectionId, $this->activeId($connectionId) ?? '');
  }

  /** Select one existing query. */
  public function select(string $connectionId, string $queryId): bool {
    if ($this->get($connectionId, $queryId) === null) {
      return false;
    }
    if ($this->activeId($connectionId) !== $queryId) {
      $this->connections[$connectionId]['active'] = $queryId;
      $this->save();
    }
    return true;
  }

  /** Create a new query after the pinned section and select it. */
  public function create(string $connectionId, string $name = 'NEW', string $text = '', ?string $database = null): array {
    $database ??= $this->active($connectionId)['database'] ?? '';
    $entry = ['id' => bin2hex(random_bytes(8)), 'name' => $name, 'text' => $text, 'database' => $database,
      'pinned' => false, 'status' => 'new'];
    $this->connections[$connectionId] ??= ['active' => null, 'queries' => []];
    array_splice($this->connections[$connectionId]['queries'], $this->pinnedCount($connectionId), 0, [$entry]);
    $this->connections[$connectionId]['active'] = $entry['id'];
    $this->save();
    return $entry;
  }

  /** Persist edited text only when it changed. */
  public function updateText(string $connectionId, string $queryId, string $text): void {
    $index = $this->index($connectionId, $queryId);
    if ($index !== null && $this->connections[$connectionId]['queries'][$index]['text'] !== $text) {
      $this->connections[$connectionId]['queries'][$index]['text'] = $text;
      $this->save();
    }
  }

  /** Persist the database associated with one saved query. */
  public function updateDatabase(string $connectionId, string $queryId, string $database): void {
    $index = $this->index($connectionId, $queryId);
    if ($index !== null && ($this->connections[$connectionId]['queries'][$index]['database'] ?? null) !== $database) {
      $this->connections[$connectionId]['queries'][$index]['database'] = $database;
      $this->save();
    }
  }

  /** Rename a query, rejecting an empty or multiline label. */
  public function rename(string $connectionId, string $queryId, string $name): bool {
    $name = trim($name);
    if ($name === '' || !mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $name)) {
      return false;
    }
    $index = $this->index($connectionId, $queryId);
    if ($index === null) {
      return false;
    }
    $this->connections[$connectionId]['queries'][$index]['name'] = $name;
    $this->save();
    return true;
  }

  /** Clone the active query as an unpinned new tab. */
  public function cloneActive(string $connectionId): ?array {
    $active = $this->active($connectionId);
    return $active === null ? null : $this->create($connectionId, $active['name'] . ' copy', $active['text'], $active['database'] ?? '');
  }

  /** Move the active query into or out of the pinned section. */
  public function togglePin(string $connectionId): ?array {
    $index = $this->index($connectionId, $this->activeId($connectionId) ?? '');
    if ($index === null) {
      return null;
    }
    $entry = $this->connections[$connectionId]['queries'][$index];
    $this->movePin($connectionId, $entry['id'], !($entry['pinned'] ?? false));
    $this->save();
    return $this->get($connectionId, $entry['id']);
  }

  /** Apply the marked rows as pins, moving each changed row to its section's front. */
  public function setPinned(string $connectionId, array $pinnedIds): bool {
    $wanted = array_fill_keys($pinnedIds, true);
    $changed = false;
    foreach ($this->entries($connectionId) as $entry) {
      $pin = isset($wanted[$entry['id']]);
      if ($pin !== (bool)($entry['pinned'] ?? false)) {
        $this->movePin($connectionId, $entry['id'], $pin);
        $changed = true;
      }
    }
    if ($changed) {
      $this->save();
    }
    return $changed;
  }

  /** Keep row order and change only the moved row's pin when it crosses the boundary. */
  public function reorder(string $connectionId, array $ids, string $movedId): void {
    $pinnedCount = $this->pinnedCount($connectionId);
    $moved = $this->get($connectionId, $movedId);
    $byId = [];
    foreach ($this->entries($connectionId) as $entry) {
      $byId[$entry['id']] = $entry;
    }
    $ordered = [];
    foreach ($ids as $id) {
      if (isset($byId[$id])) {
        $ordered[] = $byId[$id];
        unset($byId[$id]);
      }
    }
    array_push($ordered, ...array_values($byId));
    foreach ($ordered as $index => &$entry) {
      if ($entry['id'] === $movedId && $moved !== null) {
        if (($moved['pinned'] ?? false) && $index >= $pinnedCount) {
          $entry['pinned'] = false;
        } else if (!($moved['pinned'] ?? false) && $index < $pinnedCount) {
          $entry['pinned'] = true;
        }
        break;
      }
    }
    unset($entry);
    $this->connections[$connectionId]['queries'] = $ordered;
    $this->save();
  }

  /** Remove the active tab and select a neighbor, creating a blank tab if needed. */
  public function deleteActive(string $connectionId): array {
    $index = $this->index($connectionId, $this->activeId($connectionId) ?? '');
    if ($index === null) {
      return $this->ensure($connectionId);
    }
    $database = $this->connections[$connectionId]['queries'][$index]['database'] ?? '';
    array_splice($this->connections[$connectionId]['queries'], $index, 1);
    $next = $this->connections[$connectionId]['queries'][min($index, count($this->connections[$connectionId]['queries']) - 1)] ?? null;
    if ($next === null) {
      return $this->create($connectionId, database: $database);
    }
    $this->connections[$connectionId]['active'] = $next['id'];
    $this->save();
    return $next;
  }

  /** Record a query's latest execution state without adding a list entry. */
  public function status(string $connectionId, string $queryId, string $status): void {
    $index = $this->index($connectionId, $queryId);
    if ($index !== null) {
      $this->connections[$connectionId]['queries'][$index]['status'] = $status;
      $this->save();
    }
  }

  /** Remove saved tabs when their connection is deleted. */
  public function deleteConnection(string $connectionId): void {
    unset($this->connections[$connectionId]);
    $this->save();
  }

  /** Find one query in the stored array. */
  private function index(string $connectionId, string $queryId): ?int {
    foreach ($this->connections[$connectionId]['queries'] ?? [] as $index => $entry) {
      if (($entry['id'] ?? null) === $queryId) {
        return $index;
      }
    }
    return null;
  }

  /** Count the leading pinned queries. */
  private function pinnedCount(string $connectionId): int {
    $count = 0;
    foreach ($this->connections[$connectionId]['queries'] ?? [] as $entry) {
      if (!($entry['pinned'] ?? false)) {
        break;
      }
      $count++;
    }
    return $count;
  }

  /** Move one row to the front of the pinned or unpinned section. */
  private function movePin(string $connectionId, string $queryId, bool $pinned): void {
    $index = $this->index($connectionId, $queryId);
    if ($index === null) {
      return;
    }
    $entry = array_splice($this->connections[$connectionId]['queries'], $index, 1)[0];
    $entry['pinned'] = $pinned;
    $position = $pinned ? 0 : $this->pinnedCount($connectionId);
    array_splice($this->connections[$connectionId]['queries'], $position, 0, [$entry]);
  }

  /** Convert existing execution rows into named tabs without deleting the old file. */
  private function importHistory(): void {
    $old = AppData::loadJson('query-history.json');
    foreach ($old['connections'] ?? [] as $connectionId => $history) {
      if (!is_string($connectionId) || !is_array($history)) {
        continue;
      }
      $queries = [];
      foreach ($history as $entry) {
        if (!is_array($entry) || !is_string($entry['query'] ?? null)) {
          continue;
        }
        $first = trim(explode("\n", trim($entry['query']), 2)[0]);
        $queries[] = ['id' => is_string($entry['id'] ?? null) ? $entry['id'] : bin2hex(random_bytes(8)),
          'name' => mb_strimwidth($first ?: 'NEW', 0, 48, '…'), 'text' => $entry['query'], 'pinned' => false,
          'status' => match ($entry['state'] ?? '') { 'done' => 'ok', 'error' => 'error', default => 'new' }];
      }
      if ($queries !== []) {
        $this->connections[$connectionId] = ['active' => $queries[0]['id'], 'queries' => $queries];
      }
    }
    if ($this->connections !== []) {
      $this->save();
    }
  }

  /** Write the per-connection query list with private permissions. */
  private function save(): void {
    if (!AppData::saveJson('query-list.json', ['connections' => $this->connections])) {
      throw new \RuntimeException('Could not save the query list.');
    }
  }

}
