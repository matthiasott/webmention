<?php

namespace matthiasott\webmention\migrations;

use craft\db\Migration;
use craft\db\Table;
use matthiasott\webmention\records\Webmention;
use matthiasott\webmention\records\WebmentionFailure;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->safeDown();

        $tableName = Webmention::tableName();

        // source/target are varchar(384). On MySQL/utf8mb4 each char is 4 bytes,
        // so the two-column (target, source) index below is 384*4*2 = 3072 bytes,
        // exactly InnoDB's max key length. The unique index further down also
        // includes targetId + targetSiteId (4 bytes each), so it indexes the URLs
        // by a 382-char prefix to stay within 3072 (see below).
        $this->createTable($tableName, [
            'id' => $this->integer()->notNull(),
            'source' => $this->string(384)->notNull(),
            'target' => $this->string(384)->notNull(),
            'targetId' => $this->integer(),
            'targetSiteId' => $this->integer(),
            'avatarUrl' => $this->string(384),
            'avatarId' => $this->integer(),
            'authorName' => $this->string(),
            'authorUrl' => $this->string(384),
            'published' => $this->dateTime(),
            'name' => $this->string(),
            'host' => $this->string(),
            'type' => $this->string(),
            'text' => $this->text(),
            'hEntryUrl' => $this->string(384),
            'rsvp' => $this->string(),
            'properties' => $this->json(),
            'parentId' => $this->integer(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(null, $tableName, ['target', 'source'], false);
        $this->createIndex(null, $tableName, ['targetId', 'targetSiteId', 'source'], false);
        $this->createIndex(null, $tableName, ['parentId'], false);

        // Unique dedup index, mirroring m260511_000000_webmentions_unique_index.
        // On MySQL the URL columns are indexed by a 382-char prefix so the key
        // (382*4*2 + 4 + 4 = 3064 bytes) stays within InnoDB's 3072-byte limit;
        // 382 chars is far beyond any real URL, so uniqueness is unaffected.
        if ($this->db->getIsMysql()) {
            $this->execute(
                'ALTER TABLE ' . $tableName . ' ADD UNIQUE INDEX ' .
                'idx_webmentions_source_target_target_id_site_id ' .
                '(source(382), target(382), targetId, targetSiteId)'
            );
        } else {
            $this->createIndex(null, $tableName, ['source', 'target', 'targetId', 'targetSiteId'], true);
        }
        $this->addForeignKey(null, $tableName, ['id'], Table::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, $tableName, ['targetId'], Table::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, $tableName, ['targetSiteId'], Table::SITES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, $tableName, ['avatarId'], Table::ASSETS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, $tableName, ['parentId'], $tableName, ['id'], 'SET NULL', null);

        $failuresTable = WebmentionFailure::tableName();
        $this->createTable($failuresTable, [
            'id' => $this->primaryKey(),
            'source' => $this->string(384)->notNull(),
            'target' => $this->string(384)->notNull(),
            'errorMessage' => $this->text()->notNull(),
            'errorTrace' => $this->text(),
            'attempts' => $this->integer()->notNull()->defaultValue(1),
            'lastAttemptedAt' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, $failuresTable, ['source', 'target'], true);
        $this->createIndex(null, $failuresTable, ['lastAttemptedAt'], false);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(WebmentionFailure::tableName());
        $this->dropTableIfExists(Webmention::tableName());
        return true;
    }
}
