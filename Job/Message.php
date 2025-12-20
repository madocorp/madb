<?php

namespace MADB\Job;

class Message {

  public static function send($socket, $data) {
    $json = json_encode($data, JSON_THROW_ON_ERROR);
    $len = strlen($json);
    fwrite($socket, pack('N', $len)); // 4-byte length
    fwrite($socket, $json);
  }

  public static function receive($socket) {
    $header = fread($socket, 4);
    if ($header === '' || $header === false) {
      return null; // socket closed
    }
    $len = unpack('N', $header)[1];
    $json = '';
    while (strlen($json) < $len) {
      $chunk = fread($socket, $len - strlen($json));
      if ($chunk === '' || $chunk === false) {
        return null;
      }
      $json .= $chunk;
    }
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
  }

}
