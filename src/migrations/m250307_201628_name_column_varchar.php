<?php

namespace matthiasott\webmention\migrations;

use Craft;
use craft\db\Migration;
use matthiasott\webmention\records\Webmention;

/**
 * m250307_201628_name_column_varchar migration.
 */
class m250307_201628_name_column_varchar extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->alterColumn(Webmention::tableName(), 'name', $this->string());
        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m250307_201628_name_column_varchar cannot be reverted.\n";
        return false;
    }
}
