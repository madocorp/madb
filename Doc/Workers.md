# Database workers and sessions

The old madb director selected any idle worker with the same connection **name** and created another when all matching workers were busy. A worker retained its database connection until reassigned or idle for ten minutes. That allowed parallel work, but did not provide a stable session for a sequence of user queries.

The new manager identifies connections by saved **ID** and gives workers three roles:

| Role | Used for | Lifetime |
| --- | --- | --- |
| App | Browsing and metadata | Reserved until the connection or app closes |
| Pool | Ordinary user queries | Reused when idle; closed after 10 idle minutes |
| Pinned | User queries requiring session state | Reserved until explicitly released or the connection/app closes |

An ordinary query finds an idle pool worker for its connection. If every pool worker is busy, it starts another, so queries can run in parallel. It never uses the app or a pinned worker. A pinned session starts a **new** worker and database connection, then runs its queries in order on that worker. This preserves temporary tables, session settings, and transaction state. Other workers cannot see that session's uncommitted state. Saved SQL init commands run once when **each** worker connects.

The manager API is `open($connection, $callback): bool`, `submitApp($id, $operation, $payload, $callback)`, `query($id, $sql, $callback, $database = null)`, `pin($id, $callback): string`, `queryPinned($handle, $sql, $callback)`, and `release($handle)`. `open` returns true when the app worker is already connected and does not call back. While a connection is still opening, further selections share that pending result. Otherwise, `open` starts a worker and returns false. Connection startup and draft tests show progress only after 750 ms and do not report routine success in the status bar; errors appear immediately. `testDraft($connection, $callback)` checks unsaved connection editor values in a disposable worker. `App\SessionController` exposes the query and pinned-session calls to future screens. A callback receives `['ok' => true, 'result' => ...]` or `['ok' => false, 'error' => ...]`, with the worker PID when available. A failed worker reports pending work; jobs are not silently retried, because a retry could duplicate a write. Editing or deleting a saved connection closes all its workers. Closing the app closes every worker.

Worker processes are fresh PHP subprocesses, not forks of the SDL process. The event loop pumps their pipes every 30 ms. Secrets pass over stdin rather than command arguments. The worker protocol accepts `connect`, `test`, `query`, and MySQL `database` operations. Selecting a MySQL schema executes `USE` on the existing app worker. Each ordinary query request carries its saved database: pooled workers switch schemas before that request, and new workers connect to the requested database. A request with no database uses a worker without one. Pinned workers keep their independent session state. MongoDB currently keeps one driver manager per worker; explicit MongoDB driver sessions and transactions have not been added.

Run `php Tests/worker.php` for SQLite checks covering app-worker isolation, pool reuse and expiry, concurrent queries, pinned state, and connection failures. The test uses a short timeout; the application default is 600 seconds. `php Tests/mysql-schema.php` verifies that schema selection uses the existing PDO session and quotes schema names safely.
