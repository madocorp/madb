<?php

namespace MADB\Job;

class Message {

  public static function send($socket, $data) {
    $json = json_encode($data, JSON_THROW_ON_ERROR);
    $len = strlen($json);
    $res = fwrite($socket, pack('N', $len)); // 4-byte length
    if ($res === false) {
      throw new \Exception('Socket write error');
    }
    $res = fwrite($socket, $json);
    if ($res === false) {
      throw new \Exception('Socket write error');
    }
  }

  public static function receive($socket) {
    $header = fread($socket, 4);
    if ($header === false) {
      $meta = stream_get_meta_data($socket);
      if (!$meta['eof']) {
        usleep(10000);
        return;
      }
      $header === '';
    }
    if ($header === '') {
      throw new \Exception("Socket read error");
    }
    $len = unpack('N', $header)[1];
    $json = '';
    while (strlen($json) < $len) {
      $chunk = fread($socket, $len - strlen($json));
      if ($chunk === false) {
        $meta = stream_get_meta_data($socket);
        if (!$meta['eof']) {
          usleep(10000);
          continue;
        }
        $chunk === '';
      }
      if ($chunk === '') {
        throw new \Exception("Socket read error");
      }
      $json .= $chunk;
    }
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
  }

}
