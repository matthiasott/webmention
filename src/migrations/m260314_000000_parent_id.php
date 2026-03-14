<?php

namespace matthiasott\webmention\migrations;

use craft\db\Migration;
use matthiasott\webmention\records\Webmention;

class m260314_000000_parent_id extends Migration
{
    public function safeUp(): bool
    {
        $tableName = Webmention::tableName();

        $this->addColumn($tableName, 'parentId', $this->integer()->after('properties'));
        $this->createIndex(null, $tableName, ['parentId'], false);
        $this->addForeignKey(null, $tableName, ['parentId'], $tableName, ['id'], 'SET NULL', null);

        // Retroactively resolve parent IDs for existing webmentions that have in-reply-to data
        $this->resolveExistingParentIds();

        return true;
    }

    public function safeDown(): bool
    {
        $tableName = Webmention::tableName();
        $this->dropForeignKeyIfExists($tableName, ['parentId']);
        $this->dropIndexIfExists($tableName, ['parentId']);
        $this->dropColumn($tableName, 'parentId');
        return true;
    }

    private function resolveExistingParentIds(): void
    {
        $tableName = Webmention::tableName();

        // Fetch all webmentions with their properties
        $webmentions = (new \craft\db\Query())
            ->select(['id', 'source', 'hEntryUrl', 'target', 'properties'])
            ->from($tableName)
            ->all();

        // Build lookup maps for matching: source URL -> id, hEntryUrl -> id
        $sourceMap = [];
        $hEntryMap = [];
        foreach ($webmentions as $wm) {
            if (!empty($wm['source'])) {
                $normalized = $this->simpleNormalize($wm['source']);
                $sourceMap[$normalized] = (int)$wm['id'];
            }
            if (!empty($wm['hEntryUrl'])) {
                $normalized = $this->simpleNormalize($wm['hEntryUrl']);
                $hEntryMap[$normalized] = (int)$wm['id'];
            }
        }

        foreach ($webmentions as $wm) {
            $properties = is_string($wm['properties']) ? json_decode($wm['properties'], true) : $wm['properties'];
            if (empty($properties['in-reply-to'])) {
                continue;
            }

            $replyToUrls = $this->extractInReplyToUrls($properties['in-reply-to']);
            $targetNormalized = !empty($wm['target']) ? $this->simpleNormalize($wm['target']) : '';

            foreach ($replyToUrls as $replyUrl) {
                $normalized = $this->simpleNormalize($replyUrl);

                // Skip if it points to the target post itself (that's a top-level comment)
                if ($normalized === $targetNormalized) {
                    continue;
                }

                // Try to match against hEntryUrl first, then source
                $parentId = $hEntryMap[$normalized] ?? $sourceMap[$normalized] ?? null;

                // Don't set self as parent
                if ($parentId !== null && $parentId !== (int)$wm['id']) {
                    $this->update($tableName, ['parentId' => $parentId], ['id' => $wm['id']]);
                    break;
                }
            }
        }
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

    private function dropForeignKeyIfExists(string $table, array $columns): void
    {
        $schema = $this->db->getSchema();
        $tableSchema = $schema->getTableSchema($table);
        if ($tableSchema === null) {
            return;
        }

        foreach ($tableSchema->foreignKeys as $name => $fk) {
            $fkColumns = $fk;
            unset($fkColumns[0]); // Remove table name
            if (array_keys($fkColumns) === $columns) {
                $this->dropForeignKey($name, $table);
                return;
            }
        }
    }

    private function dropIndexIfExists(string $table, array $columns): void
    {
        $schema = $this->db->getSchema();
        $tableSchema = $schema->getTableSchema($table);
        if ($tableSchema === null) {
            return;
        }

        $indexes = $schema->findUniqueIndexes($tableSchema);
        // For non-unique indexes, try to drop by conventional name
        $indexName = $this->db->getIndexName($table, $columns);
        try {
            $this->dropIndex($indexName, $table);
        } catch (\Exception) {
            // Index might not exist or have a different name
        }
    }
}
