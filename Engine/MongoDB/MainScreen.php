<?php

namespace MADB\Engine\MongoDB;

use MADB\App\MainController;
use SPTK\Events\EventContext;
use SPTK\Widgets\List\ListView;

/** Provide MongoDB database and collection commands for the common main layout. */
final class MainScreen {

  /** Run metadata requests on the app's reserved worker. */
  public static function menu(EventContext $event): bool {
    if (!$event->widget instanceof ListView) {
      return false;
    }
    $query = match ($event->widget->id() . ':' . $event->widget->activeValue()) {
      'mongodb-database-ops:databases' => '{"listDatabases": 1}',
      'mongodb-collection-ops:collections' => '{"listCollections": 1}',
      default => null,
    };
    return $query === null ? false : MainController::runAppQuery($query);
  }

  /** Render MongoDB command documents as readable JSON text. */
  public static function render(\SPTK\Core\Screen $screen, array $result): void {
    $documents = [];
    foreach ($result['rows'] ?? [] as $row) {
      $document = json_decode((string)($row[0] ?? ''), true);
      $documents[] = $document === null ? (string)($row[0] ?? '') : json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $screen->widget('result-text')->setText(implode("\n\n", $documents));
  }

}
