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
    $header = self::readBytes($socket, 4);
    $len = unpack('N', $header)[1];
    $json = self::readBytes($socket, $len);
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
  }

  private static function readBytes($socket, int $length): string {
    $data = '';
    while (strlen($data) < $length) {
      $chunk = fread($socket, $length - strlen($data));
      if ($chunk === false || $chunk === '') {
        $meta = stream_get_meta_data($socket);
        if (!$meta['eof']) {
          usleep(10000);
          continue;
        }
        throw new \Exception("Socket read error");
      }
      $data .= $chunk;
    }
    return $data;
  }

}
