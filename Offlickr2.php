<?php

require_once('./oPhpFlickr.php');
require_once('./Dialog.php');
require_once('./LocalStorage.php');
require_once('./version.php');

define('FLICKR_APPID', 'c538ec60d29c939f35461ef134d6d579');
define('FLICKR_SECRET', '84c560c121f79fdd');
define("FLICKR_MAX_PER_PAGE", 500);
define('DEFAULT_AUTH_FILE', getenv("HOME") . '/.offlickr2.auth.ini');
define('CONFIG_ACCESS_TOKEN', 'access_token');
define('CONFIG_ACCESS_TOKEN_SECRET', 'access_token_secret');

class Offlickr2 {

  // Version
  private $version = OFFLICKR2_VERSION;

  // Private variables
  private $appid = FLICKR_APPID;
  private $secret = FLICKR_SECRET;
  private $configuration_file = DEFAULT_AUTH_FILE;
  private $optdef = array();

  // Settings
  private $debug_level = 0;
  private $flickr_id = false;
  private $flickr_username = false;
  private $target_directory = false;
  private $backup_all_photos = false;
  private $backup_photos_limit = 0;
  private $force_backup = 0;
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
            $this->define_option('c', ':', 'Configuration file (default: '.$this->configuration_file.')'),
            $this->define_option('i', ':', 'Flickr ID'),
            $this->define_option('I', ':', 'Flickr username'),            
            $this->define_option('d', ':', 'Target directory'),
            $this->define_option('P', '', 'Backup photos'),
            $this->define_option('p', ':', 'Specific photo to backup'),
            $this->define_option('l', ':', 'Limit of photos to backup'),
            $this->define_option('B', '', 'Force backup of photo binary (photo or video)'),
            $this->define_option('M', '', 'Force backup of photo metadata'),
            $this->define_option('C', '', 'Force backup of photo comments'),
            $this->define_option('A', '', 'Force backup of photo (all facets)'),
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
      $this->dialog->error('Problem parsing options');
      exit(1); 
    }

    foreach (array_keys($options) as $opt)
      switch ($opt) {
      case 'v':
        $this->debug_level = $options[$opt];
        $this->dialog->set_debug_level($this->debug_level);
        break;
      case 'c':
        $this->configuration_file = $options[$opt];
        break;
      case 'q':
        $this->local_checks = true;
        break;
      case 'i':
        $this->flickr_id = $options[$opt];
        break;
      case 'I':
        $this->flickr_username = $options[$opt];
        break;
      case 'd':
        $this->target_directory = $options[$opt];
        break;
      case 'P':
        $this->backup_all_photos = true;
        break;
      case 'l':
        $this->backup_photos_limit = $options[$opt];
        break;
      case 'B':
        $this->force_backup |= LocalMedia::BINARY_FLAG;;
        break;
      case 'M':
        $this->force_backup |= LocalMedia::METADATA_FLAG;;
        break;
      case 'C':
        $this->force_backup |= LocalMedia::COMMENTS_FLAG;;
        break;
      case 'A':
        $this->force_backup |= LocalMedia::ALL_FLAGS;;
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
    if ($doc->loadXML($flickr_xml) == FALSE) {
      return false;
    }
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
      $this->dialog->dump_var(0, "Flickr response", $xml);
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
    $local_media = false;

    if ($this->force_backup == 0) {

      $already_backed_up = false;
      if (!$this->local_checks) {
        $this->dialog->info(1, "Getting photo info");
        $photo_info = $this->phpflickr->photos_getInfo($photo_id);

        if (!$photo_info) {
          $this->dialog->error("Could not retrieve photo info for $photo_id");
          return false;
        }

        $local_media = $this->local_storage->local_media_factory($photo_info);
        if ($local_media->is_backed_up()) {
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
    } else {
      $this->dialog->info(3, "Forcing download (" . $this->force_backup . ")");
    }

    // If we got here, then we do need to back the photo up
    $this->dialog->info(1, "Processing photo $photo_id");

    if ($local_media == false) {
      $this->dialog->info(1, "Getting photo info");
      $photo_info = $this->phpflickr->photos_getInfo($photo_id);
      $local_media = $this->local_storage->local_media_factory($photo_info);
    }

    $local_media->setup_temporary_dir();

    $backed_up = 0;

    // Binary
    if ($this->force_backup & LocalMedia::BINARY_FLAG || ! $local_media->has_data(LocalMedia::BINARY)) {
      $photo_size = $this->phpflickr->photos_getSizes($photo_id); 
      $source_url = false;
      $max_size = 0;
      // Find the right source URL
      $media = $local_media->get_media_type();
      foreach(array_reverse($photo_size) as $size) {
        if ($size['media'] != $media) {
          $this->dialog->info(3, "Skipping " . $size['label'] . ": wrong media (" . $size['media'] . ")");
          continue;
        }
        $this->dialog->info(3, "Found " . $size['label'] . " for " . $size['media'] . " media");
        if (in_array($size['label'], array('Original', 'Video Original'))) {
          // We found the original
          $source_url =  $size['source'];
          break;
        }
        $ps = $size['height'] * $size['width'];
        if ($max_size < $ps) {
          $source_url =  $size['source'];
          $max_size = $ps;
        }
      }
      if ($source_url == false) {
        $this->dialog->error("Could not find source URL");
        $this->dialog->dump_var(4, "Flickr response", $this->phpflickr->parsed_response);
        return false;
      }
      $this->dialog->info(2, "Downloading " . $media . " binary to " . $local_media->get_data_filename(LocalMedia::BINARY, true));
      $this->dialog->info(2, "Binary source is " . $source_url);
      $fp = fopen($local_media->get_data_filename(LocalMedia::BINARY, true), "w");
      curl_setopt($this->curl, CURLOPT_URL, $source_url);
      curl_setopt($this->curl, CURLOPT_FILE, $fp);
      curl_setopt($this->curl, CURLOPT_FOLLOWLOCATION, true);
      if ($this->dialog->show_progress()) {
        curl_setopt($this->curl, CURLOPT_NOPROGRESS, false);
      }
      if (!curl_exec($this->curl)) {
        $this->dialog->error("Could not download binary");
        return false;
      }
      fclose($fp);
      if ($local_media->is_video()) {
        // Now that we have the video, find out about the extension
        $current_filename = $local_media->get_data_filename(LocalMedia::BINARY, true);
        $http_media_type = curl_getinfo($this->curl, CURLINFO_CONTENT_TYPE);
        $local_media->set_extension($http_media_type);
        $new_filename = $local_media->get_data_filename(LocalMedia::BINARY, true);
        $this->dialog->info(2, "Renaming " . $current_filename . " into " . $new_filename);
        if (!rename($current_filename, $new_filename)) {
          return false;
        }
      }
    }

    // Metadata
    if ($this->force_backup & LocalMedia::METADATA_FLAG || ! $local_media->has_data(LocalMedia::METADATA)) {
      if (! $this->get_flickr_xml("flickr.photos.getInfo", array("photo_id"=>$photo_id),
                                  $local_media->get_data_filename(LocalMedia::METADATA, true))) {
        return false;
      }
    }

    // Comments
    if ($this->force_backup & LocalMedia::COMMENTS_FLAG || ! $local_media->has_data(LocalMedia::COMMENTS)) {
      if (! $this->get_flickr_xml("flickr.photos.comments.getList", array("photo_id"=>$photo_id),
                                  $local_media->get_data_filename(LocalMedia::COMMENTS, true))) {
        return false;
      }
    }

    // Move to the right place
    $local_media->save_temporary_files();
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
                                $local_set->get_data_filename(LocalSet::INFO, true))) {
      return false;
    }

    // Photo list
    $ps = $this->phpflickr->photosets_getPhotos($set_id);
    $total_pages = $ps['photoset']['pages'];
    $local_set->set_pages($total_pages);
    for($page = 1; $page <= $total_pages; $page++) {
      $this->dialog->info(3, "Set $set_id - page $page");
      if (! $this->get_flickr_xml("flickr.photosets.getPhotos",
                                  array("photoset_id"=>$set_id, "page" => $page),
                                  $local_set->get_photoset_photos_filename($page, true))) {
        return false;
      }
      
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
    $this->dialog->info(0, "Getting photo list" . ($this->backup_photos_limit > 0 ? ' (' . $this->backup_photos_limit .' max)' : ''));
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
      $retrieved = count($this->photo_list);
      $this->dialog->info(2, "Total so far: " . $retrieved . " photo(s)");
      if ($this->backup_photos_limit > 0 && $retrieved >= $this->backup_photos_limit) {
        break;
      }
      $page += 1;
    }
    $this->dialog->info(0, "Found: " . count($this->photo_list) . " photo(s)");
  }

  /**
   * Backup a series of photo
   */

  private function backup_photos() {
    if ($this->backup_photos_limit > 0) {
      array_splice($this->photo_list, $this->backup_photos_limit);
    }
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
   * Configuration
   */
  
  private function save_auth_header($config_handle) {
    fputs($config_handle,
      sprintf("\n[%s]\n", $this->flickr_id));
  }

  private function save_auth_data($config_handle, $property, $value) {
    fputs($config_handle,
      sprintf("%s = %s\n", $property, $value));
  }
  
  /**
   * Flickr authorization
   */
  
  private function save_access_token($token) {
    $this->dialog->info(1, "Saving the access token to $this->configuration_file");
    $ah = fopen($this->configuration_file, "a");
    if ($ah === FALSE) {
      $this->dialog->error("Cannot write to configuration file");
      exit(1);
    }
    $ah = fopen($this->configuration_file, "a");
    if ($ah === FALSE) {
      $this->dialog->error("Cannot write to configuration file");
      exit(1);
    }
    $this->save_auth_header($ah);
    $this->save_auth_data($ah, CONFIG_ACCESS_TOKEN, $token->getToken());
    $this->save_auth_data($ah, CONFIG_ACCESS_TOKEN_SECRET, $token->getSecret());
    fclose($ah);
  }

  private function authorize() {
    $this->dialog->info(0, "\nYou need to authorize Offlickr2 to access your Flickr account");
    $token = $this->phpflickr->authorize_console();
    $this->save_access_token($token);
    return $token;
  }

  /**
   * Main function: does the backup
   */

  private function go() {

    $this->phpflickr = new oPhpFlickr($this->appid, $this->secret, true);

    // Check for Flickr username
    if ($this->flickr_username != false) { 
      $this->dialog->info(1, "Looking for Flickr id for username $this->flickr_username");
      $r = $this->phpflickr->people_findByUsername($this->flickr_username);
      $this->flickr_id = $r['nsid'];
    }    

    // Check for Flickr ID
    if (!$this->flickr_id) { 
      $this->dialog->error("Missing Flickr ID");
      exit(1); 
    }

    // Create phpFlickr object
    $ini_array = parse_ini_file($this->configuration_file, true);
    if (!is_array($ini_array) || !is_array($ini_array[$this->flickr_id])
        || !$ini_array[$this->flickr_id][CONFIG_ACCESS_TOKEN]
        || !$ini_array[$this->flickr_id][CONFIG_ACCESS_TOKEN_SECRET]) {
        $this->dialog->info(1, "No information about Flickr id $this->flickr_id in configuration file $this->configuration_file");
        $token = $this->authorize();
    } else {
      $token = new Token($ini_array[$this->flickr_id][CONFIG_ACCESS_TOKEN],
                         $ini_array[$this->flickr_id][CONFIG_ACCESS_TOKEN_SECRET]);
    }
    if (!$token || $token->getToken() == '' || $token->getSecret() == '') {
      $this->dialog->error("No access token for Flickr id $this->flickr_id in configuration file $this->configuration_file: " . $token->__toString());
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
