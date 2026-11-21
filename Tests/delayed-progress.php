<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADB');
require APP_DIR . '/SPTK/App.php';

use MADB\App\DelayedProgress;
use SPTK\Events\EventLoop;
use SPTK\Widgets\StatusBar\StatusBar;

/** Run expired timer callbacks without starting SDL's event loop. */
function fireProgressTimers(EventLoop $loop): void {
  (new ReflectionMethod($loop, 'dispatchDueTimers'))->invoke($loop);
}

$loop = new EventLoop();
$status = new StatusBar();
$quick = new DelayedProgress($status, 'Connecting…', 10, $loop);
$quick->finish();
usleep(15000);
fireProgressTimers($loop);
if ($status->text() !== '') {
  throw new RuntimeException('A quick operation showed progress.');
}
$slow = new DelayedProgress($status, 'Connecting…', 10, $loop);
usleep(15000);
fireProgressTimers($loop);
if ($status->text() !== 'Connecting…' || $status->behavior() !== 'continuous') {
  throw new RuntimeException('A slow operation did not show nonmodal progress.');
}
$slow->finish();
if ($status->text() !== '') {
  throw new RuntimeException('Completed progress remained visible.');
}
$error = new DelayedProgress($status, 'Loading…', 10, $loop);
usleep(15000);
fireProgressTimers($loop);
$status->error('Operation failed.');
$error->finish();
if ($status->text() !== 'Operation failed.') {
  throw new RuntimeException('Finishing progress cleared an error.');
}
$retry = new DelayedProgress($status, 'Retrying…', 10, $loop);
if ($status->text() !== '') {
  throw new RuntimeException('A new operation retained an old error.');
}
$retry->finish();
echo "Delayed progress checks passed.\n";
