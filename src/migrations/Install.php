<?php

namespace matthiasott\webmention\migrations;

use craft\db\Migration;
use craft\db\Table;
use matthiasott\webmention\records\Webmention;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->safeDown();

        $tableName = Webmention::tableName();

        // 3072 / 4 (bytes per char) / 2 (target, source) = 384,
        // the maximum size we can safely allow in URLs w/o hitting MySQL's max index size limit
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
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(null, $tableName, ['target', 'source'], false);
        $this->createIndex(null, $tableName, ['targetId', 'targetSiteId', 'source'], false);
        $this->addForeignKey(null, $tableName, ['id'], Table::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, $tableName, ['targetId'], Table::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, $tableName, ['targetSiteId'], Table::SITES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, $tableName, ['avatarId'], Table::ASSETS, ['id'], 'SET NULL', null);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Webmention::tableName());
        return true;
    }
}
