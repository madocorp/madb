<?php


namespace MADB\Connection;

abstract class Connection {

  // timestamps:
  private $ts = 0; // start
  private $tc = 0; // after connect
  private $tq = 0; // after query
  private $tf = 0; // after fetch
  private $te = 0; // end

  public function __construct($data) {
    $defaults = static::getDefaults();
    $this->data = [];
    foreach ($defaults as $key => $default) {
      if (isset($data[$key])) {
        $this->data[$key] = $data[$key];
      } else {
        $this->data[$key] = $default;
      }
    }
  }

  public function getTimeStat() {
    $timeStat = [
      'connectTime' => $this->tc > $this->ts ? $this->tc - $this->ts : 0,
      'queryTime'   => $this->tq > $this->tc ? $this->tq - $this->tc : 0,
      'fetchTime'   => $this->tf > $this->tq ? $this->tf - $this->tq : 0,
      'overallTime' => $this->te > $this->ts ? $this->te - $this->ts : 0
    ];
    $this->ts = 0;
    $this->tc = 0;
    $this->tq = 0;
    $this->tf = 0;
    $this->te = 0;
    return $timeStat;
  }

  public function startTime() {
    $this->ts = microtime(true);
  }

  public function connectTime() {
    $this->tc = microtime(true);
  }

  public function endTime() {
    $this->te = microtime(true);
  }

  abstract static public function getDefaults();
  abstract public function test();
  abstract public function schemaList();
//  abstract public function tableList();
  abstract public function query();

}