<?php

require_once('Dialog.php');

abstract class LocalItem {

  protected $temporary_dir = false;
  protected $data = array();

  function __construct($local_storage, $dialog) {
    $this->local_storage = $local_storage;
    $this->dialog = $dialog;
  }

  function setup_temporary_dir() {
    // FIXME: Not atomic
    $tempname = tempnam($path,$prefix);
    if (!$tempname) {
      throw new Exception("Could not create temporary directory!");
    }

    if (!unlink($tempname)) {
      throw new Exception("Could not setup temporary directory!");
    }

    // Create the temporary directory and returns its name.
    if (mkdir($tempname)) {
      $this->temporary_dir = $tempname;
      $this->dialog->info(3, "Created temporary directory $tempname");
    } else {
      throw new Exception("Could not create temporary directory!");
    }
  }

  private function get_filename($filename, $temporary) {
    if ($temporary == true) {
      if ($this->temporary_dir == false) {
        throw new Exception("Temporary directory not setup");
      }
      $dir = $this->temporary_dir;
    } else {
      $dir = $this->location;
    }
    return $dir . '/' . $filename;
  }


  function has_data($type) {
    return is_file($this->full_path($this->data[$type]));
  }

  function get_data_filename($type, $temporary = false) {
    return $this->get_filename($this->data[$type], $temporary);
  }

  private function full_path($path) {
    return $this->location . '/' . $path;
  }

  private function create_target_directory() {
    if (!is_dir($this->location)) {
      $this->dialog->info(2, "Creating target directory " . $this->location);
      if (!mkdir($this->location, $mode = 0755, $recursive = true)) {
        throw new Exception("Could not create target directory");
      }
    }
  }

  function is_backed_up() {
    foreach(array_keys($this->data) as $d) {
      if (!has_data($type)) {
        return false;
      }
    }
    return true;
  }

  function save_temporary_files() {
    foreach(array_keys($this->data) as $d) {
      if (!is_file($this->get_data_filename($d, true))) {
        throw new Exception("Missing temporary files ($d): " . $this->get_data_filename($d, true));
      }
    }

    $this->create_target_directory();

    foreach(array_keys($this->data) as $d) {
      if (!rename($this->get_data_filename($d, true), $this->get_data_filename($d))) {
        throw new Exception("Could not move temporary files");
      }
    }

    $this->dialog->info(2, "Files moved to $this->location");
  }

  private function rrmdir($dir) { 
    if (is_dir($dir)) { 
      $objects = scandir($dir); 
      foreach ($objects as $object) { 
        if ($object != "." && $object != "..") { 
          if (filetype($dir."/".$object) == "dir") $this->rrmdir($dir."/".$object); else unlink($dir."/".$object);
        } 
      } 
      reset($objects); 
      rmdir($dir); 
    } 
  } 

  function __destruct() {
    if ($this->temporary_dir != false) {
      $this->dialog->info(3, "Cleaning up $this->temporary_dir");
      $this->rrmdir($this->temporary_dir);
    }
  }

}

class LocalSet extends LocalItem {

  const target_dir = "sets";

  function __construct($set_id, $local_storage, $dialog) {
    parent::__construct($local_storage, $dialog);
    $this->location = $local_storage->relative(self::target_dir);

    // Target filenames
    $this->data['info'] = $set_id . '.xml';
    $this->dialog->info(3, "Target metadata filename: " . $this->data['info']);
    $this->data['photos'] = $set_id . '-photos.xml';
    $this->dialog->info(3, "Target photo filename: " . $this->data['photos']);
  }

}

class LocalPhoto extends LocalItem {

  function __construct($photo_info, $local_storage, $dialog) {
    parent::__construct($local_storage, $dialog);

    $this->photo_info = $photo_info;

    // Target directory
    $year = substr($this->photo_info['dates']['taken'], 0, 4);
    $month = substr($this->photo_info['dates']['taken'], 5, 2);
    $day = substr($this->photo_info['dates']['taken'], 8, 2);
    $this->location = $local_storage->relative($year . '/' . $month . '/' . $day);
    $this->dialog->info(3, "Target directory: $this->location");

    // Target filenames
    $this->binary = $photo_info['id'] . '.' . $photo_info['originalformat'];
    $this->dialog->info(3, "Target binary filename: $this->binary");
    $this->metadata = $photo_info['id'] . '-info.xml';
    $this->dialog->info(3, "Target metadata filename: $this->metadata");
    $this->comments = $photo_info['id'] . '-comments.xml';
    $this->dialog->info(3, "Target binary filename: $this->comments");
  }

  function has_binary() {
    return is_file($this->full_path($this->binary));
  }

  function has_metadata() {
    return is_file($this->full_path($this->metadata));
  }

  function has_comments() {
    return is_file($this->full_path($this->comments));
  }

  function is_backed_up() {
      return $this->has_binary() && $this->has_metadata() && $this->has_comments();
  }

  function get_binary_filename($temporary = false) {
    return $this->get_filename($this->binary, $temporary);
  }

  function get_metadata_filename($temporary = false) {
    return $this->get_filename($this->metadata, $temporary);
  }

  function get_comments_filename($temporary = false) {
    return $this->get_filename($this->comments, $temporary);
  }

  function save_temporary_files() {
    if (!is_file($this->get_binary_filename(true)) ||
        !is_file($this->get_metadata_filename(true)) ||
        !is_file($this->get_comments_filename(true))) {
      throw new Exception("Missing some files");
    }

    $this->create_target_directory();

    if (!rename($this->get_binary_filename(true), $this->get_binary_filename())
        || !rename($this->get_metadata_filename(true), $this->get_metadata_filename())
        || !rename($this->get_comments_filename(true), $this->get_comments_filename())) {
      throw new Exception("Could not move temporary files");
    }

    $this->dialog->info(2, "Files moved to $this->location");

  }

}

class LocalStorage {

  function __construct($dir, $debug_level) {
    $this->dialog = new Dialog($debug_level);
    if (! is_string($dir)) {
      $this->dialog->error("Local directory not specified");
      throw new Exception();
    }
    $this->directory = $dir;
    $this->dialog->info(2, "Checking for local directory $dir");
    if (! is_dir($this->directory)) {
      $this->dialog->info(1, "Creating $dir");
      mkdir($dir);
    }
  }

  private function photo_directory($photo_info) {
    print($photo_info['dates']['taken']);
  }

  function is_photo_present($photo_info) {
    $this->photo_directory($photo_info);
  }

  function local_photo_factory($photo_info) {
    return new LocalPhoto($photo_info, $this, $this->dialog);
  }

  function local_set_factory($set_id) {
    return new LocalSet($set_id, $this, $this->dialog);
  }

  function relative($path) {
    return $this->directory . '/' . $path;
  }

}

?>