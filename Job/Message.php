<?php

namespace MADB\Job;

/** Encodes and decodes length-prefixed messages sent between job director, workers, and the UI process. */
class Message {

  /** Coordinates send work in the background job system. */
  public static function send($socket, $data) {
    $json = json_encode($data, JSON_THROW_ON_ERROR);
    $len = strlen($json);
    self::writeBytes($socket, pack('N', $len)); // 4-byte length
    self::writeBytes($socket, $json);
  }

  /** Coordinates receive work in the background job system. */
  public static function receive($socket) {
    $header = self::readBytes($socket, 4);
    $len = unpack('N', $header)[1];
    $json = self::readBytes($socket, $len);
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
  }

  /** Coordinates read bytes work in the background job system. */
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

  /** Writes the full buffer to a stream socket, including large payloads that require multiple fwrite calls. */
  private static function writeBytes($socket, string $data): void {
    $written = 0;
    $length = strlen($data);
    while ($written < $length) {
      $chunk = fwrite($socket, substr($data, $written));
      if ($chunk === false) {
        throw new \Exception('Socket write error');
      }
      if ($chunk === 0) {
        $write = [$socket];
        $read = $except = [];
        if (stream_select($read, $write, $except, 1, 0) === false) {
          throw new \Exception('Socket write error');
        }
        continue;
      }
      $written += $chunk;
    }
  }

}
