<?php

require_once('./phpflickr/phpFlickr.php');
require_once('./scribe-php/src/test/bootstrap.php');

class oPhpFlickr extends phpFlickr {	

	function __construct($api_key, $secret = NULL, $die_on_error = false) {
		parent::__construct($api_key, $secret, $die_on_error);
		$builder = new ServiceBuilder();
		$this->oauth_service = $builder->provider(new FlickrApi())
		->apiKey($api_key)
		->apiSecret($secret)
		->build();
	}

	function authorize_console() {
		// Obtain the Request Token
		$requestToken = $this->oauth_service->getRequestToken();
		print("Go and authorize the application here:\n");
		print($this->oauth_service->getAuthorizationUrl($requestToken) . "\n");
		fwrite(STDOUT, "And paste the code you're given here: ");
		$verifier = new Verifier(trim(fgets(STDIN)));
		print("\n");
		// Trade the Request Token and Verfier for the Access Token
		$accessToken = $this->oauth_service->getAccessToken($requestToken, $verifier);
		return $accessToken;
	}

	function request ($command, $args = array()) {
		// NOTE: cache not implemented
		
		$args = array_merge(
			array("url" => $this->rest_endpoint,
				  "method" => $command,
				  "format" => "json",
				  "nojsoncallback" => "1"),
			$args);

        $request = new OAuthRequest(Verb::POST, $args['url']);
        foreach ($args as $key => $value) {
            $request->addBodyParameter($key, $value);
        }
        $this->oauth_service->signRequest($this->token, $request);
        $response_object = $request->send();
        $response = $response_object->getBody();

		$this->parsed_response = json_decode($response, TRUE);
		if ($this->parsed_response['stat'] == 'fail') {
			if ($this->die_on_error) die("The Flickr API returned the following error: #{$this->parsed_response['code']} - {$this->parsed_response['message']}");
			else {
				$this->error_code = $this->parsed_response['code'];
				$this->error_msg = $this->parsed_response['message'];
				$this->parsed_response = false;
			}
		} else {
			$this->error_code = false;
			$this->error_msg = false;
		}
		return $this->response;
	}

}

?>