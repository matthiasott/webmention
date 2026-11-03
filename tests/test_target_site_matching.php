<?php
require __DIR__ . '/../vendor/autoload.php';

use craft\models\Site;
use matthiasott\webmention\services\Webmentions;

$failures = 0;

function assert_true(bool $result, string $label): void {
    global $failures;
    if (!$result) {
        echo "FAIL: {$label}\n";
        $failures++;
    } else {
        echo "PASS: {$label}\n";
    }
}

class TestWebmentionsSiteMatching extends Webmentions
{
    /**
     * Returns the handle of the site a target URL resolves to.
     *
     * @param array<string,array{url:string,primary:bool}> $sites Site definitions by handle
     */
    public function matchedSite(string $targetUrl, array $sites): ?string
    {
        $parseUrl = new ReflectionMethod(Webmentions::class, 'parseUrl');
        $parseUrl->setAccessible(true);
        $matchTargetSite = new ReflectionMethod(Webmentions::class, 'matchTargetSite');
        $matchTargetSite->setAccessible(true);

        $siteInfo = [];
        foreach ($sites as $handle => $config) {
            $site = new Site();
            $site->handle = $handle;
            $site->primary = $config['primary'];
            $siteInfo[$handle] = [$site, $parseUrl->invoke($this, $config['url'])];
        }

        $match = $matchTargetSite->invoke($this, $parseUrl->invoke($this, $targetUrl), $siteInfo);
        return $match ? $match[0]->handle : null;
    }
}

$service = new TestWebmentionsSiteMatching();

// One install serving each site from its own domain, all at the root path.
$domains = [
    'en' => ['url' => 'https://example.com/', 'primary' => true],
    'de' => ['url' => 'https://example.de/', 'primary' => false],
];
assert_true($service->matchedSite('https://example.de/blog/post', $domains) === 'de', 'Target on the second domain resolves to the second site');
assert_true($service->matchedSite('https://example.com/blog/post', $domains) === 'en', 'Target on the primary domain resolves to the primary site');
assert_true($service->matchedSite('https://example.de/', $domains) === 'de', 'Homepage of the second domain resolves to the second site');

// One domain, with a site in a subfolder. This already worked and must keep working.
$paths = [
    'en' => ['url' => 'https://example.com/', 'primary' => true],
    'de' => ['url' => 'https://example.com/de/', 'primary' => false],
];
assert_true($service->matchedSite('https://example.com/de/blog/post', $paths) === 'de', 'Target in the subfolder resolves to the subfolder site');
assert_true($service->matchedSite('https://example.com/blog/post', $paths) === 'en', 'Target outside the subfolder resolves to the root site');
assert_true($service->matchedSite('https://example.com/design/post', $paths) === 'en', 'A path that merely starts with the subfolder name resolves to the root site');

// A target on no known domain falls back to the primary site, as getTargetElement() expects.
assert_true($service->matchedSite('https://elsewhere.example/blog/post', $domains) === 'en', 'Unknown domain falls back to the primary site');

// Two root-path sites on the same host can't be told apart, so the primary wins.
$sameHost = [
    'de' => ['url' => 'https://example.com/', 'primary' => false],
    'en' => ['url' => 'https://example.com/', 'primary' => true],
];
assert_true($service->matchedSite('https://example.com/blog/post', $sameHost) === 'en', 'Primary site wins a tie');

echo $failures === 0 ? "\nAll tests passed.\n" : "\n{$failures} test(s) failed.\n";
exit($failures === 0 ? 0 : 1);
