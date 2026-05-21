<?php

namespace matthiasott\webmention\migrations;

use craft\db\Migration;
use matthiasott\webmention\records\Webmention;

/**
 * Re-runs the parentId backfill with Bluesky rkey matching.
 *
 * Bridgy stores Bluesky hEntryUrls in either DID form
 * (/profile/did:plc:.../post/{rkey}) or handle form
 * (/profile/{handle}/post/{rkey}), and converts in-reply-to at:// URIs to the
 * DID form. Earlier backfills missed handle-based parents because they only
 * matched by exact URL equality.
 */
class m260521_000000_backfill_parent_ids_bluesky extends Migration
{
    public bool $dryRun = false;

    public function safeUp(): bool
    {
        $this->resolveExistingParentIds();
        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }

    private function resolveExistingParentIds(): void
    {
        $tableName = Webmention::tableName();

        $webmentions = (new \craft\db\Query())
            ->select(['id', 'source', 'hEntryUrl', 'target', 'properties', 'parentId'])
            ->from($tableName)
            ->all();

        // Build lookup maps
        $sourceMap = [];
        $hEntryMap = [];
        $statusIdMap = [];
        $blueskyRkeyMap = [];
        foreach ($webmentions as $wm) {
            if (!empty($wm['source'])) {
                $sourceMap[$this->simpleNormalize($wm['source'])] = (int)$wm['id'];
            }
            if (!empty($wm['hEntryUrl'])) {
                $hEntryMap[$this->simpleNormalize($wm['hEntryUrl'])] = (int)$wm['id'];

                $statusId = $this->extractMastodonStatusId($wm['hEntryUrl']);
                if ($statusId) {
                    $statusIdMap[$statusId] = (int)$wm['id'];
                }

                $rkey = $this->extractBlueskyPostRkey($wm['hEntryUrl']);
                if ($rkey) {
                    $blueskyRkeyMap[$rkey] = (int)$wm['id'];
                }
            }
        }

        $matched = 0;
        foreach ($webmentions as $wm) {
            // Only retouch rows that don't already have a parent — leaves Mastodon
            // matches from the prior backfill untouched.
            if (!empty($wm['parentId'])) {
                continue;
            }

            $properties = is_string($wm['properties']) ? json_decode($wm['properties'], true) : $wm['properties'];
            if (empty($properties['in-reply-to'])) {
                continue;
            }

            $replyToUrls = $this->extractInReplyToUrls($properties['in-reply-to']);
            $targetNormalized = !empty($wm['target']) ? $this->simpleNormalize($wm['target']) : '';

            foreach ($replyToUrls as $replyUrl) {
                $normalized = $this->simpleNormalize($replyUrl);

                if ($normalized === $targetNormalized) {
                    continue;
                }

                $parentId = $hEntryMap[$normalized] ?? $sourceMap[$normalized] ?? null;

                if ($parentId === null) {
                    $statusId = $this->extractMastodonStatusId($replyUrl);
                    if ($statusId) {
                        $parentId = $statusIdMap[$statusId] ?? null;
                    }
                }

                if ($parentId === null) {
                    $rkey = $this->extractBlueskyPostRkey($replyUrl);
                    if ($rkey) {
                        $parentId = $blueskyRkeyMap[$rkey] ?? null;
                    }
                }

                if ($parentId !== null && $parentId !== (int)$wm['id']) {
                    if ($this->dryRun) {
                        echo "    [dry-run] would set parentId={$parentId} on webmention id={$wm['id']} (in-reply-to={$replyUrl})\n";
                    } else {
                        $this->update($tableName, ['parentId' => $parentId], ['id' => $wm['id']]);
                    }
                    $matched++;
                    break;
                }
            }
        }

        $prefix = $this->dryRun ? '[dry-run] would match' : 'Matched';
        echo "    > $prefix $matched webmentions to their Bluesky parents.\n";
    }

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

    private function atUriToBlueskyUrl(string $atUri): ?string
    {
        if (!preg_match('#^at://(did:[^/]+)/app\.bsky\.feed\.post/([^/]+)$#', $atUri, $matches)) {
            return null;
        }
        return 'https://bsky.app/profile/' . $matches[1] . '/post/' . $matches[2];
    }

    private function extractBlueskyPostRkey(string $url): ?string
    {
        if (preg_match('#^https?://bsky\.app/profile/[^/]+/post/([A-Za-z0-9]+)$#', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    private function extractMastodonStatusId(string $url): ?string
    {
        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host']) || empty($parsed['path'])) {
            return null;
        }

        if (!empty($parsed['fragment'])) {
            return null;
        }

        $host = strtolower($parsed['host']);
        $path = $parsed['path'];

        if (preg_match('#^/web/statuses/(\d+)$#', $path, $m)) {
            return $host . ':' . $m[1];
        }

        if (preg_match('#^/@[^/]+/(\d+)$#', $path, $m)) {
            return $host . ':' . $m[1];
        }

        return null;
    }

    private function simpleNormalize(string $url): string
    {
        $parsed = parse_url($url);
        if (!$parsed) {
            return $url;
        }

        $scheme = isset($parsed['scheme']) ? strtolower($parsed['scheme']) : 'http';
        $host = isset($parsed['host']) ? strtolower($parsed['host']) : '';
        $path = isset($parsed['path']) ? strtolower(rtrim($parsed['path'], '/')) : '';
        if ($path === '') {
            $path = '/';
        }

        return $scheme . '://' . $host . $path;
    }
}
