<?php

namespace MADB\Config;

class Settings {

  private const DEFAULT_SELECT_LIMIT = 1000;
  private const SECRET_ALGORITHM = 'aes-256-gcm';
  private const KEY_ITERATIONS = 100000;
  private static string|false $masterPassword = false;

  public static function load(): array {
    return \SPTK\Config::load(self::file());
  }

  public static function save(array $settings): bool {
    return \SPTK\Config::save(self::file(), $settings);
  }

  public static function file(): string {
    return \SPTK\Config::getFilePath('settings.json');
  }

  public static function defaultExportDirectory(): string {
    $settings = self::load();
    $directory = trim((string)($settings['defaultExportDirectory'] ?? ''));
    if ($directory !== '') {
      return $directory;
    }
    return rtrim(\SPTK\Config::getHome(), '/');
  }

  public static function defaultSelectLimit(): int {
    $settings = self::load();
    $limit = $settings['defaultSelectLimit'] ?? self::DEFAULT_SELECT_LIMIT;
    if (is_int($limit) && $limit > 0) {
      return $limit;
    }
    if (is_string($limit) && ctype_digit($limit) && (int)$limit > 0) {
      return (int)$limit;
    }
    return self::DEFAULT_SELECT_LIMIT;
  }

  public static function masterPasswordConfigured(): bool {
    $settings = self::load();
    return isset($settings['masterPasswordHash'], $settings['masterPasswordSalt']);
  }

  public static function isUnlocked(): bool {
    return !self::masterPasswordConfigured() || self::$masterPassword !== false;
  }

  public static function unlock(string $password): bool {
    $settings = self::load();
    if (!isset($settings['masterPasswordHash']) || !password_verify($password, (string)$settings['masterPasswordHash'])) {
      return false;
    }
    self::$masterPassword = $password;
    return true;
  }

  public static function setMasterPassword(array &$settings, string $password): void {
    if (!function_exists('openssl_encrypt')) {
      throw new \Exception('OpenSSL is required to store encrypted connection passwords.');
    }
    $settings['masterPasswordHash'] = password_hash($password, PASSWORD_DEFAULT);
    $settings['masterPasswordSalt'] = bin2hex(random_bytes(16));
    unset($settings['masterPasswordPending']);
    self::$masterPassword = $password;
  }

  public static function clearMasterPassword(array &$settings): void {
    unset($settings['masterPasswordHash'], $settings['masterPasswordSalt'], $settings['masterPasswordPending']);
    self::$masterPassword = false;
  }

  public static function shouldEncryptSecrets(): bool {
    return self::masterPasswordConfigured() && self::$masterPassword !== false;
  }

  public static function encryptSecret(string $value) {
    if (!self::shouldEncryptSecrets()) {
      return false;
    }
    $iv = random_bytes(12);
    $tag = '';
    $encrypted = openssl_encrypt($value, self::SECRET_ALGORITHM, self::secretKey(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($encrypted === false) {
      return false;
    }
    return [
      'algorithm' => self::SECRET_ALGORITHM,
      'iv' => base64_encode($iv),
      'tag' => base64_encode($tag),
      'value' => base64_encode($encrypted)
    ];
  }

  public static function decryptSecret($encrypted) {
    if (!self::shouldEncryptSecrets() || !is_array($encrypted)) {
      return false;
    }
    if (self::emptyEncryptedSecret($encrypted)) {
      return '';
    }
    if (($encrypted['algorithm'] ?? '') !== self::SECRET_ALGORITHM) {
      return false;
    }
    $iv = base64_decode((string)($encrypted['iv'] ?? ''), true);
    $tag = base64_decode((string)($encrypted['tag'] ?? ''), true);
    $value = base64_decode((string)($encrypted['value'] ?? ''), true);
    if ($iv === false || $tag === false || $value === false) {
      return false;
    }
    $decrypted = openssl_decrypt($value, self::SECRET_ALGORITHM, self::secretKey(), OPENSSL_RAW_DATA, $iv, $tag);
    return $decrypted === false ? false : $decrypted;
  }

  private static function emptyEncryptedSecret(array $encrypted): bool {
    foreach (['algorithm', 'iv', 'tag', 'value'] as $key) {
      if (isset($encrypted[$key]) && (string)$encrypted[$key] !== '') {
        return false;
      }
    }
    return true;
  }

  private static function secretKey(): string {
    $settings = self::load();
    $salt = hex2bin((string)($settings['masterPasswordSalt'] ?? ''));
    if (self::$masterPassword === false || $salt === false) {
      throw new \Exception('Master password is locked.');
    }
    return hash_pbkdf2('sha256', self::$masterPassword, $salt, self::KEY_ITERATIONS, 32, true);
  }

}
