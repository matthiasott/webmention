<?php

namespace matthiasott\webmention\migrations;

use craft\db\Migration;
use matthiasott\webmention\records\Webmention;

/**
 * Re-runs the parentId backfill with improved Mastodon URL matching.
 * The original migration couldn't match /web/statuses/{id} against /@user/{id}.
 */
class m260314_000001_backfill_parent_ids extends Migration
{
    public function safeUp(): bool
    {
        $this->resolveExistingParentIds();
        return true;
    }

    public function safeDown(): bool
    {
        // Reset all parentId values (reversible)
        $this->update(Webmention::tableName(), ['parentId' => null], ['not', ['parentId' => null]]);
        return true;
    }

    private function resolveExistingParentIds(): void
    {
        $tableName = Webmention::tableName();

        $webmentions = (new \craft\db\Query())
            ->select(['id', 'source', 'hEntryUrl', 'target', 'properties'])
            ->from($tableName)
            ->all();

        // Build lookup maps
        $sourceMap = [];
        $hEntryMap = [];
        $statusIdMap = [];
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
            }
        }

        $matched = 0;
        foreach ($webmentions as $wm) {
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

                if ($parentId !== null && $parentId !== (int)$wm['id']) {
                    $this->update($tableName, ['parentId' => $parentId], ['id' => $wm['id']]);
                    $matched++;
                    break;
                }
            }
        }

        echo "    > Matched $matched webmentions to their parents.\n";
    }

    private function extractInReplyToUrls(array $inReplyTo): array
    {
        $urls = [];
        foreach ($inReplyTo as $item) {
            if (is_string($item)) {
                $urls[] = $item;
            } elseif (is_array($item) && isset($item['value'])) {
                $urls[] = $item['value'];
            }
        }
        return $urls;
    }

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
