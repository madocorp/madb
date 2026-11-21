<?php

namespace MADB\Connection;

use SPTK\Core\AppData;

/** Persist board columns and engine connection definitions in the private app directory. */
final class Store {

  private array $columns = [];
  private array $connections = [];

  /** Load saved board data, accepting only complete records. */
  public function __construct() {
    $data = AppData::loadJson('board.json');
    foreach ($data['columns'] ?? [] as $column) {
      if (is_array($column) && is_string($column['id'] ?? null) && is_string($column['name'] ?? null) && trim($column['name']) !== '') {
        $this->columns[] = $column;
      }
    }
    if ($this->columns === []) {
      $this->columns[] = ['id' => self::id(), 'name' => 'Connections'];
    }
    $usedHotkeys = [];
    foreach ($data['connections'] ?? [] as $connection) {
      if (is_array($connection) && is_string($connection['id'] ?? null) && is_string($connection['name'] ?? null)
        && in_array($connection['engine'] ?? null, ['MySQL', 'SQLite', 'MongoDB'], true)) {
        $hotkey = is_string($connection['hotkey'] ?? null) ? strtolower($connection['hotkey']) : '';
        if (!preg_match('/^[a-z0-9]$/D', $hotkey) || isset($usedHotkeys[$hotkey])) {
          $hotkey = '';
        }
        $connection['hotkey'] = $hotkey;
        if ($hotkey !== '') {
          $usedHotkeys[$hotkey] = true;
        }
        $this->connections[] = $connection;
      }
    }
  }

  /** Return ordered board columns. */
  public function columns(): array {
    return $this->columns;
  }

  /** Return ordered connection records. */
  public function connections(): array {
    return $this->connections;
  }

  /** Find one connection by its stable identifier. */
  public function get(string $id): ?array {
    foreach ($this->connections as $connection) {
      if ($connection['id'] === $id) {
        return $connection;
      }
    }
    return null;
  }

  /** Replace column labels while retaining card assignments by column position. */
  public function setColumns(array $names): bool {
    $old = $this->columns;
    $columns = [];
    foreach ($names as $index => $name) {
      $columns[] = ['id' => $old[$index]['id'] ?? self::id(), 'name' => $name];
    }
    $this->columns = $columns;
    $validIds = array_column($columns, 'id');
    foreach ($this->connections as &$connection) {
      if (!in_array($connection['column'] ?? '', $validIds, true)) {
        $connection['column'] = $columns[0]['id'];
      }
    }
    unset($connection);
    return $this->save();
  }

  /** Create or update a connection without losing its board position. */
  public function put(array $data, ?string $id): ?string {
    foreach ($this->connections as $connection) {
      if (mb_strtolower($connection['name']) === mb_strtolower($data['name']) && $connection['id'] !== $id) {
        return null;
      }
    }
    foreach ($this->connections as $index => $connection) {
      if ($connection['id'] === $id) {
        $this->connections[$index] = array_replace($connection, $data);
        $this->save();
        return $id;
      }
    }
    $id = self::id();
    $this->connections[] = array_replace($data, ['id' => $id, 'column' => $this->columns[0]['id']]);
    $this->save();
    return $id;
  }

  /** Delete one connection and persist the result. */
  public function delete(string $id): void {
    $this->connections = array_values(array_filter($this->connections, fn(array $connection): bool => $connection['id'] !== $id));
    $this->save();
  }

  /** Move a card within its column or to an adjacent column. */
  public function move(string $id, int $horizontal, int $vertical): bool {
    foreach ($this->connections as $index => $connection) {
      if ($connection['id'] !== $id) {
        continue;
      }
      $columnIds = array_column($this->columns, 'id');
      $columnIndex = array_search($connection['column'], $columnIds, true);
      if ($horizontal !== 0) {
        $target = $columnIndex + $horizontal;
        if (!isset($columnIds[$target])) {
          return false;
        }
        $this->connections[$index]['column'] = $columnIds[$target];
        return $this->save();
      }
      $positions = [];
      foreach ($this->connections as $position => $candidate) {
        if ($candidate['column'] === $connection['column']) {
          $positions[] = $position;
        }
      }
      $rank = array_search($index, $positions, true);
      $other = $positions[$rank + $vertical] ?? null;
      if ($other === null) {
        return false;
      }
      [$this->connections[$index], $this->connections[$other]] = [$this->connections[$other], $this->connections[$index]];
      return $this->save();
    }
    return false;
  }

  /** Write all board state as one private JSON document. */
  private function save(): bool {
    if (!AppData::saveJson('board.json', ['columns' => $this->columns, 'connections' => $this->connections])) {
      throw new \RuntimeException('Could not save connections.');
    }
    return true;
  }

  /** Allocate an opaque identifier for a column or connection. */
  private static function id(): string {
    return bin2hex(random_bytes(8));
  }

}
