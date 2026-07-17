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
