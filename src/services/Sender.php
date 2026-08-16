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

use matthiasott\webmention\models\Settings;
use matthiasott\webmention\Plugin;
use yii\base\Component;

class Sender extends Component
{
    /**
     * The Webmentions service, which supplies the guarded outbound request
     * helpers. Overridable so tests can substitute a stub without booting Craft.
     */
    protected function webmentions(): Webmentions
    {
        return Plugin::getInstance()->webmentions;
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
            $this->webmentions()->safePostForm($endpoint, [
                'source' => $source,
                'target' => $target,
            ]);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    public function getEndpoint(string $target): string|false
    {
        $webmentions = $this->webmentions();

        try {
            $endpoint = $this->_findEndpointInHeaders($target) ?? $this->_findEndpointInBody($target);
        } catch (\Throwable) {
            return false;
        }

        if (!$endpoint) {
            return false;
        }

        try {
            $endpoint = $webmentions->resolveUrl($endpoint, $target);
        } catch (\Throwable) {
            return false;
        }

        return $webmentions->safeUrl($endpoint) ?: false;
    }

    private function _findEndpointInBody(string $url): ?string
    {
        try {
            $response = $this->webmentions()->safeOutboundRequest('GET', $url, Settings::MAX_SOURCE_BODY_SIZE);
        } catch (\Throwable) {
            return null;
        }
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
        try {
            $response = $this->webmentions()->safeOutboundRequest('HEAD', $url);
        } catch (\Throwable) {
            return null;
        }

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
                    return $uriRef === '' ? $url : $uriRef;
                }
            }
        }

        return null;
    }
}
