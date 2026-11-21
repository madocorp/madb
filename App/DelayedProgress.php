<?php

namespace MADB\App;

use SPTK\App;
use SPTK\Events\EventLoop;
use SPTK\Widgets\StatusBar\StatusBar;

/** Show operation progress only when an asynchronous job lasts long enough. */
final class DelayedProgress {

  private ?int $timer = null;
  private bool $shown = false;
  private bool $finished = false;
  private EventLoop $loop;

  /** Schedule a quiet progress message after the normal quick-operation window. */
  public function __construct(private StatusBar $status, private string $message, int $delayMs = 750, ?EventLoop $loop = null) {
    $this->loop = $loop ?? App::eventLoop();
    if ($status->text() !== '' && !$status->confirming()) {
      $status->clear();
    }
    $this->timer = $this->loop->after($delayMs, function(): void {
      $this->timer = null;
      if ($this->finished || $this->status->text() !== '') {
        return;
      }
      $this->shown = true;
      $this->status->info($this->message, 'continuous');
    });
  }

  /** Cancel pending progress and remove this operation's message if it appeared. */
  public function finish(): void {
    if ($this->finished) {
      return;
    }
    $this->finished = true;
    if ($this->timer !== null) {
      $this->loop->removeTimer($this->timer);
      $this->timer = null;
    }
    if ($this->shown && $this->status->behavior() === 'continuous' && $this->status->text() === $this->message) {
      $this->status->clear();
    }
  }

}
