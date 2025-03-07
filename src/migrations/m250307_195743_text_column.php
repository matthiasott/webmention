<?php

namespace matthiasott\webmention\migrations;

use Craft;
use craft\db\Migration;
use matthiasott\webmention\records\Webmention;

/**
 * m250307_195743_text_column migration.
 */
class m250307_195743_text_column extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->alterColumn(Webmention::tableName(), 'text', $this->text());
        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m250307_195743_text_column cannot be reverted.\n";
        return false;
    }
}
