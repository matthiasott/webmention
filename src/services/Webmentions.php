<?php

namespace matthiasott\webmention\services;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\events\ModelEvent;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\helpers\Html;
use craft\helpers\HtmlPurifier;
use craft\helpers\Image;
use craft\helpers\Queue;
use craft\models\Site;
use craft\models\VolumeFolder;
use DateTime;
use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Collection;
use LitEmoji\LitEmoji;
use matthiasott\webmention\elements\Webmention;
use matthiasott\webmention\fields\WebmentionSwitch;
use matthiasott\webmention\jobs\SendWebmention;
use matthiasott\webmention\Plugin;
use Mf2;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidConfigException;

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
            if (preg_match('/facebook/', $src)) {
                $result['site'] = 'facebook';
            }
            if (preg_match('/flickr/', $src)) {
                $result['site'] = 'flickr';
            }
            if (preg_match('/github/', $src)) {
                $result['site'] = 'github';
            }
            if (preg_match('/instagram/', $src)) {
                $result['site'] = 'instagram';
            }
            if (preg_match('/mastodon/', $src)) {
                $result['site'] = 'mastodon';
            }
            if (preg_match('/bluesky/', $src)) {
                $result['site'] = 'bluesky';
            }
            if (preg_match('/reddit/', $src)) {
                $result['site'] = 'reddit';
            }

            // Get the type of mention from Bridgy's webmention source URLs
            if (preg_match('/post/', $src)) {
                $result['type'] = 'mention';
            } elseif (preg_match('/comment/', $src)) {
                $result['type'] = 'comment';
            } elseif (preg_match('/like/', $src)) {
                $result['type'] = 'like';
            } elseif (preg_match('/repost/', $src)) {
                $result['type'] = 'repost';
            } elseif (preg_match('/rsvp/', $src)) {
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

        // Get HTML content
        $client = Craft::createGuzzleClient();
        try {
            Craft::info('Fetching source URL: ' . $src, 'webmention');
            $response = $client->get($src, [
                RequestOptions::CONNECT_TIMEOUT => 10,
                RequestOptions::TIMEOUT => 30,
            ]);
        } catch (GuzzleException $e) {
            Craft::info('Failed to fetch source URL: ' . $e->getMessage(), 'webmention');
            return false;
        }
        $html = (string) $response->getBody();
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
            $linkUrl = $link->getAttribute('href');
            $resolvedUrl = $this->resolveUrl($linkUrl, $src);

            if ($this->normalizeUrl($resolvedUrl) === $normalizedTarget) {
                return $html;
            }
            $linkUrls[] = $resolvedUrl;
        }

        // Only check for redirects if we haven't found a direct match, and limit the number of HEAD requests
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
                $resolvedRedirect = $this->resolveUrl($redirect, $linkUrl);
                if ($this->normalizeUrl($resolvedRedirect) === $normalizedTarget) {
                    return $html;
                }
            }
        }

        return false;
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

        // Add query if present
        if (isset($parsed['query'])) {
            $normalized .= '?' . $parsed['query'];
        }

        return $normalized;
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

        // Author photo should be saved locally to avoid exploits.
        // If an author photo is available get the image and save it to assets
        if ($authorPhotoUrl) {
            $asset = $this->saveAsset($authorPhotoUrl, $authorPhotoAlt);
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
        $model->authorUrl = Html::encode($result['author']['url'] ?? null);
        $model->published = isset($result['published']) ? new DateTime($result['published']) : null;
        $model->name = Html::encode($result['name'] ?? null);
        $model->text = $text;
        $model->target = Html::encode($target);
        $model->targetId = $targetElement?->id;
        $model->targetSiteId = $targetElement && $targetElement->isLocalized() ? $targetElement->siteId : null;
        $model->source = Html::encode($source);
        $model->hEntryUrl = Html::encode($result['url'] ?? null);
        $model->host = $result['site'] ?? null;
        $model->type = $result['type'] ?? null;
        $model->properties = $entry['properties'];

        return $model;
    }



    private function saveAsset(string $url, ?string $alt = null): ?Asset
    {
        $folder = $this->getAvatarFolder();
        if (!$folder) {
            return null;
        }

        // get remote image and store in temp path with a hashed filename
        $client = Craft::createGuzzleClient();
        try {
            $response = $client->get($url, [
                RequestOptions::CONNECT_TIMEOUT => 10,
                RequestOptions::TIMEOUT => 30,
            ]);
        } catch (GuzzleException) {
            return null;
        }

        $body = (string) $response->getBody();

        $hashedFileName = sha1($url);

        $fileExtension = (pathinfo($url, PATHINFO_EXTENSION));

        if (empty($fileExtension)) {
            // First try to get MIME type from Content-Type header
            $mimeType = $response->getHeaderLine('Content-Type');

            if ($mimeType && str_starts_with($mimeType, 'image/')) {
                $fileExtension = FileHelper::getExtensionByMimeType($mimeType);
            }

            // Fallback: detect from image content (already downloaded)
            if (empty($fileExtension)) {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($body);
                if ($mimeType && str_starts_with($mimeType, 'image/')) {
                    $fileExtension = FileHelper::getExtensionByMimeType($mimeType);
                }
            }

            // Last resort: assume JPG
            if (empty($fileExtension)) {
                $fileExtension = "jpg";
            }
        }

        $fileName = $hashedFileName . "." . $fileExtension;

        $tempPath = sprintf('%s/%s', Craft::$app->path->getTempPath(), $fileName);
        FileHelper::writeToFile($tempPath, $body);

        // If it's an image, cleanse it of any malicious scripts that may be embedded
        // (recommended unless you completely trust everyone that's uploading images)
        $ext = strtolower(pathinfo($tempPath, PATHINFO_EXTENSION));
        if (Image::canManipulateAsImage($ext) && $ext !== 'svg') {
            Craft::$app->images->cleanImage($tempPath);
        }

        // Save avatar to asset folder
        $asset = new Asset();
        $asset->tempFilePath = $tempPath;
        $asset->newFilename = $fileName;
        $asset->newFolderId = $folder->id;
        $asset->avoidFilenameConflicts = true;
        if ($alt) {
            $asset->alt = $alt;
        }

        if (!Craft::$app->elements->saveElement($asset)) {
            Craft::warning(sprintf('Couldn\'t save avatar asset: %s', implode(', ', $asset->getFirstErrors())), __METHOD__);
            return null;
        }

        return $asset;
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
            // https://regex101.com/r/p2nCxk/1
            preg_match_all("/(?<=\]\()(?:https?|ftp):\/\/(?:(?:(?:[a-zA-Z0-9$\-_.+!*\'(),]|%[0-9a-fA-F]{2})+(?::(?:[a-zA-Z0-9$\-_.+!*\'(),]|%[0-9a-fA-F]{2})*)?@)?(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)|(?:(?:[a-zA-Z0-9$\-_.+!*\'(),]|%[0-9a-fA-F]{2})+\.)+(?:[a-zA-Z]{2,}))(?::\d{1,5})?(?:(?:[\/?#](?:[a-zA-Z0-9$\-_.+!*\'(),;\/?:@&=#%]|%[0-9a-fA-F]{2})+)(?=(?<!\()\)))|(?:https?|ftp):\/\/(?:(?:(?:[a-zA-Z0-9$\-_.+!*\'(),]|%[0-9a-fA-F]{2})+(?::(?:[a-zA-Z0-9$\-_.+!*\'(),]|%[0-9a-fA-F]{2})*)?@)?(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)|(?:(?:[a-zA-Z0-9$\-_.+!*\'(),]|%[0-9a-fA-F]{2})+\.)+(?:[a-zA-Z]{2,}))(?::\d{1,5})?(?:(?:[\/?#](?:[a-zA-Z0-9$\-_.+!*\'(),;\/?:@&=#%]|%[0-9a-fA-F]{2})*))/uix", $value, $urls);

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
}
