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
        $body = (string) $response->getBody();

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $body = mb_convert_encoding($body, 'HTML-ENTITIES', mb_detect_encoding($body));
        @$doc->loadHTML($body, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xpath = new \DOMXPath($doc);

        foreach ($xpath->query('//link[@href]|//a[@href]') as $node) {
            /** @var \DOMElement $node */
            // rel token matching is ASCII case-insensitive per the HTML living standard,
            // so lowercase the value before splitting and comparing.
            $rels = preg_split('/\s+/', strtolower(trim($node->getAttribute('rel'))), -1, PREG_SPLIT_NO_EMPTY);
            if (in_array('webmention', $rels, true) || in_array('http://webmention.org/', $rels, true)) {
                $href = trim($node->getAttribute('href'));
                return $href === '' ? $url : $href;
            }
        }

        return null;
    }

    private function _findEndpointInHeaders(string $url): ?string
    {
        $response = $this->client->head($url);

        foreach ($response->getHeader('Link') as $headerValue) {
            // Multiple link-values may be comma-separated in a single header line
            foreach (explode(',', $headerValue) as $linkValue) {
                $parts = explode(';', $linkValue);
                $uriRef = trim(trim($parts[0]), '<>');
                $rels = [];
                for ($i = 1; $i < count($parts); $i++) {
                    $param = trim($parts[$i]);
                    if (stripos($param, 'rel=') === 0) {
                        $relVal = trim(substr($param, 4), '"\'');
                        // rel can contain multiple space-separated values
                        foreach (preg_split('/\s+/', $relVal) as $r) {
                            $rels[] = strtolower(trim($r));
                        }
                    }
                }
                if (in_array('webmention', $rels, true) || in_array('http://webmention.org/', $rels, true)) {
                    return $uriRef === '' ? $url : $this->_relativeToAbsoluteUrl($uriRef, $url);
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
