<?php

namespace matthiasott\webmention\migrations;

use craft\db\Migration;
use matthiasott\webmention\records\WebmentionFailure;

class m260322_000000_failures_table extends Migration
{
    public function safeUp(): bool
    {
        $tableName = WebmentionFailure::tableName();

        if ($this->db->tableExists($tableName)) {
            return true;
        }

        $this->createTable($tableName, [
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

        $this->createIndex(null, $tableName, ['source', 'target'], true);
        $this->createIndex(null, $tableName, ['lastAttemptedAt'], false);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(WebmentionFailure::tableName());
        return true;
    }
}
