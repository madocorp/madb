<?php

namespace MADB\Query;

use \SPTK\SDLWrapper\KeyCombo;
use \SPTK\SDLWrapper\Action;
use \SPTK\SDLWrapper\KeyCode;
use \SPTK\SDLWrapper\KeyModifier;
use \SPTK\SDLWrapper\ScanCode;
use \SPTK\SDLWrapper\SDL;
use \SPTK\Element;
use \MADB\List\QueryList;
use \MADB\Result\ResultStore;
use \MADB\Query\SqlSplitter;

/** Coordinates the query editor search panel, including find, replace, session navigation, and highlight updates. */
trait ScreenSearchActionsTrait {

  /** Coordinates search query work in the query workspace. */
  public static function searchQuery() {
    if (self::$connectionName === false) {
      \SPTK\Elements\WarningPanel::forge('No connection selected!', 'Please select a connection before searching a query.');
      return;
    }
    $query = self::$queryList->getActive(self::$connectionName);
    if ($query === false) {
      \SPTK\Elements\WarningPanel::forge('No query selected!', 'Please select a query before searching it.');
      return;
    }
    self::$searchPanel->setValue(self::$searchPanelState);
    self::syncSearchPanel();
    self::$searchPanel->show();
    if (method_exists(self::$searchPanel, 'activateInput')) {
      self::$searchPanel->activateInput('search');
    }
    Element::refresh();
  }

  /** Synchronizes search panel state inside the query workspace. */
  public static function syncSearchPanel($element = null) {
    $panel = self::$searchPanel;
    if ($panel === null) {
      return;
    }
    $replaceEnabled = false;
    $replaceCheckbox = Element::byName('replaceEnabled', $panel);
    if ($replaceCheckbox !== false) {
      $replaceEnabled = self::boolValue($replaceCheckbox->getValue());
    }
    $replaceRow = Element::byName('query-search-replace-row', $panel);
    if ($replaceRow !== false) {
      if ($replaceEnabled) {
        $replaceRow->show();
      } else {
        $replaceRow->hide();
      }
    }
    if ($panel->isDisplayed() && method_exists($panel, 'refreshInputList')) {
      $panel->refreshInputList($element);
    }
    Element::refresh();
  }

  /** Coordinates do search query work in the query workspace. */
  public static function doSearchQuery($panel) {
    $values = $panel->getValue();
    self::$searchPanelState = self::normalizeSearchPanelState($values);
    $search = (string) ($values['search'] ?? '');
    $replaceEnabled = self::boolValue($values['replaceEnabled'] ?? false);
    $replace = (string) ($values['replace'] ?? '');
    $regexp = self::boolValue($values['regexp'] ?? false);
    $caseSensitive = self::boolValue($values['caseSensitive'] ?? false);
    $scope = self::selectedSearchScope($values);
    if ($search === '') {
      \SPTK\Elements\WarningPanel::forge('Missing search text', 'Please enter text to search for.');
      return;
    }
    $pattern = self::searchPattern($search, $regexp, $caseSensitive);
    if ($regexp && ($pattern === false || !self::validPattern($pattern))) {
      \SPTK\Elements\WarningPanel::forge('Invalid regexp', 'Please enter a valid regular expression.');
      return;
    }
    if ($replaceEnabled) {
      self::replaceInQuery($panel, $search, $replace, $regexp, $caseSensitive, $scope);
    } else {
      self::findInQuery($panel, $search, $regexp, $caseSensitive, $scope);
    }
  }

  /** Finds in query data inside the query workspace. */
  private static function findInQuery($panel, $search, $regexp, $caseSensitive, $scope): void {
    $text = self::editorText();
    $state = self::captureEditorState();
    $matches = self::scopedMatches($text, self::allQueryMatches($text, $search, $regexp, $caseSensitive), $scope, $state);
    [$match, $index] = self::pickMatch($text, $matches, $scope, $state);
    if ($match === false) {
      self::clearSearchSession();
      \SPTK\Elements\WarningPanel::forge('Not found', 'No match was found in the current query.');
      return;
    }
    [$matchOffset, $matchLength] = $match;
    self::restoreEditorState(self::cursorStateForMatch($text, $matchOffset, $matchLength, $state));
    if ($scope === 'next' || $scope === 'previous') {
      self::clearSearchSession();
      $panel->hide();
      self::deactivateList();
      self::deactivateResult();
      self::activateEditor();
      Element::refresh();
      return;
    }
    self::$searchSession = [
      'search' => $search,
      'regexp' => $regexp,
      'caseSensitive' => $caseSensitive,
      'scope' => $scope,
      'matches' => $matches,
      'index' => $index,
      'text' => $text
    ];
    self::applySearchHighlights($text, self::searchHighlightMatches($matches, $scope, $index));
    $panel->hide();
    self::deactivateList();
    self::deactivateResult();
    self::activateEditor();
    Element::refresh();
  }

  /** Coordinates replace in query work in the query workspace. */
  private static function replaceInQuery($panel, $search, $replace, $regexp, $caseSensitive, $scope): void {
    $query = self::$connectionName === false ? false : self::$queryList->getActive(self::$connectionName);
    if ($query !== false && !self::canEditQueryText($query)) {
      \SPTK\Elements\WarningPanel::forge('Query is locked', 'This query has already started running and cannot be modified.');
      return;
    }
    $text = self::editorText();
    $state = self::captureEditorState();
    $matches = self::scopedMatches($text, self::allQueryMatches($text, $search, $regexp, $caseSensitive), $scope, $state);
    if ($scope === 'next' || $scope === 'previous') {
      [$match, $index] = self::pickMatch($text, $matches, $scope, $state);
      if ($match === false) {
        self::clearSearchSession();
        \SPTK\Elements\WarningPanel::forge('Not found', 'No match was found in the current query.');
        return;
      }
      [$matchOffset, $matchLength, $matchText] = $match;
      $replacement = $regexp ? preg_replace(self::searchPattern($search, true, $caseSensitive), $replace, $matchText, 1) : $replace;
      self::restoreEditorState(self::cursorStateForMatch($text, $matchOffset, $matchLength, $state));
      self::$editor->insertText($replacement);
      self::saveCurrentEditor();
      self::clearSearchSession();
      $panel->hide();
      self::deactivateList();
      self::deactivateResult();
      self::activateEditor();
      Element::refresh();
      return;
    }
    if (empty($matches)) {
      self::clearSearchSession();
      \SPTK\Elements\WarningPanel::forge('Not found', 'No match was found in the current query.');
      return;
    }
    $newText = $text;
    for ($i = count($matches) - 1; $i >= 0; $i--) {
      [$matchOffset, $matchLength, $matchText] = $matches[$i];
      $replacement = $regexp ? preg_replace(self::searchPattern($search, true, $caseSensitive), $replace, $matchText, 1) : $replace;
      $newText = substr($newText, 0, $matchOffset) . $replacement . substr($newText, $matchOffset + $matchLength);
    }
    self::replaceEditorText($newText);
    self::saveCurrentEditor();
    self::startSearchSession($search, $regexp, $caseSensitive, $scope);
    $panel->hide();
    self::deactivateList();
    self::deactivateResult();
    self::activateEditor();
    Element::refresh();
  }

  /** Coordinates start search session work in the query workspace. */
  private static function startSearchSession($search, $regexp, $caseSensitive, $scope): void {
    $text = self::editorText();
    $state = self::captureEditorState();
    $matches = self::scopedMatches($text, self::allQueryMatches($text, $search, $regexp, $caseSensitive), $scope, $state);
    [$match, $index] = self::pickMatch($text, $matches, $scope, $state);
    if ($match === false) {
      self::clearSearchSession();
      return;
    }
    self::$searchSession = [
      'search' => $search,
      'regexp' => $regexp,
      'caseSensitive' => $caseSensitive,
      'scope' => $scope,
      'matches' => $matches,
      'index' => $index,
      'text' => $text
    ];
    self::applySearchHighlights($text, self::searchHighlightMatches($matches, $scope, $index));
  }

  /** Coordinates navigate search session work in the query workspace. */
  private static function navigateSearchSession($delta): bool {
    if (self::$searchSession === false) {
      return false;
    }
    $text = self::editorText();
    if ((self::$searchSession['text'] ?? null) !== $text) {
      self::clearSearchSession();
      Element::refresh();
      return true;
    }
    $matches = self::$searchSession['matches'];
    if (empty($matches)) {
      self::clearSearchSession();
      Element::refresh();
      return true;
    }
    self::$searchSession['matches'] = $matches;
    $index = (int) (self::$searchSession['index'] ?? 0);
    $index = ($index + $delta) % count($matches);
    if ($index < 0) {
      $index = count($matches) - 1;
    }
    self::$searchSession['index'] = $index;
    $match = $matches[$index];
    self::restoreEditorState(self::cursorStateForMatch($text, $match[0], $match[1], self::captureEditorState()));
    self::applySearchHighlights($text, self::searchHighlightMatches($matches, self::$searchSession['scope'], $index));
    self::deactivateList();
    self::deactivateResult();
    self::activateEditor();
    Element::refresh();
    return true;
  }

}
