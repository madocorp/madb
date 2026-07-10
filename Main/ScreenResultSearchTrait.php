<?php

namespace MADB\Main;

use \SPTK\Element;

/** Coordinates the result table search panel and SPTK table search state. */
trait ScreenResultSearchTrait {

  /** Opens the dedicated result table search panel. */
  public static function searchResult() {
    if (!self::resultTableSearchAvailable()) {
      \SPTK\Elements\WarningPanel::forge('No result table', 'Please execute a query with a table result before searching results.');
      return;
    }
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
      'caseSensitive' => $caseSensitive
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
      !empty($state['caseSensitive']) === self::boolValue($options['caseSensitive'] ?? false);
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
      'result-search-regexp' => self::boolValue($values['result-search-regexp'] ?? false),
      'result-search-case-sensitive' => self::boolValue($values['result-search-case-sensitive'] ?? false),
      'result-search-scope-all' => $scope === 'all',
      'result-search-scope-next' => $scope === 'next',
      'result-search-scope-previous' => $scope === 'previous'
    ];
  }

}
