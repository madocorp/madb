# MADB

MADB is a graphical MySQL client written in PHP. It runs as a desktop-style
SPTK application and provides connection management, schema and table browsing,
query editing, saved query lists, result tables, row-level operations, exports,
and background worker processes for database jobs.

MADB currently targets MySQL and MariaDB through PDO.

## Requirements

### Operating System

MADB uses SPTK for the graphical interface and PCNTL/POSIX process handling for
background workers. It is intended for Linux or another Unix-like system with
those PHP extensions available.

### PHP CLI

PHP 8 must be installed and available in your `PATH`.

Required PHP extensions:

- FFI
- PCNTL
- POSIX
- Mbstring
- XML, DOM, and XMLReader
- PDO
- PDO MySQL

OpenSSL is required if you want to use the master password feature for
encrypted connection passwords.

Check with:

```sh
php --version
php -m | grep -E '^(dom|FFI|mbstring|openssl|pcntl|pdo_mysql|PDO|posix|xml|xmlreader)$'
```

### SPTK

[SPTK](https://github.com/madocorp/SPTK), the SDL-based PHP Toolkit, is
required. Install SPTK first and follow its SDL3 and SDL3_ttf setup
instructions.

You will need the **SPTK directory path** during MADB installation.

### MySQL or MariaDB

MADB connects to MySQL-compatible servers using PHP's PDO MySQL driver. Install
the client/runtime packages required by your PHP distribution, then create a
database user with the privileges needed for the work you plan to do.

Use limited test credentials when trying schema, table, row edit, copy, drop, or
export workflows.

## Installation

This is a **manual installation**. MADB does not use Composer or a package
manager.

### 1. Choose Installation Location

You can install MADB anywhere. Common locations are:

```text
~/.local/share/madb
/opt/madb
```

The chosen location is referred to as `INSTALL_DIR` below. Use `sudo` for
system-wide locations such as `/opt/madb`.

### 2. Download the Source Code

```sh
INSTALL_DIR="$HOME/.local/share/madb"
mkdir -p "$(dirname "$INSTALL_DIR")"
git clone https://github.com/madocorp/madb.git "$INSTALL_DIR"
cd "$INSTALL_DIR"
```

Change `INSTALL_DIR` if you want another location.

### 3. Configure the SPTK Symlink

MADB expects SPTK to be available as `SPTK` inside the MADB installation
directory.

```sh
SPTK_DIR="$HOME/.local/share/SPTK"
ln -s "$SPTK_DIR" SPTK
```

Change `SPTK_DIR` to the actual SPTK directory path.

### 4. Make the Main Script Executable

```sh
chmod +x madb.php
```

### 5. Create a Command Wrapper

Create a small wrapper in a directory from your `PATH`, for example
`~/.local/bin`, `~/bin`, or `/usr/local/bin`.

```sh
BIN_DIR="$HOME/.local/bin"
mkdir -p "$BIN_DIR"
cat > "$BIN_DIR/madb" <<SH
#!/bin/sh
cd "$INSTALL_DIR" || exit 1
exec php ./madb.php "\$@"
SH
chmod +x "$BIN_DIR/madb"
```

The wrapper starts MADB from its installation directory so the relative `SPTK`
and `Layout` paths resolve correctly.

### 6. Run MADB

```sh
madb
```

You can also run it directly from the installation directory:

```sh
cd "$INSTALL_DIR"
./madb.php
```

## First Run

Open `MADB -> Connection -> Create -> MySQL` and add a connection. Use `Test
connection` before saving if you want to validate the credentials immediately.

After selecting a connection, MADB loads schemas, tables, saved queries, and
result views through background workers. Use the built-in Help panel for the
keyboard reference and screen-specific workflow notes.

## Settings and Stored Data

MADB stores user data under:

```text
~/.config/madb/
```

Important files:

- `connections.json` stores saved connection definitions.
- `queries.json` stores saved queries.
- `settings.json` stores application settings.

The Settings panel supports:

- default export directory
- default `SELECT` limit
- master password for encrypted connection passwords

If a master password is configured, MADB asks for it after startup. You can also
use `MADB -> Unlock` later. When the session is locked and a saved connection
password cannot be decrypted, MADB asks for the database password for that
connection without saving the prompted value.

## Updating

Update MADB from its installation directory:

```sh
cd "$INSTALL_DIR"
git pull
```

If SPTK is installed separately, update it from the SPTK directory:

```sh
cd /path/to/SPTK
git pull
```

## Development Checks

Run a syntax check for a changed PHP file:

```sh
php -l path/to/File.php
```

Run the SQL formatter regression cases when touching query formatting,
tokenization, lexicon, or splitter behavior:

```sh
php Query/SqlFormatter/SqlFormatterTest.php
```

Lint all PHP files:

```sh
find . -path ./vendor -prune -o -name '*.php' -exec php -l {} +
```

## License

MADB is released into the public domain under the [Unlicense](UNLICENSE).
