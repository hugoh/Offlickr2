<?php

require_once('./phpflickr/phpFlickr.php');
require_once('Dialog.php');
require_once('LocalStorage.php');

define("FLICKR_MAX_PER_PAGE", 500);

class Offlickr2 {

  // Version
  private $version = '0.0 -- 2010-12-27';

  // Private variables
  private $appid = 'c538ec60d29c939f35461ef134d6d579';
  private $secret = '84c560c121f79fdd';
  private $configuration_file = 'sample.ini';
  private $optdef = array();

  // Settings
  private $debug_level = 2;
  private $flickr_id = false;
  private $target_directory = false;
  private $backup_all_photos = false;
  private $local_checks = false;
  private $photo_list = array();
  private $set_list = array();

  /**
   * Help
   */

  private function help() {
    $this->dialog->dprint("Offlickr2 $this->version\n\n");
    foreach ($this->optdef as $opt) {
      $this->dialog->dprint($opt['doc'] . "\n");
    }
  }

  private function define_option($short, $arg, $doc) {
    return array('short' => $short . $arg,
                 'doc' => "-$short\t$doc");
  }

  /**
   * Parse command line arguments
   */

  private function parse_args() {
    $this->optdef =
      array(
            $this->define_option('h', '', 'Display this message'),
            $this->define_option('i', ':', 'Flickr ID'),
            $this->define_option('d', ':', 'Target directory'),
            $this->define_option('P', '', 'Backup photos'),
            $this->define_option('p', ':', 'Specific photo to backup'),
            $this->define_option('S', '', 'Backup sets'),
            $this->define_option('s', ':', 'Specific set to backup'),
            $this->define_option('q', '', 'Quick (local) check to evaluate state'),
            $this->define_option('v', ':', "Verbosity level; default: $this->debug_level"),
            );
    $short = '';
    foreach ($this->optdef as $opt) {
      $short .= $opt['short'];
    }

    $options = getopt($short);
    if (!is_array($options)) { 
      $this->error('Problem parsing options');
      exit(1); 
    }

    foreach (array_keys($options) as $opt)
      switch ($opt) {
      case 'v':
        $this->debug_level = $options[$opt];
        break;
      case 'q':
        $this->local_checks = true;
        break;
      case 'i':
        $this->flickr_id = $options[$opt];
        break;
      case 'd':
        $this->target_directory = $options[$opt];
        break;
      case 'P':
        $this->backup_all_photos = true;
        break;
      case 'p':
        if(is_array($options[$opt])) {
          $this->photo_list = $options[$opt];
        } else {
          $this->photo_list = array($options[$opt]);
        }
        break;
      case 'S':
        $this->backup_all_sets = true;
        break;
      case 's':
        if(is_array($options[$opt])) {
          $this->set_list = $options[$opt];
        } else {
          $this->set_list = array($options[$opt]);
        }
        break;
        
      case 'h':
        $this->help();
        exit(0);
      }

  }

  /**
   * Validate Flickr rsp rest response
   */

  private function rsp_validate($flickr_xml) {
    $doc = new DOMDocument();
    $doc->loadXML($flickr_xml);
    $rsp = $doc->getElementsByTagName('rsp');
    return ($rsp->item(0)->getAttribute('stat') == 'ok');
  }

  /**
   * Extract XML document from Flickr rest response
   */

  private function get_rsp_child($flickr_xml) {
    $doc = new DOMDocument();
    $doc->loadXML($flickr_xml);
    $rsp = $doc->getElementsByTagName('rsp');
    $c = $rsp->item(0)->childNodes;
    $e = false;
    for ($i = 0; $i < $c->length; $i++) {
      if ($c->item($i)->nodeType == XML_ELEMENT_NODE) {
        $e = $c->item($i);
        break;
      }
    }
    if ($e == false) {
      throw new Exception("Could not parse Flickr XML");
    }
    return $doc->saveXML($e);
  }

  /**
   * Get XML info from Flickr
   */

  private function get_flickr_xml($method, $args, $filename) {
    $xml = $this->phpflickr->request($method,
                                     array_merge($args, array("format"=>"rest")));
    if (!$this->rsp_validate($xml)) {
      $this->dialog->error("Error while getting " . $method);
      print $xml;
      return false;
    }
    $fp = fopen($filename, "w");
    $this->dialog->info(2, "Saving " . $method . " to " . $filename);
    fwrite($fp, $this->get_rsp_child($xml));
    fclose($fp);
    return true;
  }

  /**
   * Backup a photo
   */

  private function backup_photo($photo_id) {
    // Check if photo is already backed up
    $local_photo = false;
    $already_backed_up = false;
    if (!$this->local_checks) {
      $this->dialog->info(1, "Getting photo info");
      $photo_info = $this->phpflickr->photos_getInfo($photo_id);

      $local_photo = $this->local_storage->local_photo_factory($photo_info);
      if ($local_photo->is_backed_up()) {
        $already_backed_up = true;
      }
    } else {
      if ($this->local_storage->does_photo_seem_backed_up($photo_id)) {
        $already_backed_up = true;
      }
    }

    if ($already_backed_up) {
      $this->dialog->info(0, "Photo $photo_id already backed up");
      return true;
    }

    // If we got here, then we do need to back the photo up
    $this->dialog->info(1, "Processing photo $photo_id");

    if ($local_photo == false) {
      $this->dialog->info(1, "Getting photo info");
      $photo_info = $this->phpflickr->photos_getInfo($photo_id);
      $local_photo = $this->local_storage->local_photo_factory($photo_info);
    }

    $local_photo->setup_temporary_dir();

    // Binary
    $photo_size = $this->phpflickr->photos_getSizes($photo_id); 
    $source_url = false;
    foreach($photo_size as $size) {
      if ($size['label'] == "Original") {
        $source_url =  $size['source'];
        break;
      }
    }
    if ($source_url == false) {
      $this->dialog->error("Could not find source URL");
      return false;
    }
    $this->dialog->info(2, "Downloading binary to " . $local_photo->get_data_filename('binary', true));
    $this->dialog->info(2, "Binary source is " . $source_url);
    $fp = fopen($local_photo->get_data_filename('binary', true), "w");
    curl_setopt($this->curl, CURLOPT_URL, $source_url);
    curl_setopt($this->curl, CURLOPT_FILE, $fp);
    if (!curl_exec($this->curl)) {
      $this->dialog->error("Could not download binary");
      return false;
    }
    fclose($fp);

    // Metadata
    if (! $this->get_flickr_xml("flickr.photos.getInfo", array("photo_id"=>$photo_id),
                                $local_photo->get_data_filename('metadata', true))) {
      return false;
    }

    // Comments
    if (! $this->get_flickr_xml("flickr.photos.comments.getList", array("photo_id"=>$photo_id),
                                $local_photo->get_data_filename('comments', true))) {
      return false;
    }

    // Move to the right place
    $local_photo->save_temporary_files();
    return true;

  }

  /**
   * Backup a set
   */

  private function backup_set($set_id) {
    $this->dialog->info(1, "Processing set $set_id");

    $local_set = $this->local_storage->local_set_factory($set_id);
    $local_set->setup_temporary_dir();

    // Metadata
    if (! $this->get_flickr_xml("flickr.photosets.getInfo", array("photoset_id"=>$set_id),
                                $local_set->get_data_filename('info', true))) {
      return false;
    }

    // Photo list
    // FIXME: there could be more than one page
    if (! $this->get_flickr_xml("flickr.photosets.getPhotos", array("photoset_id"=>$set_id),
                                $local_set->get_data_filename('photos', true))) {
      return false;
    }

    // Move to the right place
    $local_set->save_temporary_files();
    return true;
  }

  /**
   * Get set list
   */

  private function get_set_list() {
    $this->dialog->info(0, "Getting set list");
    $sets = $this->phpflickr->photosets_getList();
    foreach ($sets['photoset'] as $set) {
      array_push($this->set_list, $set['id']);
    }
    $this->dialog->info(0, "Found: " . count($this->set_list) . " set(s)");
  }

  /**
   * Get photo list
   */

  private function get_photo_list() {
    $this->dialog->info(0, "Getting photo list");
    $page = 1;
    while(true) {
      $this->dialog->info(2, "Setting photos list page $page");
      $photos = $this->phpflickr->photos_search(array("user_id"=>$this->flickr_id,
                                                      "page"=>$page,
                                                      "per_page"=>FLICKR_MAX_PER_PAGE));
      if (count($photos['photo']) == 0) {
        break;
      }
      foreach ($photos['photo'] as $photo) {
        array_push($this->photo_list, $photo['id']);
      }
      $this->dialog->info(2, "Total so far: " . count($this->photo_list) . " photo(s)");
      $page += 1;
    }
    $this->dialog->info(0, "Found: " . count($this->photo_list) . " photo(s)");
  }

  /**
   * Backup a series of photo
   */

  private function backup_photos() {
    return $this->backup_items($this->photo_list, "photo", "backup_photo");
  }

  /**
   * Backup a series of sets
   */

  private function backup_sets() {
    return $this->backup_items($this->set_list, "set", "backup_set");
  }

  /**
   * Backup a series of items
   */

  private function backup_items($list, $what, $backup_function, $retry = true) {
    $total = count($list);
    $errors = array();
    if ($total > 0) {
      $i = 1;
      foreach ($list as $id) {
        $this->dialog->info(0, "Backing up $what $id [$i/$total]");
        if (!$this->{$backup_function}($id)) {
          array_push($errors, $id);
        }
        $i++;
      }
      $this->dialog->info(0, "Done with backup");
      if (count($errors) > 0) {
        $this->dialog->error("Could not backup some $what(s): " . implode(' ', $errors));
        if ($retry) {
          $this->dialog->info(0, "Retrying for $what(s) which had errors...");
          $this->backup_items($errors, $what, $backup_function, false);
        }
      }
    } else {
      $this->dialog->info(0, "No $what to backup");
    }
  }

  /**
   * Main function: does the backup
   */

  private function go() {

    // Check for Flickr ID
    if (!$this->flickr_id) { 
      $this->error("Missing Flickr ID");
      exit(1); 
    }

    // Create phpFlickr object
    $this->phpflickr = new phpFlickr($this->appid, $this->secret, true);
    $ini_array = parse_ini_file($this->configuration_file, true);
    if (!is_array($ini_array)) { 
      $this->error("Could not parse configuration file $this->configuration_file");
      exit(1); 
    }
    if (!is_array($ini_array[$this->flickr_id])) {
        $this->error("No information about Flickr id $this->flickr_id in configuration file $this->configuration_file");
        exit(1); 
      }
    $token = $ini_array[$this->flickr_id]['token'];
    if (!$token) {
      $this->error("No token for Flickr id $this->flickr_id in configuration file $this->configuration_file");
        exit(1); 
    }
    $this->phpflickr->setToken($token);

    // Do the backup
    if ($this->backup_all_photos) {
      $this->get_photo_list();
    }
    $this->backup_photos();
    if ($this->backup_all_sets) {
      $this->get_set_list();
    }
    $this->backup_sets();
  }

  /**
   * Main: constructor
   */

  function __construct() {
    $this->dialog = new Dialog($this->debug_level);
    $this->parse_args();
    try {
      $this->local_storage = new LocalStorage($this->target_directory, $this->debug_level);
    } catch (Exception $e) {
      exit();
    }
    $this->curl = curl_init();
    $this->go();
  }

}

$offlickr2 = new Offlickr2();

?>