<?php

function safeUrl(?string $url): ?string
{
    if ($url === null || trim($url) === '') {
        return null;
    }

    if (preg_match('/\s/', $url)) {
        return null;
    }

    $scheme = parse_url($url, PHP_URL_SCHEME);
    if ($scheme === null || $scheme === false) {
        return null;
    }

    $scheme = strtolower($scheme);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }

    $host = parse_url($url, PHP_URL_HOST);
    if (empty($host)) {
        return null;
    }

    return $url;
}

$cases = [
    ['https://example.com/post', 'https://example.com/post'],
    ['http://example.com/post', 'http://example.com/post'],
    ['javascript:alert(1)', null],
    ['JaVaScRiPt:alert(1)', null],
    ['javascript://example.com/%0aalert(1)', null],
    ['data:text/html,<script>...', null],
    ['vbscript:msgbox(1)', null],
    ['/relative/path', null],
    ['  https://example.com  ', null],
];

$allPassed = true;
foreach ($cases as [$input, $expected]) {
    $actual = safeUrl($input);
    if ($actual === $expected) {
        $expectedStr = $expected === null ? 'null' : "'$expected'";
        echo "PASS: '$input' → $expectedStr\n";
    } else {
        $expectedStr = $expected === null ? 'null' : "'$expected'";
        $actualStr = $actual === null ? 'null' : "'$actual'";
        echo "FAIL: '$input' → expected $expectedStr, got $actualStr\n";
        $allPassed = false;
    }
}

exit($allPassed ? 0 : 1);