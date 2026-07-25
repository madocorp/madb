<?php

namespace MADB\Connection;

use MADB\App\Settings;

/** Persists configured connections and menu separators in the user configuration file. */
class ConnectionList {

  private static $instance;

  private $connectionList = [];
  private array $currentByEngine = [];
  private array $sessionPasswords = [];
  private $fileName = 'connections.json';
  public $current = false;

  /** Initializes connection menu state. */
  public function __construct() {
    self::$instance = $this;
    $this->load();
  }

  /** Returns instance data used by the connection menu. */
  public static function getInstance() {
    return self::$instance;
  }

  /** Loads connection definitions and separators from the user configuration file. */
  public function load() {
    $connectionListFile = \SPTK\Config::getFilePath($this->fileName);
    if (!file_exists($connectionListFile)) {
      return;
    }
    $data = \SPTK\Config::load($connectionListFile);
    $this->connectionList = [];
    foreach (($data['connections'] ?? []) as $connectionData) {
      if (!is_array($connectionData) || empty($connectionData['engine']) || empty($connectionData['name'])) {
        continue;
      }
      if (!\MADB\Engine\EngineRegistry::exists((string)$connectionData['engine'])) {
        continue;
      }
      $this->connectionList[] = $connectionData;
    }
  }

  /** Returns name list data used by the connection menu. */
  public function getNameList($engine = null) {
    $engine = $engine ?? \MADB\Engine\EngineRegistry::active();
    $nameList = [];
    foreach ($this->connectionList as $connectionData) {
      if (($connectionData['engine'] ?? '') !== $engine) {
        continue;
      }
      $nameList[] = $connectionData['name'];
    }
    return $nameList;
  }

  /** Returns saved connection names for one engine without changing current selection. */
  public function getNamesForEngine(string $engine): array {
    return $this->getNameList($engine);
  }

  /** Returns name and type list data used by the connection menu. */
  public function getNameAndTypeList() {
    $nameList = [];
    foreach ($this->connectionList as $connectionData) {
      $nameList[$connectionData['name']] = $connectionData['engine'];
    }
    return $nameList;
  }

  /** Returns a configured connection by name. */
  public function get($name) {
    foreach ($this->connectionList as $connectionData) {
      if ($connectionData['name'] === $name) {
        return $this->withReadableSecrets($connectionData);
      }
    }
    return false;
  }

  /** Returns whether a connection name already exists, optionally ignoring one original name. */
  public function exists($name, $originalName = false): bool {
    foreach ($this->connectionList as $connectionData) {
      if ($connectionData['name'] === $name && $connectionData['name'] !== $originalName) {
        return true;
      }
    }
    return false;
  }

  /** Returns separators data used by the connection menu. */
  public function getSeparators($engine = null) {
    $engine = $engine ?? \MADB\Engine\EngineRegistry::active();
    $separators = [];
    foreach ($this->connectionList as $connectionData) {
      if (($connectionData['engine'] ?? '') !== $engine) {
        continue;
      }
      if (isset($connectionData['separator'])) {
        $separators[] = $connectionData['name'];
      }
    }
    return $separators;
  }

  /** Adds or replaces a connection definition in the saved connection list. */
  public function add($connectionData, $originalName = false) {
    if ($this->exists($connectionData['name'], $originalName)) {
      return false;
    }
    if ($originalName !== false) {
      foreach ($this->connectionList as $i => $item) {
        if ($item['name'] === $originalName) {
          $this->connectionList[$i] = $this->withListMetadata($connectionData, $item);
          return true;
        }
      }
    }
    foreach ($this->connectionList as $i => $item) {
      if ($connectionData['name'] == $item['name']) {
        $this->connectionList[$i] = $this->withListMetadata($connectionData, $item);
        return true;
      }
    }
    $this->connectionList[] = $connectionData;
    return true;
  }

  /** Preserves menu-only metadata when replacing a connection definition. */
  private function withListMetadata($connectionData, $item) {
    if (isset($item['separator']) && !isset($connectionData['separator'])) {
      $connectionData['separator'] = $item['separator'];
    }
    return $connectionData;
  }

  /** Writes connection definitions and separators to the user configuration file. */
  public function save() {
    $connectionListFile = \SPTK\Config::getFilePath($this->fileName);
    $storedConnections = [];
    foreach ($this->connectionList as $connectionData) {
      $storedConnections[] = $this->forStorage($connectionData);
    }
    \SPTK\Config::save($connectionListFile, $storedConnections, 'connections');
    $currentName = false;
    if ($this->current !== false) {
      $currentName = $this->current['name'];
    }
    $this->setCurrent($currentName);
  }

  /** Applies current values to connection menu state or controls. */
  public function setCurrent($name) {
    $this->current = false;
    foreach ($this->connectionList as $connectionData) {
      if ($connectionData['name'] == $name) {
        $this->current = $this->withReadableSecrets($connectionData);
        $this->applySessionPassword();
        $engine = $this->current['engine'] ?? false;
        if (is_string($engine) && $engine !== '') {
          $this->currentByEngine[$engine] = $this->current['name'];
        }
        return;
      }
    }
  }

  /** Restores the last selected connection for an engine, or clears current when none exists. */
  public function setCurrentForEngine(string $engine): void {
    $name = $this->currentByEngine[$engine] ?? false;
    if ($name !== false) {
      $this->setCurrent($name);
      if ($this->current !== false && ($this->current['engine'] ?? '') === $engine) {
        return;
      }
    }
    $this->current = false;
  }

  public function reload(): void {
    $currentName = $this->current === false ? false : $this->current['name'];
    $this->load();
    $this->setCurrent($currentName);
  }

  public function secretsLocked($connectionData): bool {
    return isset($connectionData['passwordEncrypted']) && Settings::masterPasswordConfigured() && !Settings::isUnlocked();
  }

  public function setSessionPassword(string $name, string $password): bool {
    foreach ($this->connectionList as $connectionData) {
      if (($connectionData['name'] ?? false) === $name) {
        $this->sessionPasswords[$name] = $password;
        $this->current = $connectionData;
        $this->applySessionPassword();
        return true;
      }
    }
    return false;
  }

  public function clearSessionPassword(string $name): void {
    unset($this->sessionPasswords[$name]);
    if ($this->current !== false && ($this->current['name'] ?? false) === $name) {
      $this->setCurrent($name);
    }
  }

  private function applySessionPassword(): void {
    if ($this->current === false) {
      return;
    }
    $name = $this->current['name'] ?? false;
    if (is_string($name) && array_key_exists($name, $this->sessionPasswords)) {
      $this->current['password'] = $this->sessionPasswords[$name];
      unset($this->current['passwordEncrypted']);
    }
  }

  public function decryptSecretsForPlainStorage(): bool {
    foreach ($this->connectionList as $i => $connectionData) {
      if (!is_array($connectionData) || !isset($connectionData['passwordEncrypted'])) {
        continue;
      }
      $password = Settings::decryptSecret($connectionData['passwordEncrypted']);
      if ($password === false) {
        return false;
      }
      $this->connectionList[$i]['password'] = $password;
      unset($this->connectionList[$i]['passwordEncrypted']);
    }
    if ($this->current !== false && isset($this->current['passwordEncrypted'])) {
      $password = Settings::decryptSecret($this->current['passwordEncrypted']);
      if ($password === false) {
        return false;
      }
      $this->current['password'] = $password;
      unset($this->current['passwordEncrypted']);
    }
    return true;
  }

  private function withReadableSecrets($connectionData) {
    if (!is_array($connectionData)) {
      return $connectionData;
    }
    if (isset($connectionData['passwordEncrypted'])) {
      $password = Settings::decryptSecret($connectionData['passwordEncrypted']);
      $connectionData['password'] = $password === false ? '' : $password;
    }
    return $connectionData;
  }

  private function forStorage($connectionData) {
    if (!is_array($connectionData)) {
      return $connectionData;
    }
    if (isset($connectionData['password'])) {
      if ((string)$connectionData['password'] === '') {
        unset($connectionData['passwordEncrypted']);
      } else if (Settings::shouldEncryptSecrets()) {
        $encrypted = Settings::encryptSecret((string)$connectionData['password']);
        if ($encrypted !== false) {
          $connectionData['passwordEncrypted'] = $encrypted;
          unset($connectionData['password']);
        }
      } else if (Settings::masterPasswordConfigured() && isset($connectionData['passwordEncrypted'])) {
        unset($connectionData['password']);
      }
    }
    return $connectionData;
  }

  /** Deletes the current connection from the saved connection list. */
  public function delete() {
    $engine = $this->current['engine'] ?? false;
    $name = $this->current['name'] ?? false;
    foreach ($this->connectionList as $i => $connectionData) {
      if ($connectionData['name'] === $name) {
        unset($this->connectionList[$i]);
        break;
      }
    }
    if (is_string($engine) && ($this->currentByEngine[$engine] ?? false) === $name) {
      unset($this->currentByEngine[$engine]);
    }
  }

  /** Returns count data used by the connection menu. */
  public function getCount() {
    return count($this->connectionList);
  }

  /** Applies the connection-menu order saved by the sort panel. */
  public function sort($order) {
    $sortedList = [];
    $j = 0;
    $remaining = $this->connectionList;
    foreach ($order as $name) {
      if (strpos($name, SortController::SEPARATOR_STRING) === 0) {
        if ($j > 0) {
          $sortedList[$j - 1]['separator'] = true;
        }
      } else {
        foreach ($remaining as $i => $connectionData) {
          if ($connectionData['name'] == $name) {
            unset($connectionData['separator']);
            $sortedList[$j] = $connectionData;
            $j++;
            unset($remaining[$i]);
            break;
          }
        }
      }
    }
    foreach ($remaining as $connectionData) {
      unset($connectionData['separator']);
      $sortedList[$j] = $connectionData;
      $j++;
    }
    if (!empty($sortedList)) {
      $this->connectionList = $sortedList;
    }
  }

}
