<?php

namespace MADB\Result;

use \SPTK\Element;

/** Coordinates the result table search panel and SPTK table search state. */
trait ScreenResultSearchTrait {

  /** Opens the dedicated result table search panel. */
  public static function searchResult() {
    if (!self::resultTableSearchAvailable()) {
      \SPTK\Elements\WarningPanel::forge('No result table', 'Please execute a query with a table result before searching results.');
      return;
    }
    self::syncResultSearchFields();
    self::$resultSearchPanel->setValue(self::$resultSearchPanelState);
    self::$resultSearchPanel->show();
    if (method_exists(self::$resultSearchPanel, 'activateInput')) {
      self::$resultSearchPanel->activateInput('result-search-text');
    }
    Element::refresh();
  }

  /** Applies result search panel values to the result table. */
  public static function doResultSearch($panel) {
    $values = $panel->getValue();
    self::$resultSearchPanelState = self::normalizeResultSearchPanelState($values);
    $search = (string)($values['result-search-text'] ?? '');
    $regexp = self::boolValue($values['result-search-regexp'] ?? false);
    $caseSensitive = self::boolValue($values['result-search-case-sensitive'] ?? false);
    $fields = self::resultSearchFields($values);
    $scope = self::selectedResultSearchScope($values);
    if ($search === '') {
      \SPTK\Elements\WarningPanel::forge('Missing search text', 'Please enter text to search for.');
      return;
    }
    if (!self::resultTableSearchAvailable()) {
      \SPTK\Elements\WarningPanel::forge('No result table', 'Please execute a query with a table result before searching results.');
      return;
    }
    if ($regexp) {
      $pattern = self::searchPattern($search, true, $caseSensitive);
      if ($pattern === false || !self::validPattern($pattern)) {
        \SPTK\Elements\WarningPanel::forge('Invalid regexp', 'Please enter a valid regular expression.');
        return;
      }
    }

    $options = [
      'regexp' => $regexp,
      'caseSensitive' => $caseSensitive,
      'columns' => $fields
    ];
    $match = self::runResultSearch($search, $options, $scope);
    if ($match === false) {
      self::$resultSearchSession = false;
      \SPTK\Elements\WarningPanel::forge('Not found', 'No match was found in the current result.');
      return;
    }
    self::$resultSearchSession = [
      'search' => $search,
      'regexp' => $regexp,
      'caseSensitive' => $caseSensitive,
      'fields' => $fields,
      'scope' => $scope
    ];
    $panel->hide();
    self::deactivateEditor();
    self::deactivateList();
    self::activateResult();
    Element::refresh();
  }

  /** Closes the result search panel and clears result search navigation state. */
  public static function closeResultSearchPanel($panel = null) {
    if ($panel !== null) {
      self::$resultSearchPanelState = self::normalizeResultSearchPanelState($panel->getValue());
      $panel->hide();
    } else if (self::$resultSearchPanel !== null) {
      $panel = self::$resultSearchPanel;
      if ($panel->isDisplayed()) {
        self::$resultSearchPanelState = self::normalizeResultSearchPanelState($panel->getValue());
      }
      $panel->hide();
    }
    self::clearResultSearchSession();
    Element::refresh();
  }

  /** Navigates the current result table search from keyboard shortcuts. */
  private static function navigateResultSearchSession($delta): bool {
    if (self::$resultSearchSession === false || !self::resultTableSearchAvailable()) {
      return false;
    }
    $match = $delta < 0 ? self::$resultTable->previousMatch() : self::$resultTable->nextMatch();
    if ($match === false) {
      self::clearResultSearchSession();
      Element::refresh();
      return true;
    }
    self::deactivateEditor();
    self::deactivateList();
    self::activateResult();
    Element::refresh();
    return true;
  }

  /** Clears result table search state. */
  private static function clearResultSearchSession(): void {
    self::$resultSearchSession = false;
    if (self::$resultTable !== null && method_exists(self::$resultTable, 'clearSearch')) {
      self::$resultTable->clearSearch();
    }
  }

  /** Checks whether result table search can run. */
  private static function resultTableSearchAvailable(): bool {
    return self::$resultTable !== null &&
      self::$resultTable->isDisplayed() &&
      method_exists(self::$resultTable, 'search') &&
      method_exists(self::$resultTable, 'nextMatch') &&
      method_exists(self::$resultTable, 'previousMatch');
  }

  /** Runs the selected result search scope. */
  private static function runResultSearch(string $search, array $options, string $scope) {
    if ($scope === 'previous') {
      if (!self::resultSearchStateMatches($search, $options)) {
        $match = self::$resultTable->search($search, $options);
        return $match === false ? false : self::$resultTable->previousMatch();
      }
      return self::$resultTable->previousMatch();
    }
    if ($scope === 'next') {
      if (!self::resultSearchStateMatches($search, $options)) {
        return self::$resultTable->search($search, $options);
      }
      return self::$resultTable->nextMatch();
    }
    return self::$resultTable->search($search, $options);
  }

  /** Returns whether current table search state matches panel options. */
  private static function resultSearchStateMatches(string $search, array $options): bool {
    if (!method_exists(self::$resultTable, 'getSearchState')) {
      return false;
    }
    $state = self::$resultTable->getSearchState();
    return !empty($state) &&
      ($state['text'] ?? '') === $search &&
      !empty($state['regexp']) === self::boolValue($options['regexp'] ?? false) &&
      !empty($state['caseSensitive']) === self::boolValue($options['caseSensitive'] ?? false) &&
      ($state['columns'] ?? []) === self::$resultTable->searchColumns($options['columns'] ?? null);
  }

  /** Selects result search scope from panel values. */
  private static function selectedResultSearchScope($values): string {
    if (self::boolValue($values['result-search-scope-all'] ?? false)) {
      return 'all';
    }
    if (self::boolValue($values['result-search-scope-previous'] ?? false)) {
      return 'previous';
    }
    return 'next';
  }

  /** Normalizes result search panel state. */
  private static function normalizeResultSearchPanelState($values): array {
    $scope = self::selectedResultSearchScope($values);
    return [
      'result-search-text' => (string)($values['result-search-text'] ?? ''),
      'result-search-fields' => self::resultSearchFieldsValue($values),
      'result-search-header' => self::resultSearchHeaderKey(),
      'result-search-regexp' => self::boolValue($values['result-search-regexp'] ?? false),
      'result-search-case-sensitive' => self::boolValue($values['result-search-case-sensitive'] ?? false),
      'result-search-scope-all' => $scope === 'all',
      'result-search-scope-next' => $scope === 'next',
      'result-search-scope-previous' => $scope === 'previous'
    ];
  }

  /** Applies current result table headers to the target fields select. */
  private static function syncResultSearchFields(): void {
    $headers = self::resultSearchHeaders();
    $headerKey = self::resultSearchHeaderKey($headers);
    $element = Element::byName('result-search-fields', self::$resultSearchPanel);
    if ($element === false) {
      return;
    }
    $element->setOptions($headers);
    $selected = self::$resultSearchPanelState['result-search-fields'] ?? false;
    $sameHeader = (self::$resultSearchPanelState['result-search-header'] ?? false) === $headerKey;
    $selectedFields = ($selected === false || !$sameHeader) ? $headers : self::fieldListFromValue($selected);
    $selectedFields = array_values(array_intersect($headers, $selectedFields));
    if (($selected !== false && $selected !== '' && empty($selectedFields)) || $selected === false) {
      $selectedFields = $headers;
    }
    self::$resultSearchPanelState['result-search-fields'] = implode(', ', $selectedFields);
    self::$resultSearchPanelState['result-search-header'] = $headerKey;
    $element->setValue(self::$resultSearchPanelState['result-search-fields']);
  }

  /** Returns result table headers usable as search target fields. */
  private static function resultSearchHeaders(): array {
    if (self::$resultTable === null || !method_exists(self::$resultTable, 'getHeader')) {
      return [];
    }
    return array_values(array_filter(
      array_map('strval', self::$resultTable->getHeader()),
      fn($header) => $header !== ''
    ));
  }

  /** Returns a stable key for the current result search fields. */
  private static function resultSearchHeaderKey($headers = null): string {
    if ($headers === null) {
      $headers = self::resultSearchHeaders();
    }
    return implode("\t", $headers);
  }

  /** Extracts selected search target fields from panel values. */
  private static function resultSearchFields($values): array {
    return array_values(array_intersect(
      self::resultSearchHeaders(),
      self::fieldListFromValue(self::resultSearchFieldsValue($values))
    ));
  }

  /** Returns the raw comma separated result-search fields panel value. */
  private static function resultSearchFieldsValue($values): string {
    return (string)($values['result-search-fields'] ?? '');
  }

  /** Parses a comma separated field list value. */
  private static function fieldListFromValue($value): array {
    return array_values(array_filter(
      array_map('trim', explode(',', (string)$value)),
      fn($field) => $field !== ''
    ));
  }

}
