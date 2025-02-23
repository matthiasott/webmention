<?php

/**
 * Webmention Sender Service
 *
 * @author Matthias Ott
 *
 * Huge parts of this code are based on @jgarber623's Craft Webmention Client Plugin
 * https://github.com/jgarber623/craft-webmention-client
 */

namespace matthiasott\webmention\services;

use Craft;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use yii\base\Component;

class Sender extends Component
{
    public Client $client;

    public function init()
    {
        parent::init();

        if (!isset($this->client)) {
            $this->client = Craft::createGuzzleClient();
        }
    }

    /**
     * Send a webmention to a given endpoint
     *
     * @param string $source The source URL
     * @param string $target The target URL
     * @return bool Whether the webmention was sent successfully
     */
    public function sendWebmention(string $source, string $target): bool
    {
        $endpoint = $this->getEndpoint($target);

        if (!$endpoint) {
            return false;
        }

        try {
            $this->client->post($endpoint, [
                RequestOptions::FORM_PARAMS => [
                    'source' => $source,
                    'target' => $target,
                ],
            ]);
        } catch (GuzzleException) {
            return false;
        }

        return true;
    }

    public function getEndpoint(string $target): string|false
    {
        try {
            $endpoint = $this->_findEndpointInHeaders($target)
                ?? $this->_findEndpointInBody($target);
        } catch (GuzzleException) {
            return false;
        }

        if (!$endpoint) {
            return false;
        }

        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return $this->_relativeToAbsoluteUrl($endpoint, $target);
        }

        return $endpoint;
    }

    private function _findEndpointInBody(string $url): ?string
    {
        $response = $this->client->get($url);
        $body = preg_replace('/<!--(?:.|\s)*?-->/', '', (string)$response->getBody());

        // Matches anchor and link tags if it contains href and webmention rel-attribute.
        // Test it here: http://regexr.com/3evjk
        $pattern = '~(<(?:link|a)(?=[^>]*href)(?:[^>]*\s+|\s*)rel="(?:[^>]*\s+|\s*)(?:webmention|http:\/\/webmention.org\/?)(?:\s*|\s+[^>]*)"[^>]*>)~i';

        if (!preg_match($pattern, $body, $matches)) {
            return null;
        }

        if (preg_match('/href=""/i', $matches[1])) {
            return $url;
        }

        if (preg_match('/href="([^"]+)"/i', $matches[1], $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function _findEndpointInHeaders(string $url): ?string
    {
        $response = $this->client->head($url);

        foreach ($response->getHeaders() as $name => $values) {
            if (strcasecmp($name, 'Link') === 0) {
                $pattern = '~<((?:https?:\/\/)?[^>]+)>; rel="?(?:[^>]*\s+|\s*)(?:webmention|http:\/\/webmention.org\/?)(?:\s*|\s+[^>]*)"?~i';

                foreach ($values as $link) {
                    if (preg_match($pattern, $link, $matches)) {
                        return $matches[1];
                    }
                }
            }
        }

        return null;
    }

    private function _relativeToAbsoluteUrl(string $url, string $base): string
    {
        // return if already absolute URL
        if (parse_url($url, PHP_URL_SCHEME) != '') {
            return $url;
        }

        // queries and anchors
        if ($url[0] == '#' || $url[0] == '?') {
            return $base . $url;
        }

        // parse base URL and convert to local variables: $scheme, $host, $path
        $urlInfo = parse_url($base);
        $scheme = $urlInfo['scheme'];
        $host = $urlInfo['host'];
        $path = $urlInfo['path'];

        // remove non-directory element from path
        $path = preg_replace('#/[^/]*$#', '', $path);

        // destroy path if relative url points to root
        if ($url[0] == '/') {
            $path = '';
        }

        // dirty absolute URL
        $abs = "$host$path/$url";

        // replace '//' or '/./' or '/foo/../' with '/'
        $re = ['#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#'];

        for ($n = 1; $n > 0; $abs = preg_replace($re, '/', $abs, -1, $n)) {
        }

        // absolute URL is ready!
        return $scheme . '://' . $abs;
    }
}
