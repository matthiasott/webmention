<?php

/**
 * Boots Craft for the seed and verify scripts, and holds what they share.
 */

use craft\db\Query;
use GuzzleHttp\RequestOptions;

const PRIMARY_URL = 'https://webmention-dev.ddev.site/';
const SECOND_URL = 'https://webmention-dev-b.ddev.site/';
const SOURCE_HOST = 'webmention-dev-sources.ddev.site';

define('CRAFT_BASE_PATH', dirname(__DIR__));
define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');
require CRAFT_VENDOR_PATH . '/autoload.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

/**
 * Returns a plain HTTP URL that works from inside the web container, so requests
 * don't depend on `mkcert -install`. The router's HTTP port varies by machine.
 */
function internalUrl(string $host, string $path = ''): string
{
    return sprintf('http://%s:%s/%s', $host, getenv('DDEV_ROUTER_HTTP_PORT') ?: '80', ltrim($path, '/'));
}

/**
 * Returns a URL for a fake source page that links to the target.
 */
function sourceUrl(string $target, string $name, string $type = 'reply', string $date = '2026-01-01'): string
{
    return internalUrl(SOURCE_HOST, 'sources/source.php') . '?' . http_build_query([
        'target' => $target,
        'name' => $name,
        'type' => $type,
        'date' => $date,
        'id' => uniqid(),
    ]);
}

/**
 * Sends a webmention to the real endpoint over HTTP, as another website would.
 * It goes to the target's own host unless another endpoint host is given.
 *
 * @return int The response status code
 */
function postWebmention(string $source, string $target, ?string $endpointHost = null): int
{
    $endpoint = internalUrl($endpointHost ?? parse_url($target, PHP_URL_HOST), 'webmention');

    return Craft::createGuzzleClient([RequestOptions::HTTP_ERRORS => false])
        ->post($endpoint, [RequestOptions::FORM_PARAMS => compact('source', 'target')])
        ->getStatusCode();
}

/**
 * Removes queued sends, so processing the queue never contacts another server.
 */
function clearQueuedSends(): void
{
    Craft::$app->getDb()->createCommand()
        ->delete('{{%queue}}', ['description' => 'Sending webmentions'])
        ->execute();
}

function queuedSends(): int
{
    return (int)(new Query())
        ->from('{{%queue}}')
        ->where(['description' => 'Sending webmentions'])
        ->count();
}
