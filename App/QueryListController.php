<?php

namespace MADB\App;

use SPTK\App;
use SPTK\Events\{EventContext, KeyNormalizer};
use SPTK\SDLWrapper\SDL;
use SPTK\Widgets\Input\Input;
use SPTK\Widgets\List\ListView;

/** Handle the shared List menu for persistent query tabs. */
final class QueryListController {

  /** Apply one List menu command. */
  public static function choose(string $command): bool {
    $id = MainController::activeConnectionId();
    if ($id === null) {
      return false;
    }
    $list = MainController::queryList();
    MainController::saveActiveQuery();
    switch ($command) {
      case 'new':
        MainController::openQuery($list->create($id)['id']);
        break;
      case 'clone':
        $clone = $list->cloneActive($id);
        if ($clone !== null) {
          MainController::openQuery($clone['id']);
        }
        break;
      case 'rename':
        self::rename();
        break;
      case 'pin':
        $list->togglePin($id);
        MainController::refreshQueries();
        break;
      case 'search':
        MainController::focusQueryList();
        break;
      case 'delete':
        self::delete();
        break;
      default:
        return false;
    }
    return true;
  }

  /** Persist a keyboard row move and adjust only the moved row's pin. */
  public static function reorder(EventContext $event): bool {
    $id = MainController::activeConnectionId();
    if ($id === null || !$event->widget instanceof ListView || $event->widget->activeValue() === null) {
      return false;
    }
    MainController::queryList()->reorder($id, $event->widget->values(), $event->widget->activeValue());
    MainController::refreshQueries();
    return true;
  }

  /** Save pin marks toggled with Space in the query list. */
  public static function markPins(EventContext $event): bool {
    $id = MainController::activeConnectionId();
    if ($id === null || !$event->widget instanceof ListView) {
      return false;
    }
    if (MainController::queryList()->setPinned($id, $event->widget->getValue())) {
      MainController::refreshQueries();
    }
    return true;
  }

  /** Confirm deletion when Delete is pressed on an unfiltered query list. */
  public static function deleteFromList(EventContext $event): bool {
    if (MainController::activeConnectionId() === null || !$event->widget instanceof ListView
      || $event->widget->id() !== 'query-list' || $event->widget->activeValue() === null) {
      return false;
    }
    MainController::saveActiveQuery();
    self::delete();
    return true;
  }

  /** Show an inline name editor above the query list. */
  private static function rename(): void {
    $screen = self::screen();
    $id = MainController::activeConnectionId();
    $query = MainController::queryList()->active($id);
    $input = $screen->widget('query-name-input');
    $input->setLabel('Name: ');
    $input->setValue($query['name'], true);
    $input->setInputInterceptor(static function(mixed $event): bool {
      if ($event->type !== SDL::SDL_EVENT_KEY_DOWN) {
        return false;
      }
      $key = KeyNormalizer::normalize((int)$event->key->key, (int)$event->key->mod);
      if ($key === SDL::KEY_ESCAPE) {
        self::closeRename();
        return true;
      }
      if ($key === SDL::KEY_RETURN) {
        $screen = self::screen();
        $id = MainController::activeConnectionId();
        if (!MainController::queryList()->rename($id, MainController::queryList()->activeId($id),
          $screen->widget('query-name-input')->getValue())) {
          $screen->statusBar->error('Enter a nonempty single-line name.');
          return true;
        }
        self::closeRename();
        MainController::refreshQueries();
        return true;
      }
      return false;
    });
    $screen->layout->findTile('query-name-input')->setHeight('1');
    MainController::focusQueryName();
  }

  /** Hide the inline name editor and focus the list. */
  private static function closeRename(): void {
    $screen = self::screen();
    $screen->release('cancel');
    $screen->widget('query-name-input')->setInputInterceptor(null);
    $screen->layout->findTile('query-name-input')->setHeight('0');
    MainController::focusQueryList();
  }

  /** Confirm removal of the current saved query. */
  private static function delete(): void {
    $id = MainController::activeConnectionId();
    $query = MainController::queryList()->active($id);
    self::screen()->statusBar->confirm('Delete query ' . $query['name'] . '?', static function() use ($id): void {
      $next = MainController::queryList()->deleteActive($id);
      MainController::openQuery($next['id']);
    }, static function(): void {
      MainController::refreshQueries();
    });
  }

  /** Return the current engine screen. */
  private static function screen(): \SPTK\Core\Screen {
    $window = array_values(App::eventLoop()->windows())[0];
    return $window->screen($window->currentScreenId());
  }

}
