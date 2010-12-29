<?php

class Dialog {

  private $debug_level = 2;

  function __construct($level = 0) {
    $this->debug_level = $level;
  }

  private function dialog($debug, $string) {
    if ($this->debug_level >= $debug) {
      if ($debug > 0) {
        print("- ");
      }
      print($string);
    }
  }

  function dprint($string) {
    $this->dialog(-1, $string);
  }

  function info($debug, $string) {
    $this->dialog($debug, $string . "\n");
  }

  function error($string) {
    $this->dialog(0, 'ERROR: ' . $string . "\n");
  }

}

?>