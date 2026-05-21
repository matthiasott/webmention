<?php

namespace matthiasott\webmention\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\events\ModelEvent;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Html;
use craft\helpers\HtmlPurifier;
use craft\helpers\Image;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use craft\models\Site;
use craft\models\VolumeFolder;
use DateTime;
use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Collection;
use LitEmoji\LitEmoji;
use matthiasott\webmention\elements\Webmention;
use matthiasott\webmention\fields\WebmentionSwitch;
use matthiasott\webmention\jobs\SendWebmention;
use matthiasott\webmention\Plugin;
use matthiasott\webmention\records\WebmentionFailure;
use Mf2;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\db\Expression;

/**
 * Webmention Service
 *
 * Provides a consistent API for the plugin to access the database
 */
class Webmentions extends Component
{
    /**
     * Gets all available webmentions for an element
     *
     * @param ElementInterface $element
     * @return Webmention[]
     */
    public function getWebmentionsForElement(ElementInterface $element): array
    {
        return Webmention::find()
            ->targetId($element->id)
            ->targetSiteId($element::isLocalized() ? $element->siteId : null)
            ->all();
    }

    /**
     * Returns the total webmentions for an element
     *
     * @param ElementInterface $element
     * @return int
     * @since 1.0.4
     */
    public function getTotalWebmentionsForElement(ElementInterface $element): int
    {
        return Webmention::find()
            ->targetId($element->id)
            ->targetSiteId($element::isLocalized() ? $element->siteId : null)
            ->count();
    }

    /**
     * Gets all available webmentions for an element by type (e.g. `mention`, `like`, or `repost`)
     *
     * @param ElementInterface $element
     * @param string|null $type
     * @return Webmention[]
     */
    public function getWebmentionsForElementByType(ElementInterface $element, ?string $type = null): array
    {
        return Webmention::find()
            ->targetId($element->id)
            ->targetSiteId($element::isLocalized() ? $element->siteId : null)
            ->type($type)
            ->all();
    }

    /**
     * Returns the total webmentions for an element by type (e.g. `mention`, `like`, or `repost`)
     *
     * @param ElementInterface $element
     * @param string|null $type
     * @return int
     * @since 1.0.4
     */
    public function getTotalWebmentionsForElementByType(ElementInterface $element, ?string $type = null): int
    {
        return Webmention::find()
            ->targetId($element->id)
            ->targetSiteId($element::isLocalized() ? $element->siteId : null)
            ->type($type)
            ->count();
    }

    /**
     * Check a webmention for typical structures of a brid.gy webmention and update $results array accordingly.
     *
     * @param array $result
     * @param array $entry
     * @param string $src
     * @param bool $useBridgy
     */
    private function _checkResponseType(array &$result, array $entry, string $src, bool $useBridgy): void
    {
        // Check for brid.gy first
        if (
            $useBridgy &&
            !empty($src) &&
            (preg_match('!http(.*?)://brid-gy.appspot.com!', $src) || preg_match('!http(.*?)://brid.gy!', $src))
        ) {
            // Is it The Facebook?
            if (!empty($result['url']) and preg_match('!http(.*?)facebook.com!', $result['url'])) {
                $result['site'] = 'facebook';
            }
            // Is it Instagram?
            if (!empty($result['url']) and preg_match('!http(.*?)instagram.com!', $result['url'])) {
                $result['site'] = 'instagram';
            }
            // Flickr?
            if (!empty($result['url']) and preg_match('!http(.*?)flickr.com!', $result['url'])) {
                $result['site'] = 'flickr';
            }

            // Get the site from Bridgy's webmention source URLs
            if (preg_match('/\/facebook\//', $src)) {
                $result['site'] = 'facebook';
            }
            if (preg_match('/\/flickr\//', $src)) {
                $result['site'] = 'flickr';
            }
            if (preg_match('/\/github\//', $src)) {
                $result['site'] = 'github';
            }
            if (preg_match('/\/instagram\//', $src)) {
                $result['site'] = 'instagram';
            }
            if (preg_match('/\/mastodon\//', $src)) {
                $result['site'] = 'mastodon';
            }
            if (preg_match('/\/bluesky\//', $src)) {
                $result['site'] = 'bluesky';
            }
            if (preg_match('/\/reddit\//', $src)) {
                $result['site'] = 'reddit';
            }

            // Get the type of mention from Bridgy's webmention source URLs
            if (preg_match('/\/post\//', $src)) {
                $result['type'] = 'mention';
            } elseif (preg_match('/\/comment\//', $src)) {
                $result['type'] = 'comment';
            } elseif (preg_match('/\/like\//', $src)) {
                $result['type'] = 'like';
            } elseif (preg_match('/\/repost\//', $src)) {
                $result['type'] = 'repost';
            } elseif (preg_match('/\/rsvp\//', $src)) {
                $result['type'] = 'rsvp';
            }
        } else {
            if (isset($entry['properties']['like-of']) || isset($entry['properties']['like'])) {
                $result['type'] = 'like';
            }
            if (isset($entry['properties']['repost-of']) || isset($entry['properties']['repost'])) {
                $result['type'] = 'repost';
            }
        }
    }

    /**
     * Get either a Gravatar URL or complete image tag for a specified email address.
     *
     * @param string $email The email address
     * @return string containing either just a URL or a complete image tag
     * @source http://gravatar.com/site/implement/images/php/
     */
    private function _get_gravatar(string $email): string
    {
        $url = 'https://www.gravatar.com/avatar/';
        $url .= md5(strtolower(trim($email)));
        return $url;
    }

    /**
     * Validate a Webmention
     * Check if source URL is valid and if it contains a backlink to the target
     *
     * @param string $src The source URL
     * @param string $target The target URL
     * @return string|false The HTML of the valid Webmention source
     */
    public function validateWebmention(string $src, string $target): string|false
    {
        // Source and target must not match!
        if ($src === $target) {
            return false;
        }

        // First check if both source and target are http(s)
        if (
            (!str_starts_with($src, 'http://') && !str_starts_with($src, 'https://')) ||
            (!str_starts_with($target, 'http://') && !str_starts_with($target, 'https://'))
        ) {
            return false;
        }

        // Sources whose host matches `trustedSourceHosts` bypass the private/reserved IP
        // checks below — this enables self-hosted senders on private networks (homelab
        // Mastodon, intranet Micropub, etc.) to deliver webmentions when their hostname
        // is explicitly listed in plugin settings. Trust is opt-in and admin-controlled.
        $isTrusted = $this->isTrustedSource($src);

        // Check if source URL is resolvable (skipped for trusted hosts)
        if (!$isTrusted && !$this->isResolvableUrl($src)) {
            Craft::warning("Skipping webmention from unresolvable domain: $src", 'webmention');
            return false;
        }

        // Get HTML content
        try {
            Craft::info('Fetching source URL: ' . $src, 'webmention');
            $response = $this->safeOutboundRequest('GET', $src, \matthiasott\webmention\models\Settings::MAX_SOURCE_BODY_SIZE, $isTrusted);
            $html = (string) $response->getBody();
        } catch (\Throwable $e) {
            Craft::warning(sprintf('Failed to fetch source URL "%s": %s', $src, $e->getMessage()), 'webmention');
            return false;
        }
        Craft::info('Fetched HTML, length: ' . strlen($html), 'webmention');

        // and go find a backlink
        $doc = new DOMDocument();
        libxml_use_internal_errors(true); # suppress parse errors and warnings
        $body = mb_convert_encoding($html, 'HTML-ENTITIES', mb_detect_encoding($html));
        @$doc->loadHTML($body, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        $normalizedTarget = $this->normalizeUrl($target);
        $linkUrls = [];

        foreach ($xpath->query('//a[@href]') as $link) {
            /** @var DOMElement $link */
            $linkUrl = trim($link->getAttribute('href'));
            if ($linkUrl === '') {
                continue;
            }

            // Skip non-http(s) hrefs (e.g. at://, mailto:, tel:, javascript:).
            // Guzzle's Uri parser throws on at:// URIs from Bridgy's Bluesky
            // pages because PHP's parse_url mis-handles the did:plc: authority,
            // which would kill the whole link loop.
            $scheme = strtolower((string) parse_url($linkUrl, PHP_URL_SCHEME));
            if ($scheme !== '' && $scheme !== 'http' && $scheme !== 'https') {
                continue;
            }

            try {
                $resolvedUrl = $this->resolveUrl($linkUrl, $src);
            } catch (\Throwable $e) {
                continue;
            }

            if ($this->normalizeUrl($resolvedUrl) === $normalizedTarget) {
                return $html;
            }
            $linkUrls[] = $resolvedUrl;
        }

        // Only check for redirects if we haven't found a direct match, and limit the number of HEAD requests
        $client = Craft::createGuzzleClient();
        $redirectCheckCount = 0;
        $maxRedirectChecks = 10;

        foreach ($linkUrls as $linkUrl) {
            if ($redirectCheckCount >= $maxRedirectChecks) {
                break;
            }

            // Skip common social media and other high-link-count domains to save time
            $host = parse_url($linkUrl, PHP_URL_HOST);
            if ($host && preg_match('/(facebook|twitter|instagram|linkedin|github)\.com$/', $host)) {
                continue;
            }

            // Skip URLs resolving to private/reserved IPs (SSRF protection), unless the
            // source host is trusted — trust extends to links discovered on the source page.
            if (!$isTrusted && !$this->isResolvableUrl($linkUrl)) {
                continue;
            }

            try {
                $head = $client->head($linkUrl, [
                    RequestOptions::ALLOW_REDIRECTS => false,
                    RequestOptions::CONNECT_TIMEOUT => 5,
                    RequestOptions::TIMEOUT => 5,
                ]);
                $redirectCheckCount++;
            } catch (GuzzleException) {
                continue;
            }

            if ($head->hasHeader('Location')) {
                $redirect = $head->getHeader('Location')[0];
                try {
                    $resolvedRedirect = $this->resolveUrl($redirect, $linkUrl);
                } catch (\Throwable $e) {
                    continue;
                }
                if ($this->normalizeUrl($resolvedRedirect) === $normalizedTarget) {
                    return $html;
                }
            }
        }

        return false;
    }

    /**
     * Check if a URL's domain is resolvable via DNS.
     * Returns false for local/test TLDs that won't resolve.
     *
     * @param string $url
     * @return bool
     */
    protected function isResolvableUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }

        // Skip local/test TLDs that won't resolve
        $localTlds = ['test', 'local', 'localhost', 'internal', 'example'];
        $hostParts = explode('.', $host);
        $tld = strtolower(end($hostParts));
        if (in_array($tld, $localTlds, true)) {
            return false;
        }

        // Skip localhost and IP addresses
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Query both A and AAAA records
        $aRecords = @dns_get_record($host, DNS_A) ?: [];
        $aaaaRecords = @dns_get_record($host, DNS_AAAA) ?: [];
        $allRecords = array_merge($aRecords, $aaaaRecords);

        if (empty($allRecords)) {
            return false;
        }

        // Validate each resolved IP against private/reserved ranges
        foreach ($allRecords as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (!$ip) {
                continue;
            }

            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                \Craft::warning("Refusing URL {$url}: resolved IP {$ip} is in private/reserved range");
                return false;
            }
        }

        return true;
    }

    /**
     * Creates a Guzzle HTTP client with the given configuration.
     *
     * This is a test seam that can be overridden in integration tests
     * to inject mock clients or custom middleware.
     *
     * @param array $config
     * @return \GuzzleHttp\Client
     */
    protected function createHttpClient(array $config = []): \GuzzleHttp\Client
    {
        return Craft::createGuzzleClient($config);
    }

    /**
     * Performs a safe outbound HTTP request with pre-flight host validation,
     * redirect control, and optional response body size enforcement.
     *
     * @param string $method The HTTP method ('GET' or 'HEAD')
     * @param string $url The request URL
     * @param int $maxBodyBytes Max response body bytes (0 = no limit)
     * @param bool $trusted If true, skip the private/reserved-IP check on both the
     *                      pre-flight host and each redirect hop. Reserved for callers
     *                      that have already verified the source via `isTrustedSource()`.
     * @return \Psr\Http\Message\ResponseInterface
     * @throws \RuntimeException If the host is non-resolvable (and not trusted), a redirect
     *                          targets a private/reserved IP (and not trusted), or the
     *                          response body exceeds $maxBodyBytes.
     */
    protected function safeOutboundRequest(
        string $method,
        string $url,
        int $maxBodyBytes = 0,
        bool $trusted = false,
    ): \Psr\Http\Message\ResponseInterface {
        // Pre-flight host check (skipped when the caller has marked this source trusted)
        if (!$trusted && !$this->isResolvableUrl($url)) {
            throw new \RuntimeException("Refusing to fetch non-resolvable host: {$url}");
        }

        $options = [
            RequestOptions::CONNECT_TIMEOUT => 10,
            RequestOptions::TIMEOUT => 30,
        ];

        // Redirect handling
        if ($method === 'GET') {
            $options[RequestOptions::ALLOW_REDIRECTS] = [
                'max' => 5,
                'on_redirect' => function($request, $response, $uri) use ($trusted) {
                    if (!$trusted && !$this->isResolvableUrl((string) $uri)) {
                        throw new \RuntimeException("Redirect target is in private/reserved range: {$uri}");
                    }
                },
            ];
        } else {
            $options[RequestOptions::ALLOW_REDIRECTS] = false;
        }

        // Body size enforcement (only for GET with maxBodyBytes > 0)
        if ($maxBodyBytes > 0) {
            $options[RequestOptions::ON_HEADERS] = function(\Psr\Http\Message\ResponseInterface $response) use ($maxBodyBytes) {
                $contentLength = (int) $response->getHeaderLine('Content-Length');
                if ($contentLength > 0 && $contentLength > $maxBodyBytes) {
                    throw new \RuntimeException("Response Content-Length {$contentLength} exceeds {$maxBodyBytes} byte limit");
                }
            };
            $options[RequestOptions::STREAM] = true;
        }

        $client = $this->createHttpClient($options);

        if ($method === 'GET') {
            $response = $client->get($url);
        } else {
            $response = $client->head($url);
        }

        // Byte-counting sink for streamed responses
        if ($maxBodyBytes > 0 && $response->getBody()->isReadable()) {
            $body = $response->getBody();
            $accumulated = '';
            $count = 0;
            while (!$body->eof()) {
                $chunk = $body->read(8192);
                $count += strlen($chunk);
                if ($count > $maxBodyBytes) {
                    throw new \RuntimeException("Response body exceeded {$maxBodyBytes} byte limit after streaming {$count} bytes");
                }
                $accumulated .= $chunk;
            }
            // Replace body with accumulated string for downstream consumers
            $response = $response->withBody(Utils::streamFor($accumulated));
        }

        return $response;
    }

    /**
     * Normalize a URL for comparison
     *
     * @param string $url
     * @return string
     */
    public function normalizeUrl(string $url): string
    {
        $parsed = parse_url($url);
        if (!$parsed) {
            return $url;
        }

        $scheme = isset($parsed['scheme']) ? strtolower($parsed['scheme']) : 'http';
        $host = isset($parsed['host']) ? strtolower($parsed['host']) : '';
        $path = isset($parsed['path']) ? strtolower($parsed['path']) : '/';

        // Remove trailing slash
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        $normalized = $scheme . '://' . $host . $path;

        // Add port if present and not default
        if (isset($parsed['port'])) {
            if (($scheme === 'http' && $parsed['port'] !== 80) || ($scheme === 'https' && $parsed['port'] !== 443)) {
                $normalized = $scheme . '://' . $host . ':' . $parsed['port'] . $path;
            }
        }

        // Strip common tracking parameters before comparison
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $params);
            $trackingPrefixes = [
                'utm_',       // Google Analytics / general
                'fbclid',     // Facebook
                'gclid',      // Google Ads
                'gclsrc',     // Google Ads
                'dclid',      // Google Display & Video 360
                'gbraid',     // Google Ads (iOS)
                'wbraid',     // Google Ads (web-to-app)
                'mc_',        // Mailchimp
                'ck_',        // ConvertKit
                'msclkid',    // Microsoft/Bing Ads
                'twclid',     // Twitter/X
                'li_fat_id',  // LinkedIn
                'igshid',     // Instagram
                's_kwcid',    // Adobe Analytics
                'ttclid',     // TikTok
                '_hsenc',     // HubSpot
                '_hsmi',      // HubSpot
                'mc_cid',     // Mailchimp (caught by mc_ but being explicit)
                'mc_eid',     // Mailchimp
                'ss_',        // various email tools
                'vero_',      // Vero
                'oly_',       // Omeda
                'ref',        // general referrer param
            ];
            foreach (array_keys($params) as $key) {
                foreach ($trackingPrefixes as $prefix) {
                    if (str_starts_with(strtolower($key), $prefix)) {
                        unset($params[$key]);
                        break;
                    }
                }
            }
            if (!empty($params)) {
                $normalized .= '?' . http_build_query($params);
            }
        }

        return $normalized;
    }

    // Html::encode is wrong layer for URLs — it escapes HTML metacharacters but does NOT sanitize schemes
    public function safeUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/\s/', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        if (!preg_match('/^([a-zA-Z0-9.\-]+|\[[0-9a-fA-F:]+\])$/', $parts['host'])) {
            return null;
        }

        return $url;
    }

    /**
     * Returns true if the source URL's host matches an entry in the
     * `trustedSourceHosts` setting. Match is case-insensitive and covers
     * subdomains (e.g. `brid.gy` matches `fed.brid.gy` and `bsky.brid.gy`).
     */
    public function isTrustedSource(string $source): bool
    {
        $trusted = Plugin::getInstance()->settings->trustedSourceHosts;
        if (empty($trusted)) {
            return false;
        }

        $host = parse_url($source, PHP_URL_HOST);
        if (!$host) {
            return false;
        }
        $host = strtolower($host);

        foreach ($trusted as $trustedHost) {
            $trustedHost = strtolower(trim((string) $trustedHost));
            if ($trustedHost === '') {
                continue;
            }
            if ($host === $trustedHost || str_ends_with($host, '.' . $trustedHost)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true if the given IP has exceeded the per-hour submission rate
     * limit. Increments the counter on each call so the limit applies across
     * accepted, deduped, and rejected submissions alike.
     *
     * Submissions whose source host matches `trustedSourceHosts` (e.g. brid.gy)
     * bypass the limit so legitimate viral traffic isn't dropped. Layer 2
     * (failure backoff) still applies, so spoofed-trusted sources that fail
     * validation are bounded.
     *
     * Set `rateLimitPerHour` to 0 in plugin settings to disable.
     */
    public function isRateLimited(string $ip, string $source): bool
    {
        if ($this->isTrustedSource($source)) {
            return false;
        }

        $limit = Plugin::getInstance()->settings->rateLimitPerHour;
        if ($limit <= 0) {
            return false;
        }

        $cache = Craft::$app->cache;
        $key = 'webmention:rl:' . $ip;
        $count = (int) $cache->get($key);

        if ($count >= $limit) {
            return true;
        }

        $cache->set($key, $count + 1, 3600);
        return false;
    }

    /**
     * Returns true if a (source, target) pair has accumulated enough failures
     * that it should no longer be re-queued. Bumps the existing row's attempt
     * counter so continued abuse is visible in the failures CP without burning
     * an outbound HTTP fetch. Stale rows are cleared by CleanupController after
     * `failureRetentionDays`, giving the pair a fresh shot.
     *
     * Set `failureBackoffThreshold` to 0 in plugin settings to disable.
     */
    public function isFailureBackedOff(string $source, string $target): bool
    {
        $threshold = Plugin::getInstance()->settings->failureBackoffThreshold;
        if ($threshold <= 0) {
            return false;
        }

        $tableName = WebmentionFailure::tableName();
        $existing = (new Query())
            ->from($tableName)
            ->select(['id', 'attempts'])
            ->where(['source' => $source, 'target' => $target])
            ->one();

        if (!$existing || (int) $existing['attempts'] < $threshold) {
            return false;
        }

        $now = Db::prepareDateForDb(new DateTime());
        Craft::$app->db->createCommand()->update(
            $tableName,
            [
                'attempts' => new Expression('attempts + 1'),
                'lastAttemptedAt' => $now,
                'dateUpdated' => $now,
            ],
            ['id' => $existing['id']],
        )->execute();

        return true;
    }

    /**
     * Extract a Mastodon-style status identifier from a URL.
     *
     * Mastodon uses two URL formats for the same status:
     * - Canonical: https://instance/@user/123456
     * - Web client: https://instance/web/statuses/123456
     *
     * Returns "host:statusId" if the URL matches either pattern, or null otherwise.
     */
    private function extractMastodonStatusId(string $url): ?string
    {
        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host']) || empty($parsed['path'])) {
            return null;
        }

        // URLs with fragments (e.g. #favorited-by-..., #reblogged-by-...) are
        // derivative interactions (likes/reposts), not the original status
        if (!empty($parsed['fragment'])) {
            return null;
        }

        $host = strtolower($parsed['host']);
        $path = $parsed['path'];

        // Match /web/statuses/{id}
        if (preg_match('#^/web/statuses/(\d+)$#', $path, $m)) {
            return $host . ':' . $m[1];
        }

        // Match /@user/{id}
        if (preg_match('#^/@[^/]+/(\d+)$#', $path, $m)) {
            return $host . ':' . $m[1];
        }

        return null;
    }

    /**
     * Resolve a relative URL against a base URL
     *
     * @param string $url
     * @param string $base
     * @return string
     */
    public function resolveUrl(string $url, string $base): string
    {
        $uri = new Uri($url);
        if ($uri->getScheme() !== '') {
            return $url;
        }

        $baseUri = new Uri($base);
        return (string) UriResolver::resolve($baseUri, $uri);
    }

    /**
     * Find an h-entry in a list of mf2 items
     * @param array $items
     * @return array|null The h-entry item or null if none found
     */
    private function findHEntry(array $items)
    {
        foreach ($items as $item) {
            if (in_array('h-entry', $item['type'])) {
                return $item;
            }
            if (isset($item['children']) && is_array($item['children'])) {
                $found = $this->findHEntry($item['children']);
                if ($found) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * Parse HTML of a source and populate model
     *
     * @param string $html The HTML of the source
     * @param string $source The source URL
     * @param string $target The target URL
     * @return Webmention|false Webmention Model
     */
    public function parseWebmention(string $html, string $source, string $target): Webmention|false
    {
        // Parse the HTML with Mf2
        $parsed = Mf2\parse($html, $source);

        $entry = $this->findHEntry($parsed['items']);

        if (!isset($entry)) {
            throw new Exception("No h-entry found in source HTML.");
        }

        // Parse comment – with max text length from settings
        $settings = Plugin::getInstance()->settings;
        $maxLength = $settings->maxTextLength;
        $result = \IndieWeb\comments\parse($entry, $source, $maxLength, 100);

        if (empty($result)) {
            // probably spam
            throw new Exception('Could not parse comment.');
        }

        // Determine the type of the response
        $this->_checkResponseType($result, $entry, $source, $settings->useBridgy);

        // Get h-card and use data for author etc. if not present in h-entry
        $representative = Mf2\HCard\representative($parsed, $source);

        // If the source url doesn't give us a representative h-card, try to get one for author url from parsed html
        if (!$representative) {
            $representative = Mf2\HCard\representative($parsed, $result['author']['url']);

            // If this also doesn't work, maybe the h-card can be found in the parsed HTML directly
            if (!$representative) {
                foreach ($parsed['items'] as $item) {
                    if (in_array('h-card', $item['type'])) {
                        $representative = $item;
                    }
                }
            }
        }

        // If author name is empty use the one from the representative h-card
        if (empty($result['author']['name']) && $representative) {
            $result['author']['name'] = $representative['properties']['name'][0] ?? $representative['properties']['nickname'][0] ?? null;
        }
        // If author url is empty use the one from the representative h-card
        if (empty($result['author']['url']) && $representative) {
            $result['author']['url'] = $representative['properties']['url'][0] ?? null;
        }
        // If url is empty use source url
        if (empty($result['url'])) {
            $result['url'] = $source;
        }
        // Use domain if 'site' ∉ {twitter, facebook, googleplus, instagram, flickr}
        if (empty($result['site'])) {
            $result['site'] = parse_url($result['url'], PHP_URL_HOST);
        }

        // Author photo

        $authorPhotoUrl = null;
        $authorPhotoAlt = null;

        if (!empty($result['author']['photo'])) {
            if (is_array($result['author']['photo'])) {
                $authorPhotoUrl = $result['author']['photo']['value'] ?? null;
                $authorPhotoAlt = $result['author']['photo']['alt'] ?? null;
            } else {
                $authorPhotoUrl = $result['author']['photo'];
            }
        } elseif ($representative) {
            // Sometimes the structure of the parsed h-card differs
            $photo = $representative['properties']['photo'][0] ?? null;
            if ($photo && is_string($photo)) {
                // The photo can be the first element in ['photo']
                $authorPhotoUrl = $photo;
            } elseif (is_array($photo) && isset($photo['value']) && is_string($photo['value'])) {
                // Alternatively, the photo can be the ['value'] key of the array inside ['photo']
                $authorPhotoUrl = $photo['value'];
                $authorPhotoAlt = $photo['alt'] ?? null;
            } else {
                // If no author photo is defined, check gravatar for image
                $email = $representative['properties']['email'][0] ?? null;
                if ($email) {
                    $email = rtrim(str_replace('mailto:', '', $email));
                    $gravatar = $this->_get_gravatar($email);
                    $authorPhotoUrl = $gravatar . ".jpg";
                }
            }
        }

        // Bluesky author fallback via public API.
        // Bridgy Fed converts Bluesky posts to HTML but strips all h-card data, so mf2 parsing
        // yields no author. If we still have no name and the URL points to Bluesky/Bridgy Fed,
        // look up the author via the public AT Protocol API using the DID embedded in the URL.
        if (empty($result['author']['name'])) {
            $entryUrl = $result['url'] ?? $source;
            $isBridgyFed = str_contains($source, 'bsky.brid.gy');
            $isBlueskyUrl = str_contains($entryUrl, 'bsky.app');

            if ($isBridgyFed || $isBlueskyUrl) {
                $blueskyAuthor = $this->fetchBlueskyAuthor($entryUrl);
                if ($blueskyAuthor) {
                    $result['author']['name'] = $result['author']['name'] ?: $blueskyAuthor['name'];
                    $result['author']['url'] = $result['author']['url'] ?: $blueskyAuthor['url'];
                    $authorPhotoUrl = $authorPhotoUrl ?: $blueskyAuthor['photo'];
                }
            }
        }

        // Author photo should be saved locally to avoid exploits.
        // If an author photo is available get the image and save it to assets.
        // Trust extends to avatar URLs so self-hosted senders on private networks
        // (whose avatar lives at the same private host as the source) still work.
        if ($authorPhotoUrl) {
            $asset = $this->saveAsset($authorPhotoUrl, $authorPhotoAlt, $this->isTrustedSource($source));
            if ($asset) {
                $result['author']['avatarId'] = $asset->id;
            }
        }

        // Check if webmention for combination of src and target exists
        $targetElement = $this->getTargetElement($target);
        if ($targetElement) {
            $model = Webmention::find()
                ->targetId($targetElement->id)
                ->targetSiteId($targetElement::isLocalized() ? $targetElement->siteId : null)
                ->source($source)
                ->one();
        } else {
            $model = Webmention::find()
                ->target($target)
                ->source($source)
                ->one();
        }

        if (!$model) {
            // create new webmention
            $model = new Webmention();
        }

        // Purify the text with Yii's HTMLPurifier wrapper
        $text = LitEmoji::encodeUnicode($result['text']);
        $text = HtmlPurifier::process($text, [
            'URI.AllowedSchemes' => [
                'http' => true,
                'https' => true,
            ],
        ]);

        // assign attributes
        $model->authorName = Html::encode($result['author']['name'] ?? null);
        $model->avatarId = $result['author']['avatarId'] ?? null;
        $model->authorUrl = $this->safeUrl($result['author']['url'] ?? null);
        $model->published = isset($result['published']) ? new DateTime($result['published']) : null;
        $model->name = Html::encode(mb_substr($result['name'] ?? '', 0, 255));
        $model->text = $text;
        $model->target = $this->safeUrl($target);
        $model->targetId = $targetElement?->id;
        $model->targetSiteId = $targetElement && $targetElement->isLocalized() ? $targetElement->siteId : null;
        $model->source = $this->safeUrl($source);
        $model->hEntryUrl = $this->safeUrl($result['url'] ?? null);
        $model->host = $result['site'] ?? null;
        $model->type = $result['type'] ?? null;
        $model->properties = $entry['properties'];

        // Resolve parent webmention from in-reply-to
        $model->parentId = $this->resolveParentWebmention($entry['properties'], $target, $model->id);

        return $model;
    }

    /**
     * Downloads a remote avatar image and saves it as a Craft asset in the configured avatar folder.
     *
     * If an asset with the same hashed filename already exists, it is returned immediately
     * without re-downloading or re-saving, ensuring that multiple webmentions from the same
     * author reuse the same avatar asset.
     *
     * The temp file is written to a unique path to prevent race conditions when concurrent
     * queue jobs process webmentions from the same author simultaneously.
     *
     * Guards against empty/truncated responses, query strings in avatar URLs polluting the
     * file extension, and cleanImage() failures that can destroy the temp file.
     *
     * @param string $url The remote URL of the avatar image
     * @param string|null $alt Optional alt text for the asset
     * @return Asset|null The saved or existing asset, or null on failure
     */
    /**
     * Resolves avatar file extension using MIME-first detection with allowlist validation.
     *
     * @param \Psr\Http\Message\ResponseInterface $response The HTTP response
     * @param string $url The avatar URL
     * @param string $body The response body
     * @return string|null The resolved extension (lowercase) or null if rejected
     */
    protected function resolveAvatarExtension(\Psr\Http\Message\ResponseInterface $response, string $url, string $body): ?string
    {
        $allowlist = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'heic', 'heif'];

        // 1. Check Content-Type header first
        $contentType = $response->getHeaderLine('Content-Type');
        if ($contentType && str_starts_with($contentType, 'image/')) {
            $ext = FileHelper::getExtensionByMimeType($contentType);
            if ($ext && in_array(strtolower($ext), $allowlist, true)) {
                return strtolower($ext);
            }
        }

        // 2. Fallback to URL path extension
        $urlPath = parse_url($url, PHP_URL_PATH) ?: $url;
        $urlExt = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
        if ($urlExt && in_array($urlExt, $allowlist, true)) {
            return $urlExt;
        }

        // 3. Final fallback: sniff body bytes with finfo
        if (!empty($body)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($body);
            if ($mimeType && str_starts_with($mimeType, 'image/')) {
                $ext = FileHelper::getExtensionByMimeType($mimeType);
                if ($ext && in_array(strtolower($ext), $allowlist, true)) {
                    return strtolower($ext);
                }
            }
        }

        // 4. If we have a non-image content type, reject
        if ($contentType && !str_starts_with($contentType, 'image/')) {
            Craft::warning("Avatar content type '{$contentType}' is not an image for {$url}", __METHOD__);
            return null;
        }

        // 5. Default to jpg if nothing else worked
        return 'jpg';
    }

    private function saveAsset(string $url, ?string $alt = null, bool $trusted = false): ?Asset
    {
        $folder = $this->getAvatarFolder();
        if (!$folder) {
            return null;
        }

        $hashedFileName = sha1($url);

        try {
            $response = $this->safeOutboundRequest('GET', $url, \matthiasott\webmention\models\Settings::MAX_AVATAR_BODY_SIZE, $trusted);
        } catch (\Throwable $e) {
            Craft::warning("Avatar download failed for {$url}: {$e->getMessage()}", __METHOD__);
            return null;
        }

        $body = (string) $response->getBody();

        if (strlen($body) < 100) {
            Craft::warning(sprintf(
                'Avatar response too small (%d bytes) for %s (HTTP %d, Content-Type: %s)',
                strlen($body),
                $url,
                $response->getStatusCode(),
                $response->getHeaderLine('Content-Type')
            ), __METHOD__);
            return null;
        }

        $fileExtension = $this->resolveAvatarExtension($response, $url, $body);
        if ($fileExtension === null) {
            Craft::warning("Avatar extension not in allowlist for {$url}", __METHOD__);
            return null;
        }

        $fileName = $hashedFileName . '.' . $fileExtension;

        // Re-use existing asset if already saved
        $existing = Asset::find()
            ->folderId($folder->id)
            ->filename($fileName)
            ->one();

        if ($existing) {
            return $existing;
        }

        // Unique temp path to avoid race conditions between concurrent jobs
        $tempPath = sprintf(
            '%s/%s',
            Craft::$app->path->getTempPath(),
            uniqid($hashedFileName . '_', true) . '.' . $fileExtension
        );
        FileHelper::writeToFile($tempPath, $body);

        // Sanitize SVG files before saving to the asset volume
        if ($fileExtension === 'svg') {
            $sanitizer = new \enshrined\svgSanitize\Sanitizer();
            $rawSvg = file_get_contents($tempPath);
            $cleanSvg = $sanitizer->sanitize($rawSvg);
            if ($cleanSvg === false || $cleanSvg === '') {
                Craft::warning("SVG sanitization failed or produced empty output for {$url}", __METHOD__);
                @unlink($tempPath);
                return null;
            }
            file_put_contents($tempPath, $cleanSvg);
        }

        $ext = strtolower(pathinfo($tempPath, PATHINFO_EXTENSION));
        if (Image::canManipulateAsImage($ext) && $ext !== 'svg') {
            try {
                Craft::$app->images->cleanImage($tempPath);
            } catch (\Throwable $e) {
                Craft::warning(
                    "cleanImage() failed for {$tempPath}: {$e->getMessage()}",
                    __METHOD__
                );
                if (!file_exists($tempPath) || filesize($tempPath) === 0) {
                    FileHelper::writeToFile($tempPath, $body);
                }
            }
        }

        $asset = new Asset();
        $asset->tempFilePath = $tempPath;
        $asset->newFilename = $fileName;
        $asset->newFolderId = $folder->id;
        $asset->avoidFilenameConflicts = true;
        if ($alt) {
            $asset->alt = $alt;
        }

        if (Craft::$app->elements->saveElement($asset)) {
            return $asset;
        }

        Craft::warning(
            'Couldn\'t save avatar asset: ' . implode(', ', $asset->getFirstErrors()),
            __METHOD__
        );
        return null;
    }

    /**
     * Returns the volume folder used to store avatars.
     *
     * @return VolumeFolder|null
     * @throws InvalidConfigException if the `avatarVolume` setting is set to an invalid volume handle
     */
    public function getAvatarFolder(): ?VolumeFolder
    {
        $settings = Plugin::getInstance()->settings;
        if (!$settings->avatarVolume) {
            return null;
        }

        $volume = Craft::$app->volumes->getVolumeByUid($settings->avatarVolume);
        if (!$volume) {
            throw new InvalidConfigException("Invalid volume UUID: $settings->avatarVolume");
        }

        $avatarPath = trim($settings->avatarPath, '/\\');
        if ($avatarPath === '') {
            return Craft::$app->assets->getRootFolderByVolumeId($volume->id);
        }

        return Craft::$app->assets->ensureFolderByFullPathAndVolume($avatarPath, $volume);
    }

    /**
     * Returns a target element by its URL.
     *
     * @param string $url
     * @return ElementInterface|null
     */
    public function getTargetElement(string $url): ?ElementInterface
    {
        $parsedTarget = $this->parseUrl($url);
        if (!$parsedTarget) {
            return null;
        }

        $sitesService = Craft::$app->sites;
        $sites = Collection::make($sitesService->getAllSites(false))
            ->keyBy(fn(Site $site) => $site->id)
            ->map(function(Site $site) {
                $baseUrl = $site->getBaseUrl();
                $parsedBaseUrl = $baseUrl ? $this->parseUrl($baseUrl) : null;
                return [$site, $parsedBaseUrl];
            })
            ->all();

        $elementsService = Craft::$app->getElements();

        $siteInfo = $this->matchTargetSite($parsedTarget, $sites);
        if ($siteInfo) {
            [$site, $parsedSiteUrl] = $siteInfo;
        } else {
            $site = $sitesService->getPrimarySite();
            $parsedSiteUrl = $sites[$site->id] ?? null;
        }

        // figure out the entry URI relative to the site base path
        $uri = $parsedTarget['path'];
        if ($parsedSiteUrl && $parsedSiteUrl['path'] && str_starts_with("{$parsedTarget['path']}/", "{$parsedSiteUrl['path']}/")) {
            $uri = substr($uri, strlen($parsedSiteUrl['path']) + 1);
        }

        return $elementsService->getElementByUri($uri, $site->id);
    }

    /**
     * @param array $parsedTarget
     * @param array{0:Site,1:array}[] $sites
     * @return array|null
     */
    private function matchTargetSite(array $parsedTarget, array $sites): ?array
    {
        if (empty($sites)) {
            return null;
        }

        $scores = [];
        foreach ($sites as $i => $site) {
            $scores[$i] = $this->scoreSiteForTarget($site, $parsedTarget);
        }

        // Sort by scores descending
        arsort($scores, SORT_NUMERIC);
        return $sites[array_key_first($scores)];
    }

    /**
     * @param array{0:Site,1:array} $site
     * @param array $parsedTarget
     */
    private function scoreSiteForTarget(array $site, array $parsedTarget): int
    {
        if ($site[1]) {
            $score = $this->scoreSiteUrlForTarget($site[1], $parsedTarget);
        } else {
            $score = 0;
        }

        if ($site[0]->primary) {
            // One more point in case we need a tiebreaker
            $score++;
        }

        return $score;
    }

    private function scoreSiteUrlForTarget(array $parsedSiteUrl, array $parsedTarget): int
    {
        // Does the site URL specify a host name?
        if (
            !empty($parsedSiteUrl['host']) &&
            !empty($parsedTarget['host']) &&
            $parsedSiteUrl['host'] !== $parsedTarget['host'] &&
            (
                !App::supportsIdn() ||
                !defined('IDNA_NONTRANSITIONAL_TO_ASCII') ||
                idn_to_ascii($parsedSiteUrl['host'], IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46) !== $parsedTarget['host']
            )
        ) {
            return 0;
        }

        // Does the site URL specify a base path?
        if (
            !str_starts_with("{$parsedTarget['path']}/", "{$parsedSiteUrl['path']}/")
        ) {
            return 0;
        }

        // It's a possible match!
        return 1000 + strlen($parsedSiteUrl['path'] ?? '') * 100;
    }

    private function parseUrl(string $url): ?array
    {
        $parsed = parse_url($url);
        if (!$parsed) {
            return null;
        }
        $parsed['path'] = preg_replace('/\/\/+/', '/', trim($parsed['path'] ?? '', '/'));
        return $parsed;
    }

    /**
     * Sync all existing entry types with settings
     *
     * @return boolean
     *
     */
    public function syncEntryTypes()
    {
        $settings = Plugin::getInstance()->settings;

        // get all existing entry types
        $entryTypesExisting = [];

        foreach (Craft::$app->entries->getAllEntryTypes() as $entryType) {
            $entryTypesExisting[$entryType->uid] = [
                'checked' => true,
                'label' => $entryType->name,
                'handle' => $entryType->handle,
            ];
        }

        // now diff and sync settings with existing entry types
        foreach (array_keys($settings->entryTypes) as $uid) {
            if (!array_key_exists($uid, $entryTypesExisting)) {
                unset($settings->entryTypes[$uid]);
            }
        }

        foreach (array_keys($entryTypesExisting) as $uid) {
            if (!array_key_exists($uid, $settings->entryTypes)) {
                $settings->entryTypes[$uid] = ['checked' => true, 'label' => $entryTypesExisting[$uid]['label'], 'handle' => $uid];
            }
        }

        // save new settings
        return Craft::$app->plugins->savePluginSettings(Plugin::getInstance(), $settings->toArray());
    }

    /**
     * Prepare sending of Webmentions for an entry on save and add them to task queue
     *
     * @param ModelEvent $event Craft's onSaveEntry event
     */
    public function onSaveEntry(ModelEvent $event): void
    {
        /** @var Entry $entry */
        $entry = $event->sender;

        if (
            $entry->propagating ||
            $entry->getIsDraft() ||
            $entry->getIsRevision() ||
            $entry->getStatus() !== Entry::STATUS_LIVE
        ) {
            return;
        }

        $url = $entry->getUrl();
        if (!$url) {
            return;
        }

        $entryType = $entry->getType();

        // check if Webmention sending is allowed for this entry type (CP settings)
        $settings = Plugin::getInstance()->settings;
        $checked = $settings->entryTypes[$entryType->uid]['checked'] ?? false;
        if (!$checked) {
            return;
        }

        $sendWebmentions = false;

        // check if entry has Webmention sending disabled (overrides entry type settings from the CP)
        foreach ($entry->getFieldLayout()->getCustomFields() as $field) {
            if ($field instanceof WebmentionSwitch) {
                $sendWebmentions = (bool) $entry->getFieldValue($field->handle);
                break;
            }
        }

        if (!$sendWebmentions) {
            return;
        }

        $targets = [];
        $this->extractTargets($entry->title, $targets);
        $this->extractTargets($entry->getSerializedFieldValues(), $targets);

        // Add all targets to the queue
        foreach (array_keys($targets) as $target) {
            Queue::push(new SendWebmention([
                'source' => $url,
                'target' => $target,
            ]));
        }
    }

    /**
     * Extract valid URLs from a given string
     *
     * @param mixed $value
     * @param array<string,bool> &$targets
     */
    private function extractTargets(mixed $value, array &$targets): void
    {
        if (is_string($value)) {
            // ReDoS-hardened version of https://regex101.com/r/p2nCxk/1. Now: https://regex101.com/r/ld9Xd7/1
            // `%` is removed from the path bare character class so `%XY` is unambiguous
            // (it must match the `%[0-9a-fA-F]{2}` alternative — bare `%` in URL paths
            // is invalid per RFC 3986 anyway). This kills the exponential-parsing root
            // cause without touching the markdown-link balancing on Branch A, which
            // depends on standard greedy backtracking against the trailing lookahead.
            preg_match_all("/(?<=\]\()(?:https?|ftp):\/\/(?:(?:(?:[a-zA-Z0-9$\-_.+!*\'(),]|%[0-9a-fA-F]{2})+(?::(?:[a-zA-Z0-9$\-_.+!*\'(),]|%[0-9a-fA-F]{2})*)?@)?(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)|(?:(?:[a-zA-Z0-9$\-_.+!*\'(),]|%[0-9a-fA-F]{2})+\.)+(?:[a-zA-Z]{2,}))(?::\d{1,5})?(?:(?:[\/?#](?:[a-zA-Z0-9$\-_.+!*\'(),;\/?:@&=#]|%[0-9a-fA-F]{2})+)(?=(?<!\()\)))|(?:https?|ftp):\/\/(?:(?:(?:[a-zA-Z0-9$\-_.+!*\'(),]|%[0-9a-fA-F]{2})+(?::(?:[a-zA-Z0-9$\-_.+!*\'(),]|%[0-9a-fA-F]{2})*)?@)?(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)|(?:(?:[a-zA-Z0-9$\-_.+!*\'(),]|%[0-9a-fA-F]{2})+\.)+(?:[a-zA-Z]{2,}))(?::\d{1,5})?(?:(?:[\/?#](?:[a-zA-Z0-9$\-_.+!*\'(),;\/?:@&=#]|%[0-9a-fA-F]{2})*))/uix", $value, $urls);

            $urls = array_unique(array_map('html_entity_decode', $urls[0]));

            foreach ($urls as $url) {
                $targets[$url] = true;
            }
        } elseif (is_iterable($value)) {
            foreach ($value as $v) {
                $this->extractTargets($v, $targets);
            }
        }
    }

    /**
     * Resolve a parent webmention ID from in-reply-to URLs in mf2 properties.
     *
     * @param array $properties The mf2 properties array
     * @param string $target The target URL of the current webmention
     * @param int|null $selfId The ID of the current webmention (to avoid self-reference)
     * @return int|null The parent webmention ID, or null if none found
     */
    private function resolveParentWebmention(array $properties, string $target, ?int $selfId = null): ?int
    {
        if (empty($properties['in-reply-to'])) {
            return null;
        }

        $replyToUrls = $this->extractInReplyToUrls($properties['in-reply-to']);
        $normalizedTarget = $this->normalizeUrl($target);

        foreach ($replyToUrls as $replyUrl) {
            $normalized = $this->normalizeUrl($replyUrl);

            // Skip if it points to the target post itself — that's a top-level comment
            if ($normalized === $normalizedTarget) {
                continue;
            }

            // Try to find a matching webmention by hEntryUrl or source
            $parent = Webmention::find()
                ->where([
                    'or',
                    ['webmentions.hEntryUrl' => $replyUrl],
                    ['webmentions.source' => $replyUrl],
                ])
                ->one();

            if ($parent && $parent->id !== $selfId) {
                return $parent->id;
            }

            // Fallback: match by Mastodon status ID (handles /web/statuses/{id} vs /@user/{id})
            $statusId = $this->extractMastodonStatusId($replyUrl);
            if ($statusId) {
                $parsed = parse_url($replyUrl);
                $host = strtolower($parsed['host'] ?? '');
                $id = explode(':', $statusId)[1];

                $parent = Webmention::find()
                    ->where(['like', 'webmentions.hEntryUrl', $host . '/@%/' . $id, false])
                    ->one();

                if (!$parent) {
                    $parent = Webmention::find()
                        ->where(['like', 'webmentions.hEntryUrl', $host . '/web/statuses/' . $id, false])
                        ->one();
                }

                if ($parent && $parent->id !== $selfId) {
                    return $parent->id;
                }
            }

            // Fallback: match by Bluesky post rkey. Bridgy stores hEntryUrl as either
            // /profile/{did}/post/{rkey} or /profile/{handle}/post/{rkey}, but the
            // at:// conversion above always produces the DID form, so a direct equality
            // lookup misses handle-based parents.
            $rkey = $this->extractBlueskyPostRkey($replyUrl);
            if ($rkey) {
                $parent = Webmention::find()
                    ->where(['like', 'webmentions.hEntryUrl', '%/post/' . $rkey, false])
                    ->one();

                if ($parent && $parent->id !== $selfId) {
                    return $parent->id;
                }
            }
        }

        return null;
    }

    /**
     * After saving a new webmention, check if any existing webmentions
     * have an in-reply-to pointing to this webmention's source or hEntryUrl.
     * If so, update their parentId (reverse resolution).
     */
    public function resolveChildWebmentions(Webmention $webmention): void
    {
        $urls = array_filter([$webmention->source, $webmention->hEntryUrl]);
        if (empty($urls)) {
            return;
        }

        // Find webmentions that might be replies to this one
        $candidates = Webmention::find()
            ->where(['webmentions.parentId' => null])
            ->andWhere(['not', ['webmentions.properties' => null]])
            ->all();

        foreach ($candidates as $candidate) {
            if ($candidate->id === $webmention->id) {
                continue;
            }

            if (empty($candidate->properties['in-reply-to'])) {
                continue;
            }

            $replyToUrls = $this->extractInReplyToUrls($candidate->properties['in-reply-to']);
            $normalizedTarget = !empty($candidate->target) ? $this->normalizeUrl($candidate->target) : '';

            foreach ($replyToUrls as $replyUrl) {
                $normalized = $this->normalizeUrl($replyUrl);

                // Skip if it points to the candidate's own target post
                if ($normalized === $normalizedTarget) {
                    continue;
                }

                foreach ($urls as $url) {
                    if ($normalized === $this->normalizeUrl($url)) {
                        $candidate->parentId = $webmention->id;
                        Craft::$app->elements->saveElement($candidate);
                        break 2;
                    }

                    // Fallback: match by Mastodon status ID
                    $replyStatusId = $this->extractMastodonStatusId($replyUrl);
                    $urlStatusId = $this->extractMastodonStatusId($url);
                    if ($replyStatusId && $urlStatusId && $replyStatusId === $urlStatusId) {
                        $candidate->parentId = $webmention->id;
                        Craft::$app->elements->saveElement($candidate);
                        break 2;
                    }

                    // Fallback: match by Bluesky post rkey (DID-vs-handle URL variations)
                    $replyRkey = $this->extractBlueskyPostRkey($replyUrl);
                    $urlRkey = $this->extractBlueskyPostRkey($url);
                    if ($replyRkey && $urlRkey && $replyRkey === $urlRkey) {
                        $candidate->parentId = $webmention->id;
                        Craft::$app->elements->saveElement($candidate);
                        break 2;
                    }
                }
            }
        }
    }

    /**
     * Extract URLs from an mf2 in-reply-to array.
     *
     * @param array $inReplyTo
     * @return string[]
     */
    private function extractInReplyToUrls(array $inReplyTo): array
    {
        $urls = [];
        foreach ($inReplyTo as $item) {
            $value = is_string($item)
                ? $item
                : (isset($item['value']) && is_string($item['value']) ? $item['value'] : null);

            if ($value === null) {
                continue;
            }

            if (str_starts_with($value, 'at://')) {
                $converted = $this->atUriToBlueskyUrl($value);
                if ($converted) {
                    $urls[] = $converted;
                }
                continue;
            }

            if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
                $urls[] = $value;
            }
        }
        return $urls;
    }

    /**
     * Converts an ATProto `at://` URI for a Bluesky post into its public
     * `bsky.app` HTTPS URL. Returns null for anything that isn't a feed post,
     * which is correct — non-post records can't be parents in a reply thread.
     */
    private function atUriToBlueskyUrl(string $atUri): ?string
    {
        if (!preg_match('#^at://(did:[^/]+)/app\.bsky\.feed\.post/([^/]+)$#', $atUri, $matches)) {
            return null;
        }
        return 'https://bsky.app/profile/' . $matches[1] . '/post/' . $matches[2];
    }

    /**
     * Extract the rkey from a Bluesky post URL. The rkey (a TID generated by
     * the author's PDS) uniquely identifies the post regardless of whether
     * Bridgy stored the URL with the user's DID or their handle in the
     * `/profile/` segment.
     */
    private function extractBlueskyPostRkey(string $url): ?string
    {
        if (preg_match('#^https?://bsky\.app/profile/[^/]+/post/([A-Za-z0-9]+)$#', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Returns webmentions for an element organized as a threaded tree.
     * Top-level webmentions (parentId = null) are returned with their
     * children populated recursively.
     *
     * @param ElementInterface $element
     * @return Webmention[]
     */
    public function getThreadedWebmentionsForElement(ElementInterface $element): array
    {
        $all = $this->getWebmentionsForElement($element);
        return $this->buildThread($all);
    }

    /**
     * Build a threaded tree from a flat list of webmentions.
     *
     * @param Webmention[] $webmentions
     * @return Webmention[] Top-level webmentions with children populated
     */
    public function buildThread(array $webmentions): array
    {
        $byId = [];
        foreach ($webmentions as $wm) {
            $wm->children = [];
            $byId[$wm->id] = $wm;
        }

        $roots = [];
        foreach ($webmentions as $wm) {
            if ($wm->parentId && isset($byId[$wm->parentId])) {
                $byId[$wm->parentId]->children[] = $wm;
            } else {
                $roots[] = $wm;
            }
        }

        return $roots;
    }

    /**
     * Records or updates a failure entry for a webmention that could not be processed.
     * If a record already exists for the same source+target, increments attempts and updates the error.
     */
    public function recordFailure(string $source, string $target, \Throwable $e): void
    {
        $tableName = WebmentionFailure::tableName();
        $now = Db::prepareDateForDb(new \DateTime());

        $existing = (new Query())
            ->from($tableName)
            ->where(['source' => $source, 'target' => $target])
            ->one();

        // Redact server paths from trace and message before storing
        $trace = mb_substr($e->getTraceAsString(), 0, 65535);
        $trace = str_replace(
            [Craft::getAlias('@root'), dirname(__DIR__, 2)],
            ['[craft]', '[plugin]'],
            $trace
        );

        $message = mb_substr($e->getMessage(), 0, 65535);
        $message = str_replace(
            [Craft::getAlias('@root'), dirname(__DIR__, 2)],
            ['[craft]', '[plugin]'],
            $message
        );

        if ($existing) {
            Craft::$app->db->createCommand()->update($tableName, [
                'errorMessage' => $message,
                'errorTrace' => $trace,
                'attempts' => $existing['attempts'] + 1,
                'lastAttemptedAt' => $now,
                'dateUpdated' => $now,
            ], ['id' => $existing['id']])->execute();
        } else {
            Craft::$app->db->createCommand()->insert($tableName, [
                'source' => $source,
                'target' => $target,
                'errorMessage' => $message,
                'errorTrace' => $trace,
                'attempts' => 1,
                'lastAttemptedAt' => $now,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }
    }

    /**
     * Deletes any existing failure record for the given source+target pair.
     * Called after a previously-failing webmention is successfully processed.
     */
    public function deleteFailure(string $source, string $target): void
    {
        Craft::$app->db->createCommand()
            ->delete(WebmentionFailure::tableName(), ['source' => $source, 'target' => $target])
            ->execute();
    }

    /**
     * Extracts a Bluesky DID from any URL that contains one.
     * Matches both did:plc: and did:web: methods.
     */
    private function extractBlueskyDid(string $url): ?string
    {
        if (preg_match('/did:(plc|web):[a-zA-Z0-9._:%-]+/', $url, $matches)) {
            return $matches[0];
        }
        return null;
    }

    /**
     * Fetches Bluesky author data from the public AT Protocol API using a DID
     * extracted from the given URL. Returns an array with 'name', 'url', and
     * 'photo' keys, or null if no DID could be found or the API call fails.
     * This is a best-effort fallback — it never throws.
     */
    private function fetchBlueskyAuthor(string $url): ?array
    {
        $did = $this->extractBlueskyDid($url);
        if ($did === null) {
            return null;
        }

        try {
            $client = Craft::createGuzzleClient();
            $response = $client->get('https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile', [
                RequestOptions::QUERY => ['actor' => $did],
                RequestOptions::CONNECT_TIMEOUT => 5,
                RequestOptions::TIMEOUT => 10,
            ]);

            $data = json_decode((string)$response->getBody(), true);
            if (!is_array($data) || empty($data['handle'])) {
                return null;
            }

            $handle = $data['handle'];
            $displayName = !empty($data['displayName']) ? $data['displayName'] : $handle;

            return [
                'name' => $displayName,
                'url' => 'https://bsky.app/profile/' . $handle,
                'photo' => $data['avatar'] ?? null,
            ];
        } catch (\Throwable $e) {
            Craft::warning('Bluesky author API fallback failed for DID ' . $did . ': ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }
}
