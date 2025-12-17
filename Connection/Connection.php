<?php

namespace MADB\Connection;

class Connection {

  private $fields = [
    'name' => 'new',
    'host' => '',
    'port' => '3306',
    'schema' => '',
    'timeout' => '3600',
    'initCommand' => '',
    'username' => '',
    'password' => '',
    'sslKey' => '',
    'sslCert' => '',
    'sslCA' => '',
    'sslCipher' => ''
  ];
  public $data;

  public function __construct($data) {
    $this->data = [];
    foreach ($this->fields as $key => $default) {
      if (isset($data[$key])) {
        $this->data[$key] = $data[$key];
      } else {
        $this->data[$key] = $default;
      }
    }
  }

}
