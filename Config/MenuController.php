<?php

namespace MADB\Config;

use \SPTK\Element;

/** Routes application-level menu callbacks such as About and Quit. */
class MenuController {

  private static array $helpTextCache = [];

  /** Coordinates about work in the application. */
  public static function about() {
    $panel = Element::byName('about');
    $panel->show();
    Element::refresh();
  }

  /** Opens the application settings panel. */
  public static function settings() {
    $panel = Element::byName('settings');
    if ($panel === false) {
      return;
    }
    $settings = Settings::load();
    $panel->setValue([
      'settings-export-directory' => (string)($settings['defaultExportDirectory'] ?? ''),
      'settings-select-limit' => (string)($settings['defaultSelectLimit'] ?? Settings::defaultSelectLimit()),
      'settings-clear-master-password' => false,
      'settings-master-password' => '',
      'settings-master-password-confirm' => ''
    ]);
    $panel->show();
    if (method_exists($panel, 'activateInput')) {
      $panel->activateInput('settings-export-directory');
    }
    Element::refresh();
  }

  /** Asks for the master password after startup when encrypted secrets are configured. */
  public static function askMasterPassword(): void {
    if (!Settings::masterPasswordConfigured() || Settings::isUnlocked()) {
      return;
    }
    $panel = Element::byName('master-password-unlock');
    if ($panel === false) {
      return;
    }
    $panel->setValue(['settings-unlock-master-password' => '']);
    $panel->show();
    if (method_exists($panel, 'activateInput')) {
      $panel->activateInput('settings-unlock-master-password');
    }
    Element::refresh();
  }

  /** Starts manual master-password unlock from the MADB menu. */
  public static function unlock(): void {
    if (!Settings::masterPasswordConfigured()) {
      \SPTK\Elements\WarningPanel::forge('No master password', 'No master password has been configured.');
      Element::refresh();
      return;
    }
    if (Settings::isUnlocked()) {
      \SPTK\Elements\WarningPanel::forge('Already unlocked', 'The master password is already unlocked for this session.');
      Element::refresh();
      return;
    }
    self::askMasterPassword();
  }

  /** Unlocks encrypted connection passwords for the current session. */
  public static function unlockMasterPassword($panel): void {
    $values = $panel->getValue();
    $password = (string)($values['settings-unlock-master-password'] ?? '');
    if (!Settings::unlock($password)) {
      \SPTK\Elements\WarningPanel::forge('Invalid master password', 'The master password is not correct.');
      Element::refresh();
      return;
    }
    $connectionList = \MADB\Connection\ConnectionList::getInstance();
    if ($connectionList !== false) {
      $connectionList->reload();
      \MADB\Connection\MenuController::updateConnectionList();
    }
    $panel->hide();
    Element::refresh();
  }

  /** Saves application settings. */
  public static function saveSettings($panel) {
    $values = $panel->getValue();
    $settings = Settings::load();
    $directory = trim((string)($values['settings-export-directory'] ?? ''));
    if ($directory !== '' && (!is_dir($directory) || !is_writable($directory))) {
      \SPTK\Elements\WarningPanel::forge('Invalid export directory', "The directory does not exist or is not writable:\n{$directory}");
      Element::refresh();
      return;
    }
    $limit = trim((string)($values['settings-select-limit'] ?? ''));
    if ($limit === '' || !ctype_digit($limit) || (int)$limit <= 0) {
      \SPTK\Elements\WarningPanel::forge('Invalid SELECT limit', 'Default SELECT limit must be a positive integer.');
      Element::refresh();
      return;
    }
    $masterPassword = (string)($values['settings-master-password'] ?? '');
    $masterPasswordConfirm = (string)($values['settings-master-password-confirm'] ?? '');
    $clearMasterPassword = $values['settings-clear-master-password'] === true;
    if ($clearMasterPassword && ($masterPassword !== '' || $masterPasswordConfirm !== '')) {
      \SPTK\Elements\WarningPanel::forge('Conflicting master password settings', 'Clear master password cannot be combined with a new master password.');
      Element::refresh();
      return;
    }
    if ($clearMasterPassword) {
      if (!Settings::masterPasswordConfigured()) {
        \SPTK\Elements\WarningPanel::forge('No master password', 'No master password has been configured.');
        Element::refresh();
        return;
      }
      if (!Settings::isUnlocked()) {
        \SPTK\Elements\WarningPanel::forge('Master password required', 'Unlock the current master password before clearing it.');
        Element::refresh();
        return;
      }
      $connectionList = \MADB\Connection\ConnectionList::getInstance();
      if ($connectionList !== false && !$connectionList->decryptSecretsForPlainStorage()) {
        \SPTK\Elements\ErrorPanel::forge('Could not clear master password', 'Stored connection passwords could not be decrypted.');
        Element::refresh();
        return;
      }
      Settings::clearMasterPassword($settings);
    }
    if ($masterPassword !== '' || $masterPasswordConfirm !== '') {
      if (Settings::masterPasswordConfigured() && !Settings::isUnlocked()) {
        \SPTK\Elements\WarningPanel::forge('Master password required', 'Unlock the current master password before changing it.');
        Element::refresh();
        return;
      }
      if ($masterPassword !== $masterPasswordConfirm) {
        \SPTK\Elements\WarningPanel::forge('Master password mismatch', 'Please enter the same master password twice.');
        Element::refresh();
        return;
      }
      $connectionList = \MADB\Connection\ConnectionList::getInstance();
      if ($connectionList !== false && !$connectionList->decryptSecretsForPlainStorage()) {
        \SPTK\Elements\ErrorPanel::forge('Could not change master password', 'Stored connection passwords could not be decrypted.');
        Element::refresh();
        return;
      }
      try {
        Settings::setMasterPassword($settings, $masterPassword);
      } catch (\Exception $e) {
        \SPTK\Elements\ErrorPanel::forge('Could not save master password', $e->getMessage());
        Element::refresh();
        return;
      }
    }
    if ($directory === '') {
      unset($settings['defaultExportDirectory']);
    } else {
      $settings['defaultExportDirectory'] = $directory;
    }
    $settings['defaultSelectLimit'] = (int)$limit;
    Settings::save($settings);
    if ($masterPassword !== '' || $clearMasterPassword) {
      $connectionList = \MADB\Connection\ConnectionList::getInstance();
      if ($connectionList !== false) {
        $connectionList->save();
      }
    }
    $panel->hide();
    Element::refresh();
  }

  /** Coordinates help work in the application. */
  public static function help() {
    $panel = Element::byName('help');
    if ($panel !== false) {
      self::loadHelpText($panel);
      $panel->show();
      if (method_exists($panel, 'refreshInputList')) {
        $panel->refreshInputList('help-overview-text');
      }
      Element::refresh();
    }
  }

  /** Loads static help files into wrapping help boxes. */
  private static function loadHelpText($panel): void {
    $topics = [
      'help-overview-text' => 'Help/Overview.txt',
      'help-connections-text' => 'Help/Connections.txt',
      'help-schemas-tables-text' => 'Help/SchemasTables.txt',
      'help-queries-text' => 'Help/Queries.txt',
      'help-results-text' => 'Help/Results.txt',
      'help-keys-text' => 'Help/Keys.txt'
    ];
    foreach ($topics as $name => $file) {
      $element = Element::byName($name, $panel);
      if ($element === false) {
        continue;
      }
      $path = defined('APP_PATH') ? dirname(APP_PATH) . "/{$file}" : getcwd() . "/{$file}";
      $text = is_readable($path) ? (string)file_get_contents($path) : "Missing help file:\n{$file}";
      if (isset(self::$helpTextCache[$name]) && self::$helpTextCache[$name] === $text) {
        continue;
      }
      self::$helpTextCache[$name] = $text;
      $element->setText($text);
    }
  }

  /** Coordinates quit work in the application. */
  public static function quit() {
    \SPTK\App::$instance->quit();
  }

}
