# Connection main screens

MySQL, SQLite, and MongoDB have separate main screens. Each uses the same panel arrangement: an engine menu on the left, a one-row header and query/result area in the middle, and saved queries on the right. The menu and query list start visible. The header shows the connection, selected database when known, and current query title.

| Key | Action |
| --- | --- |
| F1 | Go to the connection board with the current connection selected. On the board, F1 or Escape returns to the previous connection. |
| F2 | Show or hide the engine menu. |
| F3 | Open or collapse the query editor. Its collapsed form shows the query and one status line. |
| F4 | Open or collapse results. Before a query has run, the result area stays hidden. Its collapsed form uses two status rows. |
| F5 | Show or hide the current connection's query list. |
| F9 | Run the editor query on a pooled user worker. |

With results open, the editor uses one share of the middle height and results use two. Without results, an open editor fills the available height. The menu and query list widths, and the editor and result heights, are changed by the shared controller without rebuilding the engine widgets.

Queries are saved per connection in the private `~/.madb/query-list.json` file. Each connection starts with one blank query. Each saved query has its own MySQL database; a new or cloned query inherits the active query's database, and the connection setting supplies the first query's default. Switching queries restores their database in the header and for execution. Existing saved queries without a database inherit the connection default once. The current query and its draft text are restored when the connection reopens. Running a query updates its current tab and result; it does not add a list row. The List menu supports New, Clone, Rename, Pin, Search, and Delete. In the query list, highlighted names mark pinned queries; Space toggles a pin without changing the current query. New queries start just after the pinned section. Pin moves a query to the top; unpin moves it just after the pinned section. Shift+Up/Down reorders rows. Crossing the section boundary changes only the moved query's pin; the row it passes keeps its pin state. Names have no prefix, so filtering begins at their first letter. Existing `query-history.json` entries are imported once as saved queries when the new file is first created. SQL results use a navigable table; MongoDB command output uses formatted JSON text. The MySQL and SQLite editors highlight SQL, while the MongoDB editor highlights JSON. Engine menus run metadata requests on the connection's reserved app worker.

## MySQL menu

F2 hides or shows the menu. It has Database `[D]` and Table `[T]` operation lists. A block's bracketed letter activates it from anywhere on the main screen when no widget is active; if the menu is hidden, the shortcut shows it. MySQL uses D/T, SQLite uses D/O, MongoDB uses D/C, and all three use E/G/L/M for Editor, Grid, List, and MaDB. Return on an inactive list activates it; the next Return runs its selected operation. The first Select item is not highlighted before activation. Each MySQL list currently implements Select. Database Create, Rename, and Drop, plus Table Create table, Create view, Show create, Modify, Copy, and Drop are visible placeholders based on the older MaDB menu. Selecting one shows an availability notice.

MySQL database and table selection stays quiet when it completes quickly. After 750 ms, a progress message appears until the request finishes. Successful selections update the menu and header without a status message; failures appear immediately.

Running Database → Select replaces the menu groups with the available database list. Return activates that list, and another Return selects the highlighted database. Escape first leaves an active list, then restores the menu groups; from the menu groups, Escape closes the menu. After a database is selected, the menu groups return and the menu remains open. The selection uses `USE` on the existing app worker, stores the database on the active saved query, and updates the header. Ordinary query requests carry their saved database so pooled workers switch schemas when needed. Existing pinned user sessions retain their own schema and session state. Returning to the connection board keeps each query's database; editing the saved connection does not replace databases already stored on queries.

Running Table → Select similarly lists tables in the current database. A database must be selected first. After activating the table list, Return on a table fills the editor with `SELECT * FROM <table> LIMIT 100` and runs it. The menu remains open after a table is chosen or a placeholder notice is shown.

The SQLite menu keeps its working Tables and Views requests and adds Database Create, Rename, and Drop and Object Create table, Create view, Show create, Modify, Copy, and Drop placeholders. The MongoDB menu keeps its working Databases and Collections requests and adds Database Rename and Drop and Collection Create, Find, and Index placeholders. These operations use lists: Return activates a list, and another Return chooses its current item. Placeholders show an availability notice.

Each engine menu shows its Database and Table, Object, or Collection lists with Editor, Grid, List, and MaDB. Every group has the height of its title and items. Blank space fills the bottom when they fit; SPTK scrolls the menu tiles when they do not. Menu lists use normal text for their current items until activated; the active cursor has its own row background. Editor → Execute runs the current query. The List entries manage saved queries. Remaining placeholder entries show an availability notice until their features are implemented.
