<?php

/**
 * Webmention Sender Service
 *
 * @author Matthias Ott
 * 
 * Huge parts of this code are based on @jgarber623's Craft Webmention Client Plugin 
 * https://github.com/jgarber623/craft-webmention-client
 */

namespace Craft;

class Webmention_SenderService extends BaseApplicationComponent {
	public function getEndpoint($url) {
		$endpoint = false;

		if ($headers = $this->_fetchHeaders($url)) {
			$endpoint = $this->_findEndpointInHeaders($headers);
		}

		if (!$endpoint && $body = $this->_fetchBody($url)) {
			$endpoint = $this->_findEndpointInBody($url, $body);
		}

		if ($endpoint && !filter_var($endpoint, FILTER_VALIDATE_URL)) {
			$endpoint = $this->_relativeToAbsoluteUrl($endpoint, $url);
		}

		return $endpoint;
	}

	private function _curl($url, $options) {
		$ch = curl_init($url);

		curl_setopt_array($ch, $options);

		$response = curl_exec($ch);

		curl_close($ch);

		return $response;
	}

	private function _fetchBody($url) {
		$options = array(
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_RETURNTRANSFER => true
		);

		return $this->_curl($url, $options);
	}

	private function _fetchHeaders($url) {
		$options = array(
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HEADER => true,
			CURLOPT_NOBODY => true,
			CURLOPT_RETURNTRANSFER => true
		);

		return $this->_curl($url, $options);
	}

	private function _findEndpointInBody($url, $body) {
		$endpoint = false;

		$body = preg_replace('/<!--(?:.|\s)*?-->/', '', $body);

		// Matches anchor and link tags if it contains href and webmention rel-attribute. 
		// Test it here: http://regexr.com/3evjk
		$pattern = '~(<(?:link|a)(?=[^>]*href)(?:[^>]*\s+|\s*)rel="(?:[^>]*\s+|\s*)(?:webmention|http:\/\/webmention.org\/?)(?:\s*|\s+[^>]*)"[^>]*>)~i';

		if (preg_match($pattern, $body, $matches)) {

			if (preg_match('/href=""/i', $matches[1], $matchestoo)) {
				$endpoint = $url;
			} 
			elseif (preg_match('/href="([^"]+)"/i', $matches[1], $matches)) {
				$endpoint = $matches[1];
			}
					
		}

		return $endpoint;
	}

	private function _findEndpointInHeaders($headers) {
		$endpoint = false;
		$parsedHeaders = $this->_parseHeaders($headers);

		if (array_key_exists('link', array_change_key_case($parsedHeaders))) {
			$pattern = '~<((?:https?:\/\/)?[^>]+)>; rel="?(?:[^>]*\s+|\s*)(?:webmention|http:\/\/webmention.org\/?)(?:\s*|\s+[^>]*)"?~i';

			if ( is_array($parsedHeaders['Link'])) {
				foreach ($parsedHeaders['Link'] as $link) {
					if (preg_match($pattern, $link, $matches)) {
						$endpoint = $matches[1];
					}
				}
			} else {
				if (preg_match($pattern, $parsedHeaders['Link'], $matches)) {
					$endpoint = $matches[1];
				}
			}
			
		}

		return $endpoint;
	}

	private function _parseHeaders($headers) {
		$headersArray = array();
		$fields = explode("\r\n", preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $headers));

		foreach ($fields as $field) {
			if (preg_match('/([^:]+): (.+)/m', $field, $match)) {
				$match[1] = preg_replace_callback('/(?<=^|[\x09\x20\x2D])./',  function($m){ return strtoupper($m[0]); }, strtolower(trim($match[1])));
				if (isset($headersArray[$match[1]])) {
					if (!is_array($headersArray[$match[1]])) {
						$headersArray[$match[1]] = array($headersArray[$match[1]]);
					}
					$headersArray[$match[1]][] = $match[2];
				} else {
					$headersArray[$match[1]] = trim($match[2]);
				}
			}
		}

		return $headersArray;
	}

	private function _relativeToAbsoluteUrl($url, $base) {
		/* return if already absolute URL */
		if (parse_url($url, PHP_URL_SCHEME) != '') return $url;

		/* queries and anchors */
		if ($url[0]=='#' || $url[0]=='?') return $base . $url;

		/* parse base URL and convert to local variables: $scheme, $host, $path */
		extract(parse_url($base));

		/* remove non-directory element from path */
		$path = preg_replace('#/[^/]*$#', '', $path);

		/* destroy path if relative url points to root */
		if ($url[0] == '/') $path = '';

		/* dirty absolute URL */
		$abs = "$host$path/$url";

		/* replace '//' or '/./' or '/foo/../' with '/' */
		$re = array('#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#');

		for ($n = 1; $n > 0; $abs = preg_replace($re, '/', $abs, -1, $n)) {}

		/* absolute URL is ready! */
		return $scheme.'://'.$abs;
	}
}