<?php

namespace matthiasott\webmention\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use matthiasott\webmention\Plugin;
use matthiasott\webmention\records\Webmention as WebmentionRecord;

/**
 * m250305_002020_properties migration.
 */
class m250305_002020_properties extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $tableName = WebmentionRecord::tableName();

        $this->addColumn($tableName, 'properties', $this->string()->after('rsvp'));

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m250305_002020_properties cannot be reverted.\n";
        return false;
    }
}
