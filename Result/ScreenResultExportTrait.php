<?php

namespace MADB\Result;

use \SPTK\Element;
use \MADB\Result\ResultStore;

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
    $switchedToMongoJson = false;
    if (self::isMongoDocumentExportResult($result) && (self::$resultExportPanelState['result-export-format'] ?? 'CSV/TSV') === 'CSV/TSV') {
      self::$resultExportPanelState['result-export-format'] = 'Mongo JSON';
      $switchedToMongoJson = true;
    }
    if ((self::$resultExportPanelState['result-export-file'] ?? '') === '') {
      self::$resultExportPanelState['result-export-file'] = self::defaultExportPath(self::$resultExportPanelState['result-export-format'] ?? 'CSV/TSV');
    } else if ($switchedToMongoJson) {
      self::$resultExportPanelState['result-export-file'] = self::exportPathForFormat(self::$resultExportPanelState['result-export-file'], 'Mongo JSON');
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
    if (self::$resultExportPanel === null || self::$resultExportPanel === false) {
      return $tabs;
    }
    $values = self::$resultExportPanel->getValue();
    $format = self::selectedResultExportFormat();
    $path = self::exportPathForFormat((string)($values['result-export-file'] ?? ''), $format);
    self::$resultExportPanelState['result-export-format'] = $format;
    self::$resultExportPanelState['result-export-file'] = $path;
    self::$resultExportPanel->setValue([
      'result-export-file' => $path
    ]);
    Element::refresh();
    return $tabs;
  }

  /** Applies CSV/TSV preset values to the delimited export option fields. */
  public static function syncResultDelimitedPreset($item = null): void {
    if (self::$resultExportPanel === null || self::$resultExportPanel === false) {
      return;
    }
    $values = self::$resultExportPanel->getValue();
    $preset = self::selectedDelimitedPreset($values);
    $path = self::exportPathForDelimitedPreset((string)($values['result-export-file'] ?? ''), $preset);
    if ($preset === 'CSV') {
      self::$resultExportPanel->setValue(array_merge(self::delimitedPresetValues('CSV'), [
        'result-export-file' => $path
      ]));
    } else if ($preset === 'TSV') {
      self::$resultExportPanel->setValue(array_merge(self::delimitedPresetValues('TSV'), [
        'result-export-file' => $path
      ]));
    }
    self::$resultExportPanelState['result-export-file'] = $path;
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
    $state = self::normalizeResultExportPanelState($values);
    if ($state === false) {
      return;
    }
    self::$resultExportPanelState = $state;
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
          ['text' => 'Export', 'hotKey' => 'RETURN', 'onPress' => '\MADB\Result\ResultExportController::confirmLargeClipboardExport'],
          ['text' => 'Cancel', 'hotKey' => 'ESCAPE', 'onPress' => 'close']
        ]
      );
      return;
    }
    self::startResultExportTask($request, $panel);
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
    self::startResultExportTask($request, self::$resultExportPanel);
  }

  /** Normalizes remembered result export panel state. */
  private static function normalizeResultExportPanelState($values) {
    $values = array_merge(self::$resultExportPanelState, $values);
    $target = self::selectedResultExportTarget($values);
    $source = self::selectedResultExportSource($values);
    $format = self::selectedResultExportFormat();
    try {
      self::resultExportDelimitedOptions($values, $format);
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
      'result-export-delimited-line-end' => (string)($values['result-export-delimited-line-end'] ?? '\n'),
      'result-export-delimited-escape-char' => (string)($values['result-export-delimited-escape-char'] ?? ''),
      'result-export-markdown-headers' => self::boolValue($values['result-export-markdown-headers'] ?? false),
      'result-export-markdown-line-collapse' => self::selectedMarkdownLineMode($values) === 'Collapse',
      'result-export-markdown-line-br' => self::selectedMarkdownLineMode($values) === 'BR tag',
      'result-export-markdown-line-literal' => self::selectedMarkdownLineMode($values) === 'Literal',
      'result-export-markdown-null-text' => (string)($values['result-export-markdown-null-text'] ?? 'NULL'),
      'result-export-markdown-length' => trim((string)($values['result-export-markdown-length'] ?? '')),
      'result-export-html-headers' => self::boolValue($values['result-export-html-headers'] ?? false),
      'result-export-html-document' => self::boolValue($values['result-export-html-document'] ?? false),
      'result-export-html-multiline' => self::boolValue($values['result-export-html-multiline'] ?? false),
      'result-export-html-null-text' => (string)($values['result-export-html-null-text'] ?? 'NULL'),
      'result-export-html-title' => (string)($values['result-export-html-title'] ?? 'MADB result'),
      'result-export-xml-declaration' => self::boolValue($values['result-export-xml-declaration'] ?? false),
      'result-export-xml-compact' => self::boolValue($values['result-export-xml-compact'] ?? false),
      'result-export-xml-root' => trim((string)($values['result-export-xml-root'] ?? 'result')),
      'result-export-xml-row' => trim((string)($values['result-export-xml-row'] ?? 'row')),
      'result-export-xml-field' => trim((string)($values['result-export-xml-field'] ?? '')),
      'result-export-xml-null-text' => (string)($values['result-export-xml-null-text'] ?? 'NULL'),
      'result-export-json-headers' => self::boolValue($values['result-export-json-headers'] ?? false),
      'result-export-json-pretty' => self::boolValue($values['result-export-json-pretty'] ?? false),
      'result-export-json-unescaped-unicode' => self::boolValue($values['result-export-json-unescaped-unicode'] ?? false),
      'result-export-json-unescaped-slashes' => self::boolValue($values['result-export-json-unescaped-slashes'] ?? false),
      'result-export-json-unescaped-lineterm' => self::boolValue($values['result-export-json-unescaped-lineterm'] ?? false),
      'result-export-mongo-json-pretty' => self::boolValue($values['result-export-mongo-json-pretty'] ?? true),
      'result-export-mongo-json-unescaped-unicode' => self::boolValue($values['result-export-mongo-json-unescaped-unicode'] ?? true),
      'result-export-mongo-json-unescaped-slashes' => self::boolValue($values['result-export-mongo-json-unescaped-slashes'] ?? true),
      'result-export-sql-schema' => trim((string)($values['result-export-sql-schema'] ?? '')),
      'result-export-sql-table' => trim((string)($values['result-export-sql-table'] ?? '')),
      'result-export-sql-group-insert' => trim((string)($values['result-export-sql-group-insert'] ?? '')),
      'result-export-sql-add-info' => self::boolValue($values['result-export-sql-add-info'] ?? false)
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
      'result-export-format-mongo-json' => 'Mongo JSON',
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
      'JSON' => 1,
      'Mongo JSON' => 2,
      'SQL INSERT' => 3,
      'Markdown' => 4,
      'HTML' => 5,
      'XML' => 6,
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

  /** Returns the selected markdown multiline handling mode. */
  private static function selectedMarkdownLineMode(array $values): string {
    if (self::boolValue($values['result-export-markdown-line-br'] ?? false)) {
      return 'BR tag';
    }
    if (self::boolValue($values['result-export-markdown-line-literal'] ?? false)) {
      return 'Literal';
    }
    return 'Collapse';
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
      'result-export-delimited-line-end' => '\n',
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
    $rowCount = (int)$result['rowCount'];
    if (is_array(self::$resultFilterState)) {
      $filterFile = self::$resultFilterState['filterFile'] ?? false;
      if (is_string($filterFile) && is_file($filterFile) && is_readable($filterFile)) {
        $file = $filterFile;
        $rowCount = (int)(self::$resultFilterState['rowCount'] ?? $rowCount);
      }
    }
    if ($file === false || !is_file($file) || !is_readable($file)) {
      return false;
    }
    return [
      'query' => $query,
      'file' => $file,
      'columns' => array_values($result['columns']),
      'rowCount' => $rowCount
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
    $markdownMaxLength = self::exportOptionalInteger($state['result-export-markdown-length']);
    if ($markdownMaxLength === false) {
      \SPTK\Elements\WarningPanel::forge('Invalid markdown cell length', 'Max cell length must be empty, zero, or a positive integer.');
      Element::refresh();
      return false;
    }
    $sqlGroupInsert = self::exportOptionalInteger($state['result-export-sql-group-insert']);
    if ($sqlGroupInsert === false) {
      \SPTK\Elements\WarningPanel::forge('Invalid SQL group size', 'Rows per insert must be empty, zero, or a positive integer.');
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
    $mongoResult = self::isMongoDocumentExportResult($result);
    if ($format === 'Mongo JSON' && !$mongoResult) {
      \SPTK\Elements\WarningPanel::forge('Not a MongoDB document result', 'Mongo JSON export is available only for MongoDB results with _id and _document columns.');
      Element::refresh();
      return false;
    }
    if ($format === 'SQL INSERT' && $mongoResult) {
      \SPTK\Elements\WarningPanel::forge('Unsupported export format', 'SQL INSERT export is not available for MongoDB document results.');
      Element::refresh();
      return false;
    }
    if ($format === 'Mongo JSON' && $source === 'Columns') {
      $bounds = [0, max(0, (int)$result['rowCount'] - 1), 0, count($result['columns']) - 1];
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
      'delimited' => self::resultExportDelimitedOptions($state, $format),
      'json' => self::resultExportJsonOptions($state),
      'mongo' => $format === 'Mongo JSON' ? self::resultExportMongoOptions($state, $result) : [],
      'markdown' => [
        'lineMode' => self::selectedMarkdownLineMode($state),
        'maxLength' => $markdownMaxLength
      ],
      'html' => [
        'document' => self::boolValue($state['result-export-html-document'] ?? false),
        'multiline' => self::boolValue($state['result-export-html-multiline'] ?? false),
        'title' => (string)($state['result-export-html-title'] ?? 'MADB result')
      ],
      'xml' => [
        'declaration' => self::boolValue($state['result-export-xml-declaration'] ?? false),
        'compact' => self::boolValue($state['result-export-xml-compact'] ?? false),
        'root' => self::xmlExportElementName($state['result-export-xml-root'] === '' ? 'result' : $state['result-export-xml-root']),
        'row' => self::xmlExportElementName($state['result-export-xml-row'] === '' ? 'row' : $state['result-export-xml-row']),
        'field' => $state['result-export-xml-field'] === '' ? '' : self::xmlExportElementName($state['result-export-xml-field'])
      ],
      'sqlTable' => self::resultExportSqlTableName($state, $result['query']),
      'sqlGroupInsert' => $sqlGroupInsert,
      'sqlAddInfo' => self::boolValue($state['result-export-sql-add-info'] ?? false),
      'confirmed' => false
    ];
  }

  /** Converts max rows panel text to an integer row cap or null for unlimited. */
  private static function exportMaxRows(string $value) {
    return self::exportOptionalInteger($value);
  }

  /** Converts an optional positive integer panel value to an integer cap or null. */
  private static function exportOptionalInteger(string $value) {
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
    $lineEnd = self::decodeDelimitedOption((string)($state['result-export-delimited-line-end'] ?? '\n'));
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

  /** Returns JSON encoding options for export. */
  private static function resultExportJsonOptions(array $state): array {
    $flags = 0;
    if (self::boolValue($state['result-export-json-unescaped-unicode'] ?? false)) {
      $flags |= JSON_UNESCAPED_UNICODE;
    }
    if (self::boolValue($state['result-export-json-unescaped-slashes'] ?? false)) {
      $flags |= JSON_UNESCAPED_SLASHES;
    }
    if (self::boolValue($state['result-export-json-pretty'] ?? false)) {
      $flags |= JSON_PRETTY_PRINT;
    }
    if (
      self::boolValue($state['result-export-json-unescaped-lineterm'] ?? false) &&
      defined('JSON_UNESCAPED_LINE_TERMINATORS')
    ) {
      $flags |= JSON_UNESCAPED_LINE_TERMINATORS;
    }
    return [
      'flags' => $flags,
      'pretty' => self::boolValue($state['result-export-json-pretty'] ?? false)
    ];
  }

  /** Returns MongoDB full-document JSON export options. */
  private static function resultExportMongoOptions(array $state, array $result): array {
    $tableContext = self::resultTableContextFromQuery($result['query'] ?? []);
    $schema = is_array($tableContext) ? ($tableContext['schema'] ?? '') : '';
    $table = is_array($tableContext) ? ($tableContext['table'] ?? '') : '';
    $flags = 0;
    if (self::boolValue($state['result-export-mongo-json-unescaped-unicode'] ?? true)) {
      $flags |= JSON_UNESCAPED_UNICODE;
    }
    if (self::boolValue($state['result-export-mongo-json-unescaped-slashes'] ?? true)) {
      $flags |= JSON_UNESCAPED_SLASHES;
    }
    if (self::boolValue($state['result-export-mongo-json-pretty'] ?? true)) {
      $flags |= JSON_PRETTY_PRINT;
    }
    return [
      'flags' => $flags,
      'pretty' => self::boolValue($state['result-export-mongo-json-pretty'] ?? true),
      'idColumn' => array_search('_id', $result['columns'], true),
      'schema' => $schema,
      'table' => $table,
      'connection' => self::$connectionName
    ];
  }

  /** Returns whether the active export result is a MongoDB document table. */
  private static function isMongoDocumentExportResult(array $result): bool {
    return self::$connectionName !== false
      && self::connectionEngineType(self::$connectionName) === 'MongoDB'
      && in_array('_id', $result['columns'] ?? [], true)
      && in_array('_document', $result['columns'] ?? [], true);
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

  /** Starts an incremental result export and opens its progress panel. */
  private static function startResultExportTask(array $request, $panel = null): void {
    self::abortPendingResultExport();
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
      $task = [
        'request' => $request,
        'handle' => $handle,
        'memory' => $memory,
        'headers' => self::selectedExportHeaders($request),
        'rows' => self::exportRows($request),
        'expectedRows' => max(1, self::estimatedExportRowCount($request)),
        'writtenRows' => 0,
        'jsonCount' => 0,
        'sqlPending' => []
      ];
      self::beginResultExportTask($task);
      self::$pendingResultExportTask = $task;
      if ($panel !== null && method_exists($panel, 'hide')) {
        $panel->hide();
      }
      self::showResultExportProgress();
    } catch (\Exception $e) {
      if (is_resource($handle)) {
        fclose($handle);
      }
      if ($request['target'] === 'File' && is_file($request['path'])) {
        unlink($request['path']);
      }
      \SPTK\Elements\ErrorPanel::forge('Could not export result', $e->getMessage());
    }
    Element::refresh();
  }

  /** Writes export preamble and format headers before row processing starts. */
  private static function beginResultExportTask(array &$task): void {
    $handle = $task['handle'];
    $request = $task['request'];
    if ($request['format'] === 'JSON' || $request['format'] === 'Mongo JSON') {
      $json = $request['format'] === 'Mongo JSON' ? $request['mongo'] : $request['json'];
      fwrite($handle, $json['pretty'] ? "[\n" : '[');
      return;
    }
    if ($request['format'] === 'SQL INSERT') {
      $task['sqlTable'] = self::quoteExportSqlName($request['sqlTable']);
      $task['sqlColumns'] = array_map(fn($header) => self::quoteExportSqlIdentifier($header), $task['headers']);
      if ($request['sqlAddInfo']) {
        self::writeSqlExportInfo($handle, $request);
      }
      return;
    }
    if ($request['format'] === 'HTML') {
      if ($request['html']['document']) {
        $title = htmlspecialchars($request['html']['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        fwrite($handle, "<!doctype html>\n<html>\n<head>\n<meta charset=\"UTF-8\">\n<title>{$title}</title>\n</head>\n<body>\n");
      }
      fwrite($handle, "<table>\n");
    } else if ($request['format'] === 'XML') {
      if ($request['xml']['declaration']) {
        fwrite($handle, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
      }
      fwrite($handle, '<' . $request['xml']['root'] . ">\n");
    }
    if ($request['includeHeaders']) {
      self::writeExportHeader($handle, $request, $task['headers']);
    } else if ($request['format'] === 'HTML') {
      fwrite($handle, "  <tbody>\n");
    }
  }

  /** Shows the export progress panel. */
  private static function showResultExportProgress(): void {
    self::removeResultExportPanelByName('result-export-progress');
    $window = \SPTK\Element::firstByType('Window');
    if ($window === false || !is_array(self::$pendingResultExportTask)) {
      return;
    }
    $panel = new \SPTK\Elements\Panel($window, 'result-export-progress');
    $title = new \SPTK\Element($panel, null, null, 'PanelTitle');
    $title->addText('Exporting result');
    $content = new \SPTK\Element($panel, null, null, 'PanelContent');
    $progress = new \SPTK\Elements\ProgressBar($content, 'result-export-progress-bar');
    $progress->setType('steps');
    $progress->setStepNumber(self::$pendingResultExportTask['expectedRows']);
    $progress->setValue(0);
    $progress->setLabel('Exported rows');
    $progress->setJobName(self::resultExportProgressText(self::$pendingResultExportTask));
    $buttons = new \SPTK\Element($content, null, null, 'ButtonBox');
    self::addResultExportPanelButton($buttons, 'ESCAPE', 'MADB\Result\ResultExportController::cancelResultExport', 'Cancel');
    $panel->show();
  }

  /** Advances the pending export without blocking the UI loop. */
  private static function processPendingResultExport(): void {
    if (!is_array(self::$pendingResultExportTask)) {
      return;
    }
    $task =& self::$pendingResultExportTask;
    try {
      $processed = 0;
      $deadline = microtime(true) + (self::RESULT_EXPORT_BATCH_MS / 1000);
      while ($processed < self::RESULT_EXPORT_BATCH_MAX_ROWS && $task['rows']->valid()) {
        self::writeResultExportTaskRow($task, $task['rows']->current());
        $task['writtenRows']++;
        $processed++;
        $task['rows']->next();
        if ($processed % 500 === 0 && microtime(true) >= $deadline) {
          break;
        }
      }
      self::syncResultExportProgress();
      if ($task['rows']->valid()) {
        \SPTK\Element::refresh();
        return;
      }
      self::finishPendingResultExport();
    } catch (\Exception $e) {
      self::abortPendingResultExport();
      \SPTK\Elements\ErrorPanel::forge('Could not export result', $e->getMessage());
      \SPTK\Element::refresh();
    }
  }

  /** Writes one row to the active export task. */
  private static function writeResultExportTaskRow(array &$task, array $row): void {
    $handle = $task['handle'];
    $request = $task['request'];
    if ($request['format'] === 'JSON' || $request['format'] === 'Mongo JSON') {
      if ($request['format'] === 'Mongo JSON') {
        $item = self::mongoDocumentExportItem($request, $row);
        $jsonOptions = $request['mongo'];
      } else {
        if ($request['includeHeaders']) {
          $item = [];
          foreach ($task['headers'] as $index => $header) {
            $item[$header] = $row[$index] ?? null;
          }
        } else {
          $item = $row;
        }
        $jsonOptions = $request['json'];
      }
      $json = json_encode($item, $jsonOptions['flags']);
      if ($json === false) {
        throw new \Exception('Could not encode JSON export.');
      }
      if ($jsonOptions['pretty']) {
        fwrite($handle, ($task['jsonCount'] > 0 ? ",\n" : '') . self::indentJsonExport($json));
      } else {
        fwrite($handle, ($task['jsonCount'] > 0 ? ',' : '') . $json);
      }
      $task['jsonCount']++;
      return;
    }
    if ($request['format'] === 'SQL INSERT') {
      $values = array_map(fn($value) => self::quoteExportSqlValue($value), $row);
      if ($request['sqlGroupInsert'] === null) {
        fwrite($handle, 'INSERT INTO ' . $task['sqlTable'] . ' (' . implode(', ', $task['sqlColumns']) . ') VALUES (' . implode(', ', $values) . ");\n");
      } else {
        $task['sqlPending'][] = '(' . implode(', ', $values) . ')';
        if (count($task['sqlPending']) >= $request['sqlGroupInsert']) {
          self::writeGroupedSqlInsert($handle, $task['sqlTable'], $task['sqlColumns'], $task['sqlPending']);
          $task['sqlPending'] = [];
        }
      }
      return;
    }
    self::writeExportRow($handle, $request, $task['headers'], $row);
  }

  /** Updates the export progress panel. */
  private static function syncResultExportProgress(): void {
    if (!is_array(self::$pendingResultExportTask)) {
      return;
    }
    $progress = \SPTK\Element::byName('result-export-progress-bar');
    if ($progress === false || !method_exists($progress, 'setValue')) {
      return;
    }
    $progress->setValue((int)self::$pendingResultExportTask['writtenRows']);
    if (method_exists($progress, 'setJobName')) {
      $progress->setJobName(self::resultExportProgressText(self::$pendingResultExportTask));
    }
  }

  /** Returns progress text for the export panel. */
  private static function resultExportProgressText(array $task): string {
    return $task['request']['format'] . ' to ' . $task['request']['target'];
  }

  /** Finishes a completed export and reports success. */
  private static function finishPendingResultExport(): void {
    if (!is_array(self::$pendingResultExportTask)) {
      return;
    }
    $task = self::$pendingResultExportTask;
    self::$pendingResultExportTask = false;
    try {
      self::finishResultExportTask($task);
      if ($task['request']['target'] === 'Clipboard') {
        rewind($task['memory']);
        \SPTK\Clipboard::set(stream_get_contents($task['memory']));
        \SPTK\Elements\Panel::forge('Result exported', (int)$task['writtenRows'] . ' row(s) copied to clipboard.', [
          ['text' => 'OK', 'hotKey' => 'RETURN', 'onPress' => 'close']
        ]);
      } else {
        \SPTK\Elements\Panel::forge('Result exported', (int)$task['writtenRows'] . " row(s) saved to:\n" . $task['request']['path'], [
          ['text' => 'OK', 'hotKey' => 'RETURN', 'onPress' => 'close']
        ]);
      }
    } catch (\Exception $e) {
      if ($task['request']['target'] === 'File' && is_file($task['request']['path'])) {
        unlink($task['request']['path']);
      }
      \SPTK\Elements\ErrorPanel::forge('Could not export result', $e->getMessage());
    } finally {
      if (is_resource($task['handle'])) {
        fclose($task['handle']);
      }
      self::removeResultExportPanelByName('result-export-progress');
      \SPTK\Element::refresh();
    }
  }

  /** Writes format trailer data for an export task. */
  private static function finishResultExportTask(array &$task): void {
    $handle = $task['handle'];
    $request = $task['request'];
    if ($request['format'] === 'JSON' || $request['format'] === 'Mongo JSON') {
      $json = $request['format'] === 'Mongo JSON' ? $request['mongo'] : $request['json'];
      if ($json['pretty']) {
        fwrite($handle, ($task['jsonCount'] > 0 ? "\n" : '') . "]\n");
      } else {
        fwrite($handle, "]\n");
      }
      return;
    }
    if ($request['format'] === 'SQL INSERT') {
      if (!empty($task['sqlPending'])) {
        self::writeGroupedSqlInsert($handle, $task['sqlTable'], $task['sqlColumns'], $task['sqlPending']);
      }
      return;
    }
    if ($request['format'] === 'HTML') {
      fwrite($handle, "  </tbody>\n</table>\n");
      if ($request['html']['document']) {
        fwrite($handle, "</body>\n</html>\n");
      }
    } else if ($request['format'] === 'XML') {
      fwrite($handle, '</' . $request['xml']['root'] . ">\n");
    }
  }

  /** Cancels an active export task and removes partial output. */
  public static function cancelResultExport($panel = null): void {
    if ($panel !== null && method_exists($panel, 'remove')) {
      $panel->remove();
    }
    self::abortPendingResultExport();
    \SPTK\Element::refresh();
  }

  /** Aborts the active export task. */
  private static function abortPendingResultExport(): void {
    if (!is_array(self::$pendingResultExportTask)) {
      return;
    }
    $task = self::$pendingResultExportTask;
    self::$pendingResultExportTask = false;
    if (is_resource($task['handle'])) {
      fclose($task['handle']);
    }
    if (($task['request']['target'] ?? '') === 'File' && is_file($task['request']['path'] ?? '')) {
      unlink($task['request']['path']);
    }
    self::removeResultExportPanelByName('result-export-progress');
  }

  /** Cleans up active export state before application exit. */
  public static function cleanupResultExports(): void {
    self::abortPendingResultExport();
  }

  /** Adds a button to a dynamic result-export panel. */
  private static function addResultExportPanelButton($parent, string $hotKey, string $callback, string $text, string $name = null): void {
    $button = new \SPTK\Elements\Button($parent, $name);
    $button->setHotKey($hotKey);
    $button->setOnPress($callback);
    $button->addText($text);
  }

  /** Removes a dynamic result-export panel by name. */
  private static function removeResultExportPanelByName(string $name): void {
    $panel = \SPTK\Element::byName($name);
    if ($panel !== false) {
      $panel->remove();
    }
  }

  /** Returns whether clipboard export should ask for confirmation first. */
  private static function shouldWarnBeforeClipboardExport(array $request): bool {
    $fileSize = filesize($request['result']['file']) ?: 0;
    return $fileSize > self::CLIPBOARD_EXPORT_WARNING_BYTES && $request['maxRows'] === null;
  }

  /** Writes one grouped SQL INSERT statement. */
  private static function writeGroupedSqlInsert($handle, string $table, array $columns, array $valueRows): void {
    fwrite($handle, 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ") VALUES\n  ");
    fwrite($handle, implode(",\n  ", $valueRows) . ";\n");
  }

  /** Writes a SQL export metadata block. */
  private static function writeSqlExportInfo($handle, array $request): void {
    $query = $request['result']['query'] ?? [];
    $headers = self::selectedExportHeaders($request);
    $lines = [
      'MADB SQL INSERT export',
      'Exported at: ' . date('Y-m-d H:i:s'),
      'Query: ' . (string)($query['name'] ?? 'NEW'),
      'Source: ' . $request['source'],
      'Target table: ' . self::quoteExportSqlName($request['sqlTable']),
      'Exported rows: ' . self::estimatedExportRowCount($request),
      'Exported columns: ' . count($headers) . ' (' . implode(', ', $headers) . ')'
    ];
    if (($request['maxRows'] ?? null) !== null) {
      $lines[] = 'Max rows: ' . $request['maxRows'];
    }
    $sql = trim((string)($query['text'] ?? ''));
    if ($sql !== '') {
      $lines[] = 'Source SQL:';
      foreach (preg_split('/\R/', $sql) as $line) {
        $lines[] = '  ' . $line;
      }
    }
    fwrite($handle, "/*\n");
    foreach ($lines as $line) {
      fwrite($handle, ' * ' . str_replace('*/', '* /', $line) . "\n");
    }
    fwrite($handle, " */\n\n");
  }

  /** Estimates the number of rows the current export request will write. */
  private static function estimatedExportRowCount(array $request): int {
    [$row1, $row2] = $request['bounds'];
    $count = $row2 >= $row1 ? $row2 - $row1 + 1 : 0;
    if ($request['maxRows'] !== null) {
      $count = min($count, (int)$request['maxRows']);
    }
    return max(0, $count);
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
        fwrite($handle, self::markdownExportRow(array_map(fn($value) => self::markdownExportCell($value === null ? $request['nullText'] : $value, $request['markdown']), $row)) . "\n");
        break;
      case 'HTML':
        fwrite($handle, "    <tr>");
        foreach ($row as $value) {
          fwrite($handle, '<td>' . self::htmlExportCell($value === null ? $request['nullText'] : (string)$value, $request['html']) . '</td>');
        }
        fwrite($handle, "</tr>\n");
        break;
      case 'XML':
        $rowName = $request['xml']['row'];
        if ($request['xml']['compact']) {
          fwrite($handle, '  <' . $rowName . '>');
        } else {
          fwrite($handle, '  <' . $rowName . ">\n");
        }
        foreach ($headers as $index => $header) {
          $name = $request['xml']['field'] === '' ? self::xmlExportElementName($header) : $request['xml']['field'];
          $text = $row[$index] === null ? $request['nullText'] : (string)$row[$index];
          fwrite($handle, $request['xml']['compact'] ? '<' . $name . ' column="' : '    <' . $name . ' column="');
          fwrite($handle, htmlspecialchars($header, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">');
          fwrite($handle, htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
          fwrite($handle, '</' . $name . ($request['xml']['compact'] ? '>' : ">\n"));
        }
        fwrite($handle, $request['xml']['compact'] ? '</' . $rowName . ">\n" : '  </' . $rowName . ">\n");
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
          yield $request['format'] === 'Mongo JSON' ? $row : array_slice($row, $col1, $col2 - $col1 + 1);
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

  /** Returns a full MongoDB document export object for a flattened result row. */
  private static function mongoDocumentExportItem(array $request, array $row): array {
    static $connections = [];
    $idColumn = $request['mongo']['idColumn'] ?? false;
    $id = $idColumn === false ? null : ($row[(int)$idColumn] ?? null);
    if ($id === null || $id === '') {
      return [
        '_id' => $id,
        '_error' => 'Missing MongoDB _id in export row.'
      ];
    }
    $connection = \MADB\Connection\ConnectionList::getInstance()->get($request['mongo']['connection']);
    if ($connection === false) {
      return [
        '_id' => $id,
        '_error' => 'MongoDB connection is not available.'
      ];
    }
    try {
      $cacheKey = (string)$request['mongo']['connection'];
      if (!isset($connections[$cacheKey])) {
        $className = \MADB\Engine\EngineRegistry::connectionClass('MongoDB');
        $connections[$cacheKey] = new $className($connection);
      }
      $mongo = $connections[$cacheKey];
      $document = $mongo->findDocumentById($request['mongo']['schema'], $request['mongo']['table'], (string)$id);
      if ($document === false) {
        return [
          '_id' => $id,
          '_error' => 'MongoDB document was not found.'
        ];
      }
      $decoded = json_decode($document, true);
      if (is_array($decoded)) {
        return $decoded;
      }
      return [
        '_id' => $id,
        '_error' => 'MongoDB document could not be decoded.'
      ];
    } catch (\Throwable $e) {
      return [
        '_id' => $id,
        '_error' => $e->getMessage()
      ];
    }
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
      $escaped[] = str_replace(["\\", "|"], ["\\\\", "\\|"], (string)$value);
    }
    return '| ' . implode(' | ', $escaped) . ' |';
  }

  /** Formats one markdown cell according to export options. */
  private static function markdownExportCell($value, array $options): string {
    $text = (string)$value;
    $text = match ($options['lineMode']) {
      'BR tag' => preg_replace('/\R/', '<br>', $text),
      'Literal' => str_replace(["\r\n", "\r", "\n"], ['\n', '\n', '\n'], $text),
      default => preg_replace('/\R+/', ' ', $text),
    };
    if ($options['maxLength'] !== null && strlen($text) > $options['maxLength']) {
      $text = $options['maxLength'] > 1
        ? substr($text, 0, $options['maxLength'] - 1) . '~'
        : substr($text, 0, $options['maxLength']);
    }
    return $text;
  }

  /** Formats one HTML cell according to export options. */
  private static function htmlExportCell(string $value, array $options): string {
    if ($options['multiline']) {
      $parts = preg_split('/\R/', $value);
      $escaped = array_map(fn($part) => htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $parts);
      return implode('<br>', $escaped);
    }
    return htmlspecialchars(preg_replace('/\R+/', ' ', $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

  /** Returns the requested SQL export table, including optional schema. */
  private static function resultExportSqlTableName(array $state, $query): string {
    $schema = trim((string)($state['result-export-sql-schema'] ?? ''));
    $table = trim((string)($state['result-export-sql-table'] ?? ''));
    if ($table === '') {
      $table = self::defaultExportSqlTable($query);
    }
    if ($schema === '') {
      return $table;
    }
    return $schema . '.' . $table;
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
    $table = self::currentSecondary(is_array($query) ? $query : []);
    return $table === '' ? 'exported_result' : $table;
  }

  /** Returns default export path for a format. */
  private static function defaultExportPath(string $format): string {
    return rtrim(\MADB\App\Settings::defaultExportDirectory(), '/') . '/madb-result.' . self::exportExtensionForFormat($format);
  }

  /** Returns an export path with the extension that matches the selected format. */
  private static function exportPathForFormat(string $path, string $format): string {
    $extension = self::exportExtensionForFormat($format);
    return self::exportPathWithExtension($path, $extension, $format);
  }

  /** Returns an export path with the extension that matches the selected CSV/TSV preset. */
  private static function exportPathForDelimitedPreset(string $path, string $preset): string {
    $extension = $preset === 'TSV' ? 'tsv' : 'csv';
    return self::exportPathWithExtension($path, $extension, 'CSV/TSV');
  }

  /** Returns an export path with a requested extension. */
  private static function exportPathWithExtension(string $path, string $extension, string $defaultFormat): string {
    $path = trim($path);
    if ($path === '' || is_dir($path)) {
      return self::defaultExportPath($defaultFormat);
    }
    $dir = dirname($path);
    $filename = basename($path);
    $dot = strrpos($filename, '.');
    $base = $dot === false ? $filename : substr($filename, 0, $dot);
    if ($base === '') {
      $base = 'madb-result';
    }
    return ($dir === '.' ? '' : $dir . '/') . $base . '.' . $extension;
  }

  /** Returns the default file extension for a format. */
  private static function exportExtensionForFormat(string $format): string {
    $extension = match ($format) {
      'Markdown' => 'md',
      'HTML' => 'html',
      'XML' => 'xml',
      'JSON', 'Mongo JSON' => 'json',
      'SQL INSERT' => 'sql',
      default => 'csv',
    };
    return $extension;
  }

}
