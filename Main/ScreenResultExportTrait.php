<?php

namespace MADB\Main;

use \SPTK\Element;
use \MADB\Query\ResultStore;

/** Coordinates the result export panel and transforms stored result files into user-facing formats. */
trait ScreenResultExportTrait {

  const CLIPBOARD_EXPORT_WARNING_BYTES = 5242880;

  /** Opens the result export panel. */
  public static function exportResult() {
    $result = self::activeResultExportResult();
    if ($result === false) {
      \SPTK\Elements\WarningPanel::forge('No result table', 'Please execute a query with a table result before exporting results.');
      return;
    }
    self::$resultExportPanelState['result-export-sql-table'] = self::defaultExportSqlTable($result['query']);
    if ((self::$resultExportPanelState['result-export-file'] ?? '') === '') {
      self::$resultExportPanelState['result-export-file'] = self::defaultExportPath(self::$resultExportPanelState['result-export-format'] ?? 'CSV/TSV');
    }
    self::$resultExportPanel->setValue(self::$resultExportPanelState);
    self::selectResultExportFormatTab(self::$resultExportPanelState['result-export-format'] ?? 'CSV/TSV');
    self::$resultExportPanel->show();
    self::syncResultExportPanel();
    if (method_exists(self::$resultExportPanel, 'activateInput')) {
      self::$resultExportPanel->activateInput('result-export-target-file');
    }
    Element::refresh();
  }

  /** Shows or hides export panel fields based on selected target. */
  public static function syncResultExportPanel($item = null): void {
    if (self::$resultExportPanel === null || self::$resultExportPanel === false) {
      return;
    }
    $values = self::$resultExportPanel->getValue();
    $target = self::selectedResultExportTarget($values);
    $fileRow = Element::byName('result-export-file-row', self::$resultExportPanel);
    if ($fileRow !== false) {
      if ($target === 'File') {
        $fileRow->show();
      } else {
        $fileRow->hide();
      }
    }
    if (method_exists(self::$resultExportPanel, 'refreshInputList')) {
      self::$resultExportPanel->refreshInputList();
    }
    Element::refresh();
  }

  /** Synchronizes format tab state after the user changes tabs. */
  public static function syncResultExportFormat($tabs = null) {
    self::$resultExportPanelState['result-export-format'] = self::selectedResultExportFormat();
    return $tabs;
  }

  /** Applies CSV/TSV preset values to the delimited export option fields. */
  public static function syncResultDelimitedPreset($item = null): void {
    if (self::$resultExportPanel === null || self::$resultExportPanel === false) {
      return;
    }
    $values = self::$resultExportPanel->getValue();
    $preset = self::selectedDelimitedPreset($values);
    if ($preset === 'CSV') {
      self::$resultExportPanel->setValue(self::delimitedPresetValues('CSV'));
    } else if ($preset === 'TSV') {
      self::$resultExportPanel->setValue(self::delimitedPresetValues('TSV'));
    }
    Element::refresh();
  }

  /** Marks the delimited preset as user-defined after manual option edits. */
  public static function syncResultDelimitedCustomPreset($item = null): void {
    if (self::$resultExportPanel === null || self::$resultExportPanel === false) {
      return;
    }
    self::$resultExportPanel->setValue([
      'result-export-delimited-preset-csv' => false,
      'result-export-delimited-preset-tsv' => false,
      'result-export-delimited-preset-user' => true
    ]);
    Element::refresh();
  }

  /** Applies result export panel values. */
  public static function doResultExport($panel) {
    $values = $panel->getValue();
    self::$resultExportPanelState = self::normalizeResultExportPanelState($values);
    $result = self::activeResultExportResult();
    if ($result === false) {
      \SPTK\Elements\WarningPanel::forge('No result table', 'Please execute a query with a table result before exporting results.');
      return;
    }
    $request = self::resultExportRequest($result, self::$resultExportPanelState);
    if ($request === false) {
      return;
    }
    if ($request['target'] === 'Clipboard' && !$request['confirmed'] && self::shouldWarnBeforeClipboardExport($request)) {
      self::$pendingResultExport = $request;
      \SPTK\Elements\WarningPanel::forge(
        'Large clipboard export',
        'This result may be large. Exporting to clipboard can take time and use significant memory.',
        [
          ['text' => 'Export', 'hotKey' => 'RETURN', 'onPress' => '\MADB\Main\ResultExportController::confirmLargeClipboardExport'],
          ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close']
        ]
      );
      return;
    }
    self::runResultExport($request, $panel);
  }

  /** Continues a large clipboard export after confirmation. */
  public static function confirmLargeClipboardExport($confirmationPanel) {
    if (self::$pendingResultExport === false) {
      $confirmationPanel->remove();
      return;
    }
    $request = self::$pendingResultExport;
    self::$pendingResultExport = false;
    $request['confirmed'] = true;
    $confirmationPanel->remove();
    self::runResultExport($request, self::$resultExportPanel);
  }

  /** Normalizes remembered result export panel state. */
  private static function normalizeResultExportPanelState($values): array {
    $values = array_merge(self::$resultExportPanelState, $values);
    $target = self::selectedResultExportTarget($values);
    $source = self::selectedResultExportSource($values);
    $format = self::selectedResultExportFormat();
    try {
      $delimited = self::resultExportDelimitedOptions($state, $format);
    } catch (\Exception $e) {
      \SPTK\Elements\WarningPanel::forge('Invalid delimited settings', $e->getMessage());
      Element::refresh();
      return false;
    }
    return [
      'result-export-target-file' => $target === 'File',
      'result-export-target-clipboard' => $target === 'Clipboard',
      'result-export-source-all' => $source === 'All',
      'result-export-source-selection' => $source === 'Selection',
      'result-export-source-rows' => $source === 'Rows',
      'result-export-source-columns' => $source === 'Columns',
      'result-export-format' => $format,
      'result-export-file' => (string)($values['result-export-file'] ?? ''),
      'result-export-max-rows' => trim((string)($values['result-export-max-rows'] ?? '')),
      'result-export-delimited-preset-csv' => self::selectedDelimitedPreset($values) === 'CSV',
      'result-export-delimited-preset-tsv' => self::selectedDelimitedPreset($values) === 'TSV',
      'result-export-delimited-preset-user' => self::selectedDelimitedPreset($values) === 'User defined',
      'result-export-delimited-headers' => self::boolValue($values['result-export-delimited-headers'] ?? false),
      'result-export-delimited-null-text' => (string)($values['result-export-delimited-null-text'] ?? 'NULL'),
      'result-export-delimited-separator' => (string)($values['result-export-delimited-separator'] ?? ','),
      'result-export-delimited-string-delimiter' => (string)($values['result-export-delimited-string-delimiter'] ?? '"'),
      'result-export-delimited-line-end' => (string)($values['result-export-delimited-line-end'] ?? '\r\n'),
      'result-export-delimited-escape-char' => (string)($values['result-export-delimited-escape-char'] ?? ''),
      'result-export-markdown-headers' => self::boolValue($values['result-export-markdown-headers'] ?? false),
      'result-export-markdown-null-text' => (string)($values['result-export-markdown-null-text'] ?? 'NULL'),
      'result-export-html-headers' => self::boolValue($values['result-export-html-headers'] ?? false),
      'result-export-html-null-text' => (string)($values['result-export-html-null-text'] ?? 'NULL'),
      'result-export-xml-null-text' => (string)($values['result-export-xml-null-text'] ?? 'NULL'),
      'result-export-json-headers' => self::boolValue($values['result-export-json-headers'] ?? false),
      'result-export-json-pretty' => self::boolValue($values['result-export-json-pretty'] ?? false),
      'result-export-sql-table' => trim((string)($values['result-export-sql-table'] ?? ''))
    ];
  }

  /** Returns selected export target from radio-button values. */
  private static function selectedResultExportTarget(array $values): string {
    return self::boolValue($values['result-export-target-clipboard'] ?? false) ? 'Clipboard' : 'File';
  }

  /** Returns selected export source from radio-button values. */
  private static function selectedResultExportSource(array $values): string {
    if (self::boolValue($values['result-export-source-selection'] ?? false)) {
      return 'Selection';
    }
    if (self::boolValue($values['result-export-source-rows'] ?? false)) {
      return 'Rows';
    }
    if (self::boolValue($values['result-export-source-columns'] ?? false)) {
      return 'Columns';
    }
    return 'All';
  }

  /** Returns the selected format from the active format tab. */
  private static function selectedResultExportFormat(): string {
    $tabs = Element::byName('result-export-format-tabs', self::$resultExportPanel);
    $contentName = $tabs !== false && method_exists($tabs, 'getCurrentTabContentName') ? $tabs->getCurrentTabContentName() : false;
    return match ($contentName) {
      'result-export-format-markdown' => 'Markdown',
      'result-export-format-html' => 'HTML',
      'result-export-format-xml' => 'XML',
      'result-export-format-json' => 'JSON',
      'result-export-format-sql' => 'SQL INSERT',
      default => 'CSV/TSV',
    };
  }

  /** Selects the format tab that matches a remembered format. */
  private static function selectResultExportFormatTab(string $format): void {
    $tabs = Element::byName('result-export-format-tabs', self::$resultExportPanel);
    if ($tabs === false || !method_exists($tabs, 'selectTab')) {
      return;
    }
    $index = match ($format) {
      'Markdown' => 1,
      'HTML' => 2,
      'XML' => 3,
      'JSON' => 4,
      'SQL INSERT' => 5,
      default => 0,
    };
    $tabs->selectTab($index, false);
  }

  /** Returns the selected CSV/TSV preset from radio-button values. */
  private static function selectedDelimitedPreset(array $values): string {
    if (self::boolValue($values['result-export-delimited-preset-tsv'] ?? false)) {
      return 'TSV';
    }
    if (self::boolValue($values['result-export-delimited-preset-user'] ?? false)) {
      return 'User defined';
    }
    return 'CSV';
  }

  /** Returns panel values for a CSV/TSV preset. */
  private static function delimitedPresetValues(string $preset): array {
    if ($preset === 'TSV') {
      return [
        'result-export-delimited-separator' => '\t',
        'result-export-delimited-string-delimiter' => '',
        'result-export-delimited-line-end' => '\n',
        'result-export-delimited-escape-char' => '\\'
      ];
    }
    return [
      'result-export-delimited-separator' => ',',
      'result-export-delimited-string-delimiter' => '"',
      'result-export-delimited-line-end' => '\r\n',
      'result-export-delimited-escape-char' => ''
    ];
  }

  /** Returns the active result-set metadata and file path. */
  private static function activeResultExportResult() {
    if (
      self::$connectionName === false ||
      self::$resultTable === null ||
      self::$resultTable === false ||
      !self::$resultTable->isDisplayed()
    ) {
      return false;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false) {
      return false;
    }
    $result = false;
    if (!empty($query['results']) && is_array($query['results'])) {
      $activeStatement = $query['activeStatement'] ?? false;
      $entry = false;
      if ($activeStatement !== false) {
        $entry = self::resultForStatement($query['results'], (int)$activeStatement);
      }
      if ($entry === false) {
        $active = max(0, min((int)($query['activeResult'] ?? count($query['results']) - 1), count($query['results']) - 1));
        $entry = $query['results'][$active] ?? false;
      }
      $result = is_array($entry) ? ($entry['result'] ?? false) : false;
    } else {
      $result = $query['result'] ?? false;
    }
    if (!is_array($result) || !isset($result['columns'], $result['rowCount'], $result['file'])) {
      return false;
    }
    $file = ResultStore::absolutePath($result['file']);
    if ($file === false || !is_file($file) || !is_readable($file)) {
      return false;
    }
    return [
      'query' => $query,
      'file' => $file,
      'columns' => array_values($result['columns']),
      'rowCount' => (int)$result['rowCount']
    ];
  }

  /** Builds a validated export request from panel state and active table state. */
  private static function resultExportRequest(array $result, array $state) {
    $format = $state['result-export-format'];
    $target = self::selectedResultExportTarget($state);
    $path = trim($state['result-export-file']);
    if ($target === 'File') {
      if ($path === '' || is_dir($path)) {
        \SPTK\Elements\WarningPanel::forge('Missing file name', 'Please enter a file name before exporting the result.');
        Element::refresh();
        return false;
      }
      $dir = dirname($path);
      if (!is_dir($dir) || !is_writable($dir)) {
        \SPTK\Elements\ErrorPanel::forge('Could not export result', "The target directory is not writable:\n{$dir}");
        Element::refresh();
        return false;
      }
    }
    $maxRows = self::exportMaxRows($state['result-export-max-rows']);
    if ($maxRows === false) {
      \SPTK\Elements\WarningPanel::forge('Invalid row limit', 'Max rows must be empty, zero, or a positive integer.');
      Element::refresh();
      return false;
    }
    $source = self::selectedResultExportSource($state);
    $bounds = self::resultExportBounds($source, $result);
    if ($bounds === false) {
      \SPTK\Elements\WarningPanel::forge('No exportable selection', 'The current result selection cannot be exported.');
      Element::refresh();
      return false;
    }
    return [
      'result' => $result,
      'source' => $source,
      'format' => $format,
      'target' => $target,
      'path' => $path,
      'bounds' => $bounds,
      'maxRows' => $maxRows,
      'includeHeaders' => self::resultExportIncludeHeaders($state, $format),
      'nullText' => self::resultExportNullText($state, $format),
      'delimited' => $delimited,
      'prettyJson' => self::boolValue($state['result-export-json-pretty'] ?? false),
      'sqlTable' => $state['result-export-sql-table'] === '' ? self::defaultExportSqlTable($result['query']) : $state['result-export-sql-table'],
      'confirmed' => false
    ];
  }

  /** Converts max rows panel text to an integer row cap or null for unlimited. */
  private static function exportMaxRows(string $value) {
    if ($value === '') {
      return null;
    }
    if (!ctype_digit($value)) {
      return false;
    }
    $limit = (int)$value;
    return $limit === 0 ? null : $limit;
  }

  /** Returns whether the selected format should include headers. */
  private static function resultExportIncludeHeaders(array $state, string $format): bool {
    return match ($format) {
      'CSV/TSV' => self::boolValue($state['result-export-delimited-headers'] ?? false),
      'Markdown' => self::boolValue($state['result-export-markdown-headers'] ?? false),
      'HTML' => self::boolValue($state['result-export-html-headers'] ?? false),
      'JSON' => self::boolValue($state['result-export-json-headers'] ?? false),
      'XML', 'SQL INSERT' => true,
      default => false,
    };
  }

  /** Returns the null display text for the selected format. */
  private static function resultExportNullText(array $state, string $format): string {
    return match ($format) {
      'CSV/TSV' => (string)($state['result-export-delimited-null-text'] ?? 'NULL'),
      'Markdown' => (string)($state['result-export-markdown-null-text'] ?? 'NULL'),
      'HTML' => (string)($state['result-export-html-null-text'] ?? 'NULL'),
      'XML' => (string)($state['result-export-xml-null-text'] ?? 'NULL'),
      default => 'NULL',
    };
  }

  /** Returns decoded delimited-text options for CSV/TSV export. */
  private static function resultExportDelimitedOptions(array $state, string $format): array {
    if ($format !== 'CSV/TSV') {
      return [];
    }
    $separator = self::decodeDelimitedOption((string)($state['result-export-delimited-separator'] ?? ','));
    $lineEnd = self::decodeDelimitedOption((string)($state['result-export-delimited-line-end'] ?? '\r\n'));
    $stringDelimiter = self::decodeDelimitedOption((string)($state['result-export-delimited-string-delimiter'] ?? '"'));
    $escapeChar = self::decodeDelimitedOption((string)($state['result-export-delimited-escape-char'] ?? ''));
    if ($separator === '') {
      throw new \Exception('Field separator cannot be empty.');
    }
    if ($lineEnd === '') {
      throw new \Exception('Line end cannot be empty.');
    }
    return [
      'separator' => $separator,
      'lineEnd' => $lineEnd,
      'stringDelimiter' => $stringDelimiter,
      'escapeChar' => $escapeChar
    ];
  }

  /** Returns row and column bounds for the chosen export source. */
  private static function resultExportBounds(string $source, array $result) {
    $rowCount = max(0, (int)$result['rowCount']);
    $columnCount = count($result['columns']);
    if ($columnCount === 0) {
      return false;
    }
    if ($rowCount === 0) {
      [, $col1, , $col2] = method_exists(self::$resultTable, 'getSelection') ? self::$resultTable->getSelection() : [0, 0, 0, 0];
      $col1 = max(0, min((int)$col1, $columnCount - 1));
      $col2 = max(0, min((int)$col2, $columnCount - 1));
      if ($source === 'Columns') {
        return [0, -1, $col1, $col2];
      }
      if ($source === 'Selection' || $source === 'Rows') {
        return false;
      }
      return [0, -1, 0, $columnCount - 1];
    }
    [$row1, $col1, $row2, $col2] = method_exists(self::$resultTable, 'getSelection') ? self::$resultTable->getSelection() : [0, 0, 0, 0];
    $row1 = max(0, min((int)$row1, $rowCount - 1));
    $row2 = max(0, min((int)$row2, $rowCount - 1));
    $col1 = max(0, min((int)$col1, $columnCount - 1));
    $col2 = max(0, min((int)$col2, $columnCount - 1));
    if ($source === 'Selection') {
      return [$row1, $row2, $col1, $col2];
    }
    if ($source === 'Rows') {
      return [$row1, $row2, 0, $columnCount - 1];
    }
    if ($source === 'Columns') {
      return [0, $rowCount - 1, $col1, $col2];
    }
    return [0, $rowCount - 1, 0, $columnCount - 1];
  }

  /** Runs export request and reports the result. */
  private static function runResultExport(array $request, $panel = null): void {
    $handle = false;
    $memory = false;
    try {
      if ($request['target'] === 'Clipboard') {
        $memory = fopen('php://temp', 'w+b');
        $handle = $memory;
      } else {
        $handle = fopen($request['path'], 'wb');
      }
      if ($handle === false) {
        throw new \Exception('Could not open export target.');
      }
      $count = self::writeResultExport($handle, $request);
      if ($request['target'] === 'Clipboard') {
        rewind($memory);
        \SPTK\Clipboard::set(stream_get_contents($memory));
        \SPTK\Elements\Panel::forge('Result exported', $count . ' row(s) copied to clipboard.');
      } else {
        \SPTK\Elements\Panel::forge('Result exported', $count . " row(s) saved to:\n" . $request['path']);
      }
      if ($panel !== null && method_exists($panel, 'hide')) {
        $panel->hide();
      }
    } catch (\Exception $e) {
      \SPTK\Elements\ErrorPanel::forge('Could not export result', $e->getMessage());
    } finally {
      if (is_resource($handle)) {
        fclose($handle);
      }
      Element::refresh();
    }
  }

  /** Returns whether clipboard export should ask for confirmation first. */
  private static function shouldWarnBeforeClipboardExport(array $request): bool {
    $fileSize = filesize($request['result']['file']) ?: 0;
    return $fileSize > self::CLIPBOARD_EXPORT_WARNING_BYTES && $request['maxRows'] === null;
  }

  /** Writes a transformed export to an open handle. */
  private static function writeResultExport($handle, array $request): int {
    if ($request['format'] === 'JSON') {
      return self::writeJsonResultExport($handle, $request);
    }
    if ($request['format'] === 'SQL INSERT') {
      return self::writeSqlResultExport($handle, $request);
    }
    if ($request['format'] === 'HTML') {
      fwrite($handle, "<table>\n");
    } else if ($request['format'] === 'XML') {
      fwrite($handle, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<result>\n");
    }
    $headers = self::selectedExportHeaders($request);
    if ($request['includeHeaders']) {
      self::writeExportHeader($handle, $request, $headers);
    } else if ($request['format'] === 'HTML') {
      fwrite($handle, "  <tbody>\n");
    }
    $count = 0;
    foreach (self::exportRows($request) as $row) {
      self::writeExportRow($handle, $request, $headers, $row);
      $count++;
    }
    if ($request['format'] === 'HTML') {
      fwrite($handle, "  </tbody>\n</table>\n");
    } else if ($request['format'] === 'XML') {
      fwrite($handle, "</result>\n");
    }
    return $count;
  }

  /** Writes JSON export rows. */
  private static function writeJsonResultExport($handle, array $request): int {
    $headers = self::selectedExportHeaders($request);
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if ($request['prettyJson']) {
      $flags |= JSON_PRETTY_PRINT;
    }
    if ($request['prettyJson']) {
      fwrite($handle, "[\n");
    } else {
      fwrite($handle, '[');
    }
    $count = 0;
    foreach (self::exportRows($request) as $row) {
      if ($request['includeHeaders']) {
        $item = [];
        foreach ($headers as $index => $header) {
          $item[$header] = $row[$index] ?? null;
        }
      } else {
        $item = $row;
      }
      $json = json_encode($item, $flags);
      if ($json === false) {
        throw new \Exception('Could not encode JSON export.');
      }
      if ($request['prettyJson']) {
        if ($count > 0) {
          fwrite($handle, ",\n");
        } else {
          fwrite($handle, '');
        }
        fwrite($handle, self::indentJsonExport($json));
      } else {
        fwrite($handle, ($count > 0 ? ',' : '') . $json);
      }
      $count++;
    }
    if ($request['prettyJson']) {
      fwrite($handle, ($count > 0 ? "\n" : '') . "]\n");
    } else {
      fwrite($handle, "]\n");
    }
    return $count;
  }

  /** Writes SQL INSERT export rows. */
  private static function writeSqlResultExport($handle, array $request): int {
    $headers = self::selectedExportHeaders($request);
    $table = self::quoteExportSqlName($request['sqlTable']);
    $columns = array_map(fn($header) => self::quoteExportSqlIdentifier($header), $headers);
    $count = 0;
    foreach (self::exportRows($request) as $row) {
      $values = array_map(fn($value) => self::quoteExportSqlValue($value), $row);
      fwrite($handle, 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n");
      $count++;
    }
    return $count;
  }

  /** Indents a pretty-printed JSON item inside the exported top-level array. */
  private static function indentJsonExport(string $json): string {
    return '  ' . str_replace("\n", "\n  ", $json);
  }

  /** Writes a header row for formats that support streaming headers. */
  private static function writeExportHeader($handle, array $request, array $headers): void {
    switch ($request['format']) {
      case 'CSV/TSV':
        fwrite($handle, self::delimitedExportRow($headers, $request['delimited']));
        break;
      case 'Markdown':
        fwrite($handle, self::markdownExportRow($headers) . "\n");
        fwrite($handle, self::markdownExportRow(array_fill(0, count($headers), '---')) . "\n");
        break;
      case 'HTML':
        fwrite($handle, "  <thead><tr>");
        foreach ($headers as $header) {
          fwrite($handle, '<th>' . htmlspecialchars($header, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th>');
        }
        fwrite($handle, "</tr></thead>\n  <tbody>\n");
        break;
    }
  }

  /** Writes one exported data row. */
  private static function writeExportRow($handle, array $request, array $headers, array $row): void {
    switch ($request['format']) {
      case 'CSV/TSV':
        fwrite($handle, self::delimitedExportRow(array_map(fn($value) => $value === null ? $request['nullText'] : $value, $row), $request['delimited']));
        break;
      case 'Markdown':
        fwrite($handle, self::markdownExportRow(array_map(fn($value) => $value === null ? $request['nullText'] : $value, $row)) . "\n");
        break;
      case 'HTML':
        fwrite($handle, "    <tr>");
        foreach ($row as $value) {
          $text = $value === null ? $request['nullText'] : (string)$value;
          fwrite($handle, '<td>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>');
        }
        fwrite($handle, "</tr>\n");
        break;
      case 'XML':
        fwrite($handle, "  <row>\n");
        foreach ($headers as $index => $header) {
          $name = self::xmlExportElementName($header);
          $text = $row[$index] === null ? $request['nullText'] : (string)$row[$index];
          fwrite($handle, '    <' . $name . ' column="' . htmlspecialchars($header, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">');
          fwrite($handle, htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
          fwrite($handle, '</' . $name . ">\n");
        }
        fwrite($handle, "  </row>\n");
        break;
    }
  }

  /** Yields selected rows from the internal result file. */
  private static function exportRows(array $request): \Generator {
    [$row1, $row2, $col1, $col2] = $request['bounds'];
    $handle = fopen($request['result']['file'], 'rb');
    if ($handle === false) {
      throw new \Exception('Could not read result file.');
    }
    try {
      fgets($handle);
      $rowIndex = 0;
      $count = 0;
      while (($line = fgets($handle)) !== false) {
        if ($rowIndex > $row2) {
          break;
        }
        if ($rowIndex >= $row1) {
          $row = self::parseExportResultLine($line);
          yield array_slice($row, $col1, $col2 - $col1 + 1);
          $count++;
          if ($request['maxRows'] !== null && $count >= $request['maxRows']) {
            break;
          }
        }
        $rowIndex++;
      }
    } finally {
      fclose($handle);
    }
  }

  /** Returns selected headers for the request. */
  private static function selectedExportHeaders(array $request): array {
    [, , $col1, $col2] = $request['bounds'];
    return array_slice($request['result']['columns'], $col1, $col2 - $col1 + 1);
  }

  /** Parses one internal escaped TSV result line. */
  private static function parseExportResultLine(string $line): array {
    $line = rtrim($line, "\r\n");
    $fields = [];
    $field = '';
    $escaping = false;
    $length = strlen($line);
    for ($i = 0; $i < $length; $i++) {
      $char = $line[$i];
      if ($escaping) {
        $field .= match ($char) {
          't' => "\t",
          'n' => "\n",
          'r' => "\r",
          '\\' => '\\',
          default => "\\{$char}",
        };
        $escaping = false;
        continue;
      }
      if ($char === "\\") {
        $escaping = true;
      } else if ($char === "\t") {
        $fields[] = ($field === '\N' ? null : $field);
        $field = '';
      } else {
        $field .= $char;
      }
    }
    if ($escaping) {
      $field .= '\\';
    }
    $fields[] = ($field === '\N' ? null : $field);
    return $fields;
  }

  /** Decodes user-facing escape sequences in delimited export settings. */
  private static function decodeDelimitedOption(string $value): string {
    $out = '';
    $escaping = false;
    $length = strlen($value);
    for ($i = 0; $i < $length; $i++) {
      $char = $value[$i];
      if ($escaping) {
        $out .= match ($char) {
          't' => "\t",
          'n' => "\n",
          'r' => "\r",
          '\\' => '\\',
          default => $char,
        };
        $escaping = false;
        continue;
      }
      if ($char === "\\") {
        $escaping = true;
      } else {
        $out .= $char;
      }
    }
    if ($escaping) {
      $out .= '\\';
    }
    return $out;
  }

  /** Formats one configurable delimited text row. */
  private static function delimitedExportRow(array $values, array $options): string {
    $fields = [];
    foreach ($values as $value) {
      $fields[] = self::delimitedExportValue((string)$value, $options);
    }
    return implode($options['separator'], $fields) . $options['lineEnd'];
  }

  /** Formats one configurable delimited text field. */
  private static function delimitedExportValue(string $value, array $options): string {
    $separator = $options['separator'];
    $lineEnd = $options['lineEnd'];
    $delimiter = $options['stringDelimiter'];
    $escape = $options['escapeChar'];
    if ($delimiter !== '') {
      $needsDelimiter = str_contains($value, $separator) ||
        str_contains($value, $delimiter) ||
        str_contains($value, "\n") ||
        str_contains($value, "\r") ||
        ($lineEnd !== '' && str_contains($value, $lineEnd));
      if ($escape !== '') {
        $value = str_replace($escape, $escape . $escape, $value);
        $value = str_replace($delimiter, $escape . $delimiter, $value);
      } else {
        $value = str_replace($delimiter, $delimiter . $delimiter, $value);
      }
      return $needsDelimiter ? $delimiter . $value . $delimiter : $value;
    }
    if ($escape === '') {
      return $value;
    }
    $value = str_replace($escape, $escape . $escape, $value);
    $value = str_replace(["\t", "\n", "\r"], [$escape . 't', $escape . 'n', $escape . 'r'], $value);
    if (!in_array($separator, ["\t", "\n", "\r", $escape], true)) {
      $value = str_replace($separator, $escape . $separator, $value);
    }
    return $value;
  }

  /** Formats one markdown table row. */
  private static function markdownExportRow(array $values): string {
    $escaped = [];
    foreach ($values as $value) {
      $escaped[] = str_replace(["\\", "|", "\r", "\n"], ["\\\\", "\\|", " ", " "], (string)$value);
    }
    return '| ' . implode(' | ', $escaped) . ' |';
  }

  /** Returns a safe XML element name for a column. */
  private static function xmlExportElementName(string $header): string {
    $name = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $header);
    $name = trim($name, '_');
    if ($name === '' || preg_match('/^[A-Za-z_]/', $name) !== 1) {
      $name = 'field_' . $name;
    }
    return $name;
  }

  /** Quotes an SQL identifier. */
  private static function quoteExportSqlIdentifier(string $identifier): string {
    return '`' . str_replace('`', '``', $identifier) . '`';
  }

  /** Quotes a possibly qualified SQL table name. */
  private static function quoteExportSqlName(string $name): string {
    $parts = array_values(array_filter(array_map('trim', explode('.', $name)), fn($part) => $part !== ''));
    if (empty($parts)) {
      $parts = ['exported_result'];
    }
    return implode('.', array_map([self::class, 'quoteExportSqlIdentifier'], $parts));
  }

  /** Quotes an SQL literal value. */
  private static function quoteExportSqlValue($value): string {
    if ($value === null) {
      return 'NULL';
    }
    return "'" . str_replace(["\\", "'"], ["\\\\", "''"], (string)$value) . "'";
  }

  /** Returns default SQL table name for insert exports. */
  private static function defaultExportSqlTable($query): string {
    $table = self::currentTable(is_array($query) ? $query : []);
    return $table === '' ? 'exported_result' : $table;
  }

  /** Returns default export path for a format. */
  private static function defaultExportPath(string $format): string {
    $extension = match ($format) {
      'Markdown' => 'md',
      'HTML' => 'html',
      'XML' => 'xml',
      'JSON' => 'json',
      'SQL INSERT' => 'sql',
      default => 'csv',
    };
    return rtrim(self::homePath(), '/') . '/madb-result.' . $extension;
  }

}
