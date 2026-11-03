<?php

/**
 * Sends a webmention from a fake source page to the real endpoint, then runs the
 * queue so it's processed. Queued sends are deleted first, so nothing contacts
 * another server.
 */

require __DIR__ . '/bootstrap.php';

$target = $argv[1] ?? null;
if (!$target) {
    fwrite(STDERR, "Usage: ddev webmention <target-url> [author-name] [reply|like|mention]\n");
    exit(1);
}

$source = sourceUrl($target, $argv[2] ?? 'Test Author', $argv[3] ?? 'reply', date('Y-m-d'));
$status = postWebmention($source, $target);
echo "Source: {$source}\nEndpoint responded with HTTP {$status}\n";

clearQueuedSends();
Craft::$app->getQueue()->run();
echo "Queue processed.\n";

exit($status === 202 ? 0 : 1);
