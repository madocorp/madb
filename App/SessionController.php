<?php

namespace MADB\App;

use MADB\Job\Manager;
use SPTK\App;
use SPTK\Events\EventContext;

/** Connect asynchronous worker sessions to engine main screens and editors. */
final class SessionController {

  private static ?Manager $manager = null;
  private static ?string $visibleId = null;
  private static ?string $visibleScreen = null;
  private static int $openGeneration = 0;
  private static int $editorGeneration = 0;
  private static ?DelayedProgress $openProgress = null;
  private static ?DelayedProgress $draftProgress = null;

  /** Start or reuse the app's reserved worker when a saved connection is opened. */
  public static function open(array $connection, string $screen): void {
    self::$manager ??= new Manager();
    $id = $connection['id'];
    $generation = ++self::$openGeneration;
    self::$visibleId = $id;
    self::$visibleScreen = $screen;
    self::$openProgress?->finish();
    self::$openProgress = new DelayedProgress(self::status(), 'Connecting…');
    try {
      $reused = self::$manager->open($connection, static function(array $response) use ($id, $generation): void {
        self::finishOpen($id, $generation, $response);
      });
      if ($reused && $connection['engine'] === 'MySQL' && ($connection['database'] ?? '') !== '') {
        self::$manager->selectDatabase($id, $connection['database'], static function(array $response) use ($id, $generation): void {
          self::finishOpen($id, $generation, $response);
        });
      } else if ($reused) {
        self::finishOpen($id, $generation, ['ok' => true]);
      }
    } catch (\Throwable $error) {
      self::finishOpen($id, $generation, ['ok' => false, 'error' => $error->getMessage()]);
    }
  }

  /** Finish connection setup after any saved query database is selected. */
  private static function finishOpen(string $id, int $generation, array $response): void {
    if (self::$openGeneration !== $generation) {
      return;
    }
    self::$openProgress?->finish();
    self::$openProgress = null;
    self::report($id, $response);
  }

  /** Test the editor's current, possibly unsaved, connection settings. */
  public static function testDraft(array $connection, string $screen): void {
    self::$manager ??= new Manager();
    $generation = ++self::$editorGeneration;
    self::$draftProgress?->finish();
    $window = array_values(App::eventLoop()->windows())[0];
    self::$draftProgress = new DelayedProgress($window->screen($screen)->statusBar, 'Testing connection…');
    try {
      self::$manager->testDraft($connection, static function(array $response) use ($screen, $generation): void {
        if (self::$editorGeneration === $generation) {
          self::$draftProgress?->finish();
          self::$draftProgress = null;
        }
        $window = array_values(App::eventLoop()->windows())[0];
        if (self::$editorGeneration !== $generation || $window->currentScreenId() !== $screen) {
          return;
        }
        $status = $window->screen($screen)->statusBar;
        if (!($response['ok'] ?? false)) {
          $status->error((string)($response['error'] ?? 'Connection failed.'));
        }
      });
    } catch (\Throwable $error) {
      self::$draftProgress?->finish();
      self::$draftProgress = null;
      $window = array_values(App::eventLoop()->windows())[0];
      $window->screen($screen)->statusBar->error($error->getMessage());
    }
  }

  /** Ignore draft test results after leaving or replacing an editor. */
  public static function leaveEditor(): void {
    self::$editorGeneration++;
    self::$draftProgress?->finish();
    self::$draftProgress = null;
  }

  /** Run an ordinary user query on an idle pooled worker or a new one. */
  public static function query(string $id, string $text, callable $callback, ?string $database = null): void {
    if (self::$manager === null) {
      throw new \RuntimeException('No database session is open.');
    }
    self::$manager->query($id, $text, $callback, $database);
  }

  /** Run metadata work without borrowing a user query worker. */
  public static function appQuery(string $id, string $text, callable $callback): void {
    if (self::$manager === null) {
      throw new \RuntimeException('No database session is open.');
    }
    self::$manager->submitApp($id, 'query', ['query' => $text], $callback);
  }

  /** Change MySQL's current schema without reconnecting its app worker. */
  public static function selectDatabase(string $id, string $name, callable $callback): void {
    if (self::$manager === null) {
      throw new \RuntimeException('No database session is open.');
    }
    self::$manager->selectDatabase($id, $name, $callback);
  }

  /** Stop reporting connection messages after leaving its main screen. */
  public static function leaveMain(): void {
    self::$openGeneration++;
    self::$openProgress?->finish();
    self::$openProgress = null;
    self::$visibleId = null;
    self::$visibleScreen = null;
  }

  /** Create a dedicated user session and return its opaque handle. */
  public static function pin(string $id, callable $callback): string {
    if (self::$manager === null) {
      throw new \RuntimeException('No database session is open.');
    }
    return self::$manager->pin($id, $callback);
  }

  /** Run one user query on a dedicated session. */
  public static function queryPinned(string $handle, string $text, callable $callback): void {
    if (self::$manager === null) {
      throw new \RuntimeException('No database session is open.');
    }
    self::$manager->queryPinned($handle, $text, $callback);
  }

  /** End a dedicated user session. */
  public static function release(string $handle): void {
    self::$manager?->release($handle);
  }

  /** Pump nonblocking worker streams from the SPTK timer. */
  public static function poll(EventContext $event): void {
    self::$manager?->poll();
  }

  /** Close a stale session after its saved definition changes or is removed. */
  public static function close(string $id): void {
    if (self::$visibleId === $id) {
      self::leaveMain();
    }
    self::$manager?->close($id);
  }

  /** Stop child processes before SPTK shuts down. */
  public static function shutdown(EventContext $event): void {
    self::$openProgress?->finish();
    self::$draftProgress?->finish();
    self::$openProgress = null;
    self::$draftProgress = null;
    self::$manager?->closeAll();
    self::$manager = null;
    self::$visibleId = null;
    self::$visibleScreen = null;
  }

  /** Show the worker result on the matching visible connection. */
  private static function report(string $id, array $response): void {
    if (!($response['ok'] ?? false)) {
      self::error($id, (string)($response['error'] ?? 'Worker failed.'));
    }
  }

  /** Display a worker error only on its main screen. */
  private static function error(string $id, string $message): void {
    if (self::$visibleId === $id) {
      self::status()->error($message, 'background', 5000);
    }
  }

  /** Return the active engine screen's status widget. */
  private static function status(): \SPTK\Widgets\StatusBar\StatusBar {
    $window = array_values(App::eventLoop()->windows())[0];
    return $window->screen(self::$visibleScreen)->statusBar;
  }

}
