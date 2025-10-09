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
            }
            if (preg_match('/comment/', $src)) {
                $result['type'] = 'comment';
            }
            if (preg_match('/like/', $src)) {
                $result['type'] = 'like';
            }
            if (preg_match('/repost/', $src)) {
                $result['type'] = 'repost';
            }
            if (preg_match('/rsvp/', $src)) {
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
            $response = $client->get($src);
        } catch (GuzzleException $e) {
            return false;
        }
        $html = (string)$response->getBody();

        // and go find a backlink
        $doc = new DOMDocument();
        libxml_use_internal_errors(true); # suppress parse errors and warnings
        $body = mb_convert_encoding($html, 'HTML-ENTITIES', mb_detect_encoding($html));
        @$doc->loadHTML($body, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        $linkUrls = [];

        foreach ($xpath->query('//a[@href]') as $link) {
            /** @var DOMElement $link */
            $linkUrl = $link->getAttribute('href');
            if (strcasecmp($linkUrl, $target) === 0) {
                return $html;
            }
            $linkUrls[] = $linkUrl;
        }

        foreach ($linkUrls as $linkUrl) {
            try {
                $head = $client->head($linkUrl, [
                    RequestOptions::ALLOW_REDIRECTS => false,
                ]);
            } catch (GuzzleException) {
                continue;
            }

            if ($head->hasHeader('Location')) {
                $redirect = $head->getHeader('Location')[0];
                if (strcasecmp($redirect, $target) === 0) {
                    return $html;
                }
            }
        }

        return false;
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

        function findHEntry($items) {
            foreach ($items as $item) {
                if (in_array('h-entry', $item['type'])) {
                    return $item;
                }
                // Check nested children
                if (isset($item['children']) && is_array($item['children'])) {
                    $found = findHEntry($item['children']);
                    if ($found) return $found;
                }
            }
            return null;
        }

        $entry = findHEntry($parsed['items']);

        if (!isset($entry)) {
            throw new Exception("No h-entry found in source HTML.");
            return false;
        }

        // Parse comment – with max text length from settings
        $settings = Plugin::getInstance()->settings;
        $maxLength = $settings->maxTextLength;
        $result = \IndieWeb\comments\parse($entry, $source, $maxLength, 100);

        if (empty($result)) {
            // probably spam
            throw new Exception('Could not parse comment.');
            return false;
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
        if (empty($result['author']['name'])) {
            $result['author']['name'] = $representative['properties']['name'][0] ?? $representative['properties']['nickname'][0] ?? null;
        }
        // If author url is empty use the one from the representative h-card
        if (empty($result['author']['url'])) {
            if ($representative) {
                $result['author']['url'] = $representative['properties']['url'][0];
            }
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
        
        if (!empty($result['author']['photo'])) {
            Craft::info('Not empty', 'webmention');
            if (isset($result['author']['photo']['value'])) {
                $result['author']['photo'] = $result['author']['photo']['value'];
            }
        } elseif ($representative) {
            // Sometimes the structure of the parsed h-card differs
            if ($representative['properties']['photo'][0] && is_string($representative['properties']['photo'][0])) {
                // The photo can be the first element in ['photo']
                $result['author']['photo'] = $representative['properties']['photo'][0];
            } elseif ($representative['properties']['photo'][0]['value'] && is_string($representative['properties']['photo'][0]['value'])) {
                // Alternatively, the photo can be the ['value'] key of the array inside ['photo']
                $result['author']['photo'] = $representative['properties']['photo'][0]['value'];
            } else {
                // If no author photo is defined, check gravatar for image
                $email = $representative['properties']['email'][0];
                if ($email) {
                    $email = rtrim(str_replace('mailto:', '', $email));
                    $gravatar = $this->_get_gravatar($email);
                    $result['author']['photo'] = $gravatar . ".jpg";
                }
            }
        }

        // Author photo should be saved locally to avoid exploits.
        // If an author photo is available get the image and save it to assets
        if (!empty($result['author']['photo'])) {
            $asset = $this->saveAsset($result['author']['photo']);
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
        $model->authorName = Html::encode($result['author']['name']);
        $model->avatarId = $result['author']['avatarId'] ?? null;
        $model->authorUrl = Html::encode($result['author']['url']);
        $model->published = new DateTime($result['published']);
        $model->name = Html::encode($result['name']);
        $model->text = $text;
        $model->target = Html::encode($target);
        $model->targetId = $targetElement?->id;
        $model->targetSiteId = $targetElement && $targetElement->isLocalized() ? $targetElement->siteId : null;
        $model->source = Html::encode($source);
        $model->hEntryUrl = Html::encode($result['url']);
        $model->host = $result['site'];
        $model->type = $result['type'];
        $model->properties = $entry['properties'];

        return $model;
    }

    private function saveAsset(string $url): ?Asset
    {
        $folder = $this->getAvatarFolder();
        if (!$folder) {
            return null;
        }

        // get remote image and store in temp path with a hashed filename
        $client = Craft::createGuzzleClient();
        try {
            $response = $client->get($url);
        } catch (GuzzleException) {
            return null;
        }

        $hashedFileName = sha1(pathinfo($url, PATHINFO_FILENAME));

        $fileExtension = (pathinfo($url, PATHINFO_EXTENSION));

        // In case the file doesn’t have an extension – which is the case for Bluesky avatars – we need to get it via the MIME type
        if (empty($fileExtension)) {
            // Get the MIME type from image type
            $mime_type = image_type_to_mime_type(exif_imagetype($url));
            
            if (!empty($mime_type)) {
                // get the right extension
                $fileExtension = FileHelper::getExtensionByMimeType($mime_type);
            } else {
                // if it all fails, let’s just assume it is a JPG for now
                $fileExtension = "jpg";
            }
        }

        $fileName = $hashedFileName . "." . $fileExtension;

        $tempPath = sprintf('%s/%s', Craft::$app->path->getTempPath(), $fileName);
        FileHelper::writeToFile($tempPath, (string)$response->getBody());

        // If it's an image, cleanse it of any malicious scripts that may be embedded
        // (recommended unless you completely trust everyone that’s uploading images)
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

        if (!Craft::$app->elements->saveElement($asset)) {
            Craft::warning(sprintf('Couldn’t save avatar asset: %s', implode(', ', $asset->getFirstErrors())), __METHOD__);
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
            $parsedSiteUrl = $sites[$site->id];
        }

        // figure out the entry URI relative to the site base path
        $uri = $parsedTarget['path'];
        if ($parsedSiteUrl['path'] && str_starts_with("{$parsedTarget['path']}/", "{$parsedSiteUrl['path']}/")) {
            $uri = substr($uri, strlen($parsedSiteUrl['path']) + 1);
        }

        return $elementsService->getElementByUri($uri, $site->id);
    }

    /**
     * @param array $parsedTarget
     * @param array{0:Site,1:array}[] $sites
     * @return array
     */
    private function matchTargetSite(array $parsedTarget, array $sites): array
    {
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
                $sendWebmentions = (bool)$entry->getFieldValue($field->handle);
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
