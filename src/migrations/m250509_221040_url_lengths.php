<?php

namespace matthiasott\webmention\migrations;

use craft\db\Migration;
use matthiasott\webmention\records\Webmention;

/**
 * m250509_221040_url_lengths migration.
 */
class m250509_221040_url_lengths extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $tableName = Webmention::tableName();

        if ($this->db->getIsPgsql()) {
            $this->execute("alter table $tableName alter column [[source]] type varchar(384)");
            $this->execute("alter table $tableName alter column [[target]] type varchar(384)");
            $this->execute("alter table $tableName alter column [[avatarUrl]] type varchar(384)");
            $this->execute("alter table $tableName alter column [[authorUrl]] type varchar(384)");
            $this->execute("alter table $tableName alter column [[hEntryUrl]] type varchar(384)");
        } else {
            $this->alterColumn($tableName, 'source', $this->string(384)->notNull());
            $this->alterColumn($tableName, 'target', $this->string(384)->notNull());
            $this->alterColumn($tableName, 'avatarUrl', $this->string(384));
            $this->alterColumn($tableName, 'authorUrl', $this->string(384));
            $this->alterColumn($tableName, 'hEntryUrl', $this->string(384));
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m250509_221040_url_lengths cannot be reverted.\n";
        return false;
    }
}
