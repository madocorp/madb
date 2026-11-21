# Engines

Each supported engine owns a `WorkerBackend` that opens one database connection inside a worker process and runs tests or queries on that connection. MySQL and SQLite use PDO; MongoDB uses its PHP driver. Query results are bounded to 500 rows or documents. Separate engine-specific screens and controllers can grow here while the connection selector and board remain shared.

The current main screens have engine-specific menus and result views. MySQL and SQLite share SQL highlighting and a tabular result view; MongoDB uses JSON highlighting and a document view. MySQL connections can optionally save a starting database name. The MySQL menu stores database selections per saved query; pooled workers apply the database requested by each query.
