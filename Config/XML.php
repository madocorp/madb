<?php

namespace MADB\Config;

use \XmlReader;

class XML {

  public $file;

  public function __construct($file) {
    if (empty($file)) {
      throw new \Exception("XML file name is required");
    }
    $this->file = $file;
  }

  public function load() {
    $reader = new XmlReader();
    $reader->open($this->file);
    $stack = [];
    $root = [];
    while ($reader->read()) {
      switch ($reader->nodeType) {
        case XMLReader::ELEMENT:
          // Start a new element
          $name = $reader->name;
          $node = [];
          // Push current node on stack
          array_push($stack, [$name, $node]);
          break;
        case XMLReader::TEXT:
        case XMLReader::CDATA:
          // Add text to the current node
          $stack[count($stack) - 1][1] = $reader->value;
          break;
        case XMLReader::END_ELEMENT:
          // When ending a tag, pop it from stack and insert into its parent
          list($name, $node) = array_pop($stack);
          if (empty($stack)) {
            // Finished parsing
            $root[$name] = $node;
          } else {
            $parentIndex = count($stack) - 1;
            // Ensure parent key exists
            if (!isset($stack[$parentIndex][1][$name])) {
              $stack[$parentIndex][1][$name] = $node;
            } else {
              // It already exists → convert to array of items
              if (!is_array($stack[$parentIndex][1][$name]) || !isset($stack[$parentIndex][1][$name][0])) {
                $stack[$parentIndex][1][$name] = [$stack[$parentIndex][1][$name]];
              }
              $stack[$parentIndex][1][$name][] = $node;
            }
          }
          break;
      }
    }
    return $root;
  }

  public function save($data, $rootName) {
    $dom = new DOMDocument("1.0", "UTF-8");
    $dom->formatOutput = true;
    $root = $dom->createElement($rootName);
    $dom->appendChild($root);
    $this->buildXml($dom, $root, $data);
    return $dom->saveXML();
  }

  private function buildXml($dom, $parent, $data) {
    if (!is_array($data)) {
      // simple text node
      $parent->appendChild($dom->createTextNode($data));
      return;
    }
    foreach ($data as $key => $value) {
      // Handle numeric arrays → repeat the tag name
      if (is_numeric($key)) {
        $key = $parent->nodeName;  // repeat parent tag
      }
      if (is_array($value)) {
        $child = $dom->createElement($key);
        $parent->appendChild($child);
        $this->buildXml($dom, $child, $value);
      } else {
        $child = $dom->createElement($key);
        $child->appendChild($dom->createTextNode($value));
        $parent->appendChild($child);
      }
    }
  }

}

