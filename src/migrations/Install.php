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
        $this->createTable($tableName, [
            'id' => $this->integer()->notNull(),
            'source' => $this->string(),
            'target' => $this->string(),
            'authorPhoto' => $this->string(),
            'authorName' => $this->string(),
            'authorUrl' => $this->string(),
            'published' => $this->dateTime(),
            'name' => $this->string(),
            'host' => $this->string(),
            'type' => $this->string(),
            'text' => $this->string(),
            'hEntryUrl' => $this->string(),
            'rsvp' => $this->string(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(null, $tableName, ['target', 'source'], false);
        $this->addForeignKey(null, $tableName, ['id'], Table::ELEMENTS, ['id'], 'CASCADE', null);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Webmention::tableName());
        return true;
    }
}
