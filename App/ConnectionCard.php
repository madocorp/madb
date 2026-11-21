<?php

namespace MADB\App;

use SPTK\Core\{Color, Style, Widget};
use SPTK\Events\WidgetEventEmitter;
use SPTK\Rendering\GridWriter;

/** Paint a selectable connection summary on the board. */
final class ConnectionCard extends Widget {

  use WidgetEventEmitter;

  public function __construct(private readonly array $connection, private readonly Style $style = new Style()) {
  }

  /** Return the card's persistent connection identifier. */
  public function connectionId(): string {
    return $this->connection['id'];
  }

  /** Return the inherited tile background. */
  public function background(): Color {
    return $this->style->background;
  }

  /** Paint the name, engine, and an engine-specific location hint. */
  public function paint(GridWriter $writer): void {
    $writer->fill($this->style->foreground, $this->style->background);
    $hotkey = $this->connection['hotkey'] ?? '';
    $name = $hotkey === '' ? $this->connection['name'] : '[' . $hotkey . '] ' . $this->connection['name'];
    $writer->write(1, 0, $name, $this->style->highlight, $this->style->background);
    $writer->write(1, 1, $this->connection['engine'], $this->style->selected, $this->style->background);
    $location = $this->connection['engine'] === 'SQLite'
      ? ($this->connection['path'] ?? '') : ($this->connection['host'] ?? '');
    if ($writer->height() > 2) {
      $writer->write(1, 2, $location, $this->style->foreground, $this->style->background);
    }
  }

  /** Activate this card with Return. */
  public function canActivate(): bool {
    return true;
  }

  /** Let the screen handle keyboard shortcuts. */
  public function handleInput(mixed $event): bool {
    return false;
  }

  /** Keep each card three text rows tall. */
  public function preferredHeight(): ?int {
    return 3;
  }

  /** Explain the board actions available for this connection. */
  protected function defaultTip(bool $active): string {
    return 'Return opens; Space edits; Delete removes; Shift+arrows moves this connection.';
  }

}
