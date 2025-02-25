<?php

namespace matthiasott\webmention\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use matthiasott\webmention\Plugin;
use matthiasott\webmention\records\Webmention as WebmentionRecord;

/**
 * m250224_224819_target_ids migration.
 */
class m250224_224819_target_ids extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $tableName = WebmentionRecord::tableName();

        $this->delete($tableName, [
            'or',
            ['source' => null],
            ['target' => null],
        ]);

        $this->alterColumn($tableName, 'source', $this->string()->notNull());
        $this->alterColumn($tableName, 'target', $this->string()->notNull());

        $this->addColumn($tableName, 'targetId', $this->integer()->after('target'));
        $this->addColumn($tableName, 'targetSiteId', $this->integer()->after('targetId'));

        $this->createIndex(null, $tableName, ['targetId', 'targetSiteId', 'source'], false);
        $this->addForeignKey(null, $tableName, ['targetId'], Table::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, $tableName, ['targetSiteId'], Table::SITES, ['id'], 'CASCADE', null);

        $query = (new Query())
            ->select(['id', 'target'])
            ->from($tableName);

        $service = Plugin::getInstance()->webmentions;

        foreach (Db::each($query) as $row) {
            $element = $service->getTargetElement($row['target']);
            if ($element) {
                $this->update($tableName, [
                    'targetId' => $element->id,
                    'targetSiteId' => $element::isLocalized() ? $element->siteId : null,
                ], ['id' => $row['id']]);
            }
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m250224_224819_target_ids cannot be reverted.\n";
        return false;
    }
}
