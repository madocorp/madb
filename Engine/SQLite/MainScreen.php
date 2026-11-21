<?php

namespace MADB\Engine\SQLite;

use MADB\App\MainController;
use SPTK\Events\EventContext;
use SPTK\Widgets\List\ListView;

/** Provide SQLite schema requests for the common main layout. */
final class MainScreen {

  /** Run metadata requests on the app's reserved worker. */
  public static function menu(EventContext $event): bool {
    if (!$event->widget instanceof ListView || $event->widget->id() !== 'sqlite-object-ops') {
      return false;
    }
    $query = match ($event->widget->activeValue()) {
      'tables' => "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name",
      'views' => "SELECT name FROM sqlite_master WHERE type = 'view' ORDER BY name",
      default => null,
    };
    return $query === null ? false : MainController::runAppQuery($query);
  }

}
