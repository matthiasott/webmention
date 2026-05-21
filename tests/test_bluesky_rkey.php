<?php

/**
 * test_bluesky_rkey.php
 *
 * Verifies extractBlueskyPostRkey() correctly extracts the rkey from
 * both DID-based and handle-based Bluesky post URLs. The rkey-based
 * fallback in resolveParentWebmention() depends on this returning the
 * same value for the two URL formats Bridgy may use for the same post.
 */

class BlueskyRkeyTester
{
    public function extractBlueskyPostRkey(string $url): ?string
    {
        if (preg_match('#^https?://bsky\.app/profile/[^/]+/post/([A-Za-z0-9]+)$#', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    public function atUriToBlueskyUrl(string $atUri): ?string
    {
        if (!preg_match('#^at://(did:[^/]+)/app\.bsky\.feed\.post/([^/]+)$#', $atUri, $matches)) {
            return null;
        }
        return 'https://bsky.app/profile/' . $matches[1] . '/post/' . $matches[2];
    }
}

$t = new BlueskyRkeyTester();
$failures = 0;

function check(string $label, $expected, $actual): void
{
    global $failures;
    if ($expected === $actual) {
        echo "PASS: $label\n";
    } else {
        echo "FAIL: $label — expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n";
        $failures++;
    }
}

// Real URL formats observed on matthiasott.com/notes/ad-infinitum
check(
    'handle-based URL (custom domain)',
    '3mmdrrhpgec2e',
    $t->extractBlueskyPostRkey('https://bsky.app/profile/rikschennink.com/post/3mmdrrhpgec2e')
);

check(
    'handle-based URL (bsky.social)',
    '3mmdscr45322y',
    $t->extractBlueskyPostRkey('https://bsky.app/profile/harcel.bsky.social/post/3mmdscr45322y')
);

check(
    'DID-based URL',
    '3mmdrzgrvlc2p',
    $t->extractBlueskyPostRkey('https://bsky.app/profile/did:plc:vt3ya7nuzb7ubtjcxntn326t/post/3mmdrzgrvlc2p')
);

// at:// → https conversion produces DID form; same rkey should be extractable
$converted = $t->atUriToBlueskyUrl('at://did:plc:vt3ya7nuzb7ubtjcxntn326t/app.bsky.feed.post/3mmdrzgrvlc2p');
check('at:// converts to DID form', 'https://bsky.app/profile/did:plc:vt3ya7nuzb7ubtjcxntn326t/post/3mmdrzgrvlc2p', $converted);
check('rkey from converted URL', '3mmdrzgrvlc2p', $t->extractBlueskyPostRkey($converted));

// Critical: rkey from at:// conversion matches rkey from handle-based URL
$handleUrl = 'https://bsky.app/profile/rikschennink.com/post/3mmdrrhpgec2e';
$atConverted = $t->atUriToBlueskyUrl('at://did:plc:rikplcdid/app.bsky.feed.post/3mmdrrhpgec2e');
check(
    'rkey matches across DID/handle formats',
    $t->extractBlueskyPostRkey($handleUrl),
    $t->extractBlueskyPostRkey($atConverted)
);

// Non-matching URLs should return null
check('non-bsky URL returns null', null, $t->extractBlueskyPostRkey('https://example.com/post/abc'));
check('bsky profile URL returns null', null, $t->extractBlueskyPostRkey('https://bsky.app/profile/rikschennink.com'));
check('URL with query string returns null', null, $t->extractBlueskyPostRkey('https://bsky.app/profile/x/post/abc?ref=1'));
check('URL with fragment returns null', null, $t->extractBlueskyPostRkey('https://bsky.app/profile/x/post/abc#liked-by'));
check('empty string returns null', null, $t->extractBlueskyPostRkey(''));

echo "\n";
if ($failures > 0) {
    echo "$failures test(s) failed.\n";
    exit(1);
}
echo "All tests passed.\n";
exit(0);
