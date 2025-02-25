<?php

namespace matthiasott\webmention\migrations;

use craft\db\Migration;
use craft\db\Table;
use matthiasott\webmention\records\Webmention as WebmentionRecord;

/**
 * m250224_184716_avatar_id_prep migration.
 */
class m250224_184716_avatar_id_prep extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $tableName = WebmentionRecord::tableName();

        $this->renameColumn($tableName, 'authorPhoto', 'avatarUrl');
        $this->addColumn($tableName, 'avatarId', $this->integer()->after('avatarUrl'));
        $this->addForeignKey(null, $tableName, ['avatarId'], Table::ASSETS, ['id'], 'SET NULL', null);

        // clean up avatar URLs set to '0' for some reason
        $this->update($tableName, ['avatarUrl' => null], ['avatarUrl' => '0']);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m250224_184716_avatar_id_prep cannot be reverted.\n";
        return false;
    }
}
