<?php

namespace MADB\Engine;

use SPTK\Element;

class MenuController {

  public static function init(): void {
    self::updateEngineMenu();
    self::applyActiveEngine();
  }

  public static function select($item): void {
    $engine = is_string($item) ? $item : $item->getValue();
    EngineRegistry::setActive((string)$engine);
    \MADB\Connection\MenuController::switchEngine((string)$engine);
    Element::refresh();
  }

  public static function updateEngineMenu(): void {
    $menuBox = Element::byName('submenu-engine');
    if ($menuBox === false) {
      return;
    }
    $menuBox->clear();
    $menuBox->setOnSelect('\MADB\Engine\MenuController::select');
    foreach (EngineRegistry::ids() as $engine) {
      $menuBox->addItem([
        'value' => $engine,
        'text' => EngineRegistry::label($engine),
        'selectable' => 'engines',
        'selected' => $engine === EngineRegistry::active()
      ]);
    }
  }

  public static function applyActiveEngine(): void {
    self::updateEngineMenu();
    \MADB\Connection\MenuController::updateConnectionList();
    \MADB\Connection\MenuController::updateMenuLabels();
  }

}
