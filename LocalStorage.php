<?php

require_once('Dialog.php');

abstract class LocalItem {

  protected $temporary_dir = false;
  protected $data = array();

  function __construct($local_storage, $dialog) {
    $this->local_storage = $local_storage;
    $this->dialog = $dialog;
  }

  protected function assign_data_value($type, $value) {
    $this->data[$type] = $value;
    $this->dialog->info(3, "Target $type filename: " . $value);
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

  protected function get_filename($filename, $temporary) {
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
      if (!$this->has_data($d)) {
        $this->dialog->info(3, "Could not find $d file for back up");
        return false;
      }
    }
    return true;
  }

  protected function move_file($label, $old, $new) {
    if (!rename($old, $new)) {
      throw new Exception("Could not move temporary files for $label");
    } else {
      $this->dialog->info(3, "Moving $label to " . $new);
    }
  }

  function save_temporary_files() {
    $this->create_target_directory();

    foreach(array_keys($this->data) as $d) {
      if (!is_file($this->get_data_filename($d, true))) {
        continue;
      }
      $this->move_file($d, $this->get_data_filename($d, true), $this->get_data_filename($d));
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
  protected $total_pages = 0;

  const INFO = 'info';

  function __construct($set_id, $local_storage, $dialog) {
    parent::__construct($local_storage, $dialog);
    $this->location = $local_storage->relative(self::target_dir);

    $this->set_id = $set_id;

    // Target filenames
    $this->assign_data_value('info', $set_id . '.xml');
  }

  function set_pages($pages) {
    $this->total_pages = $pages;
  }

  function get_photoset_photos_filename($page, $temporary = false) {
    return $this->get_filename($this->set_id . '-photos-' . $page . '.xml', $temporary);
  }

  function save_temporary_files() {
    parent::save_temporary_files();
    for($page = 1; $page <= $this->total_pages; $page++) {
      $this->move_file("photo page $page", $this->get_photoset_photos_filename($page, true), $this->get_photoset_photos_filename($page));
    }
  }

}

class LocalPhoto extends LocalItem {

  const metadata_suffix = '-info.xml';
  const comments_suffix = '-comments.xml';

  const BINARY_FLAG = 1;
  const METADATA_FLAG = 2;
  const COMMENTS_FLAG = 4;
  const ALL_FLAGS = 7;

  const METADATA = 'metadata';
  const BINARY = 'binary';
  const COMMENTS = 'comments';

  function __construct($photo_info, $local_storage, $dialog) {
    parent::__construct($local_storage, $dialog);

    $this->photo_info = $photo_info['photo'];

    // Target directory
    $year = substr($this->photo_info['dates']['taken'], 0, 4);
    $month = substr($this->photo_info['dates']['taken'], 5, 2);
    $day = substr($this->photo_info['dates']['taken'], 8, 2);
    $this->location = $local_storage->relative($year . '/' . $month . '/' . $day);
    $this->dialog->info(3, "Target directory: $this->location");

    // Photo format
    if (array_key_exists('originalformat', $this->photo_info)) {
      $extension = $this->photo_info['originalformat'];
    } else {
      // FIXME: assume it's JPEG; a better way to do this is to call flickr.photos.getInfo and look it up
      $extension = 'jpg';
    }

    // Target filenames
    $this->assign_data_value('binary', $this->photo_info['id'] . '.' . $extension);
    $this->assign_data_value('metadata', $this->photo_info['id'] . self::metadata_suffix);
    $this->assign_data_value('comments', $this->photo_info['id'] . self::comments_suffix);
  }

  static function check_backup_dir($dir, &$present, $dialog, &$files = 0, $depth = 1) {
    // This assumes that the backup directory is in the right format

    if (is_dir($dir)) {
      $objects = scandir($dir); 
      foreach ($objects as $object) { 
        if ($object != "." && $object != ".." && ($depth > 1 || $object != LocalSet::target_dir)) { 
          if (filetype($dir."/".$object) == "dir") {
            LocalPhoto::check_backup_dir($dir."/".$object, $present, $dialog, $files, $depth + 1);
          } else {
            // Check for binary
            if (preg_match('/^(\d+)\./', $object, $matches)) {
              $dialog->progress(++$files);
              $present[(string)$matches[1]] |= self::BINARY_FLAG;
              next;
            }
            // Check for metadata
            if (preg_match('/^(\d+)' . self::metadata_suffix . '/', $object, $matches)) {
              $dialog->progress(++$files);
              $present[(string)$matches[1]] |= self::METADATA_FLAG;
              next;
            }
            // Check for comments
            if (preg_match('/^(\d+)' . self::comments_suffix . '/', $object, $matches)) {
              $dialog->progress(++$files);
              $present[(string)$matches[1]] |= self::COMMENTS_FLAG;
              next;
            }
          }
        } 
      } 

      if ($depth == 1) {
        $dialog->progress_done(' files scanned');
      }
    } 

    return $present;
  }

  static function backup_list($local_storage) {
    $present = array();
    LocalPhoto::check_backup_dir($local_storage->directory, $present, $local_storage->dialog);
    return $present;
  }

  static function does_photo_seem_backed_up($present, $photo_id) {
    return $present[$photo_id] == self::ALL_FLAGS;
  }

}

class LocalStorage {

  private $photos_backed_up = false;

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
      mkdir($dir, $mode = 0755, $recursive = true);
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

  function does_photo_seem_backed_up($photo_id) {
    if ($this->photos_backed_up === false) {
      $this->dialog->info(1, "Parsing local photo backup");
      $this->photos_backed_up = LocalPhoto::backup_list($this);
    }

    return LocalPhoto::does_photo_seem_backed_up($this->photos_backed_up, $photo_id);
  }

}

?>