# Connections and controls

This is the new SPTK implementation of madb. The first screen is a connection board. It stores its columns and connections in `~/.madb/board.json` with private file permissions. The old madb configuration is not imported automatically or changed.

Run `php madb.php` from this directory. PHP and the shared `../SPTK` checkout are required. The MySQL, SQLite, and MongoDB tests require their corresponding PHP extensions.

On the board, use the arrow keys to select a card or column title. `Insert` adds a connection, `Delete` asks before removing one, `Space` edits its engine settings, and `Return` opens its engine main screen. `F1` or `Escape` returns to the previous connection. `Shift` plus an arrow moves a card within or between columns. Press `Return` on a column title to edit the comma-separated column list above the titles; press `Return` to save or `Escape` to cancel.

Each connection may have a unique one-letter or one-digit hotkey. The board shows it as `[k] name`; type the key on the board to open that connection.

Connection editors select the Name field when opened. `Return` starts editing an input; `Escape` leaves the input, and another `Escape` closes the editor. `Delete` clears a selected input. Save, Test, and Cancel are beside the editor title, separated from each other. `F2` saves from the form, including while a text input is active. `F3` tests the current, unsaved settings: quick success is silent, progress appears after 750 ms, and failures show an error.

On a file path field, `Return` opens a file selector in place. `Escape` restores the original path; `Return` on a file fills the field with that file's path.

The engine picker lists MySQL, PostgreSQL, SQLite, MongoDB, Redis, Memcache, and Solr. MySQL, SQLite, and MongoDB currently have editors and connectivity tests; the other engines show a notice until their separate implementations are added.

Opening a connection starts a background worker reserved for app work. User queries use an idle query worker or start a new one; a user can also reserve a dedicated worker for a session. Idle query workers close after ten minutes. Saved SQL init commands run when each worker connects. The worker API is described in [Workers.md](Workers.md), and main-screen controls in [MainScreens.md](MainScreens.md). Passwords are stored in the private JSON file; encryption from the original madb has not yet been ported.
