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
  private $photo_list = array();

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

  private function parse_args() {
    $this->optdef =
      array(
            $this->define_option('h', '', 'Display this message'),
            $this->define_option('i', ':', 'Flickr ID'),
            $this->define_option('d', ':', 'Target directory'),
            $this->define_option('P', '', 'Backup photos'),
            $this->define_option('p', ':', 'Specific photo to backup'),
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
        
      case 'h':
        $this->help();
        exit(0);
      }

  }

  private function rsp_validate($flickr_xml) {
    $doc = new DOMDocument();
    $doc->loadXML($flickr_xml);
    $rsp = $doc->getElementsByTagName('rsp');
    return ($rsp->item(0)->getAttribute('stat') == 'ok');
  }

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

  private function get_flickr_xml($method, $photo_id, $filename) {
    $xml = $this->phpflickr->request($method,
                                     array("photo_id"=>$photo_id, "format"=>"rest"));
    if (!$this->rsp_validate($xml)) {
      $this->dialog->error("Error while getting " . $method);
      return false;
    }
    $fp = fopen($filename, "w");
    $this->dialog->info(2, "Saving " . $method . "  to " . $filename);
    fwrite($fp, $this->get_rsp_child($xml));
    fclose($fp);
    return true;
  }

  private function backup_photo($photo_id) {
    $this->dialog->info(1, "Getting photo info");
    $photo_info = $this->phpflickr->photos_getInfo($photo_id);

    // Check if we've already backed it up
    $local_photo = $this->local_storage->local_photo_factory($photo_info);
    if ($local_photo->is_backed_up()) {
      $this->dialog->info(1, "Photo $photo_id already backed up");
    } else {
      $this->dialog->info(1, "Processing photo $photo_id");

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
      $this->dialog->info(2, "Downloading binary to " . $local_photo->get_binary_filename(true));
      $this->dialog->info(2, "Binary source is " . $source_url);
      $fp = fopen($local_photo->get_binary_filename(true), "w");
      curl_setopt($this->curl, CURLOPT_URL, $source_url);
      curl_setopt($this->curl, CURLOPT_FILE, $fp);
      if (!curl_exec($this->curl)) {
        $this->dialog->error("Could not download binary");
        return false;
      }
      fclose($fp);

      // Metadata
      if (! $this->get_flickr_xml("flickr.photos.getInfo", $photo_id,
                                  $local_photo->get_metadata_filename(true))) {
        return false;
      }

      // Comments
      if (! $this->get_flickr_xml("flickr.photos.comments.getList", $photo_id,
                                  $local_photo->get_comments_filename(true))) {
        return false;
      }

      // Move to the right place
      $local_photo->save_temporary_files();
      return true;
    }

  }

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

  private function backup_photos($retry = true) {
    $total = count($this->photo_list);
    $errors = array();
    if ($total > 0) {
      $i = 1;
      foreach ($this->photo_list as $photo_id) {
        $this->dialog->info(0, "Backing up photo $photo_id [$i/$total]");
        if (!$this->backup_photo($photo_id)) {
          array_push($errors, $photo_id);
        }
        $i++;
      }
      $this->dialog->info(0, "Done with backup");
      if (count($errors) > 0) {
        $this->dialog->error("Could not backup some photos: " . implode(' ', $errors));
        if ($retry) {
          $this->dialog->info(0, "Retrying for photos which had errors...");
          $this->photo_list = $errors;
          $this->backup_photos(false);
        }
      }
    } else {
      $this->dialog->info(0, "Nothing to backup");
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