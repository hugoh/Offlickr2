<?php

class Dialog {

  private $debug_level = 2;
  const DIALOG_SHOW_PROGRESS_LEVEL = 2;

  function __construct($level = 0) {
    $this->set_debug_level($level);
  }

  function set_debug_level($level) {
    $this->debug_level = $level;
  }

  function get_debug_level() {
    return $this->debug_level;
  }

  function show_progress() {
    return self::DIALOG_SHOW_PROGRESS_LEVEL <= $this->debug_level;
  }

  function progress($string) {
    if ($this->show_progress()) {
      print("\r" . $string);
    }
  }

  function progress_done($string = '') {
    if ($this->show_progress()) {
      print($string . " ... done\n");
    }
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

  function dump_var($debug, $name, $var) {
    if ($this->debug_level >= $debug) {
      print('DEBUG: ' . $name . ': ');
      print_r($var);
    }
  }

}

?>
