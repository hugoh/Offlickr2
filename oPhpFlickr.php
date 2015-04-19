<?php

require_once('./phpflickr/phpFlickr.php');
require_once('./scribe-php/src/test/bootstrap.php');

class oPhpFlickr extends phpFlickr {

		function phpFlickr ($api_key, $secret = NULL, $die_on_error = false) {
			parent::phpFlickr($api_key, $secret, $die_on_error);
		}
}

?>