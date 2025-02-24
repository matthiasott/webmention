<?php

namespace matthiasott\webmention\migrations;

use Craft;
use craft\db\Migration;
use craft\services\ProjectConfig;
use Illuminate\Support\Arr;
use matthiasott\webmention\fields\WebmentionSwitch;
use matthiasott\webmention\records\Webmention;

/**
 * m250223_232750_db_changes migration.
 */
class m250223_232750_db_changes extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $oldTable = '{{%webmention_webmention}}';
        $newTable = Webmention::tableName();

        // Rename the table
        if ($this->db->tableExists($oldTable)) {
            $this->renameTable($oldTable, $newTable);
        }

        // Rename the columns
        $renameColumns = [
            'author_photo' => 'authorPhoto',
            'author_name' => 'authorName',
            'author_url' => 'authorUrl',
            'site' => 'host',
            'url' => 'hEntryUrl',
        ];

        foreach ($renameColumns as $from => $to) {
            if ($this->db->columnExists($newTable, $from)) {
                $this->renameColumn($newTable, $from, $to);
            }
        }

        // Ensure published is a DATETIME column
        $this->alterColumn($newTable, 'published', $this->dateTime());

        // Update the field classes
        $fieldConfigs = Craft::$app->projectConfig->get(ProjectConfig::PATH_FIELDS) ?? [];
        foreach ($fieldConfigs as $fieldUid => $fieldConfig) {
            if ($fieldConfig['type'] === 'Webmention_WebmentionSwitch') {
                $fieldConfig['type'] = WebmentionSwitch::class;
                $fieldConfig['settings'] = Arr::only($fieldConfig['settings'] ?? [], [
                    'default',
                ]);
                $path = sprintf('%s.%s', ProjectConfig::PATH_FIELDS, $fieldUid);
                Craft::$app->projectConfig->set($path, $fieldConfig);
            }
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m250223_232750_db_changes cannot be reverted.\n";
        return false;
    }
}
