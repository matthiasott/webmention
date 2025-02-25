<?php

namespace matthiasott\webmention\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use matthiasott\webmention\elements\Webmention;
use matthiasott\webmention\records\Webmention as WebmentionRecord;
use yii\db\Expression;

/**
 * m250224_175749_elementize migration.
 */
class m250224_175749_elementize extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $table = WebmentionRecord::tableName();

        if (!$this->db->columnExists($table, 'dateCreated')) {
            // everything's probably already in order
            return true;
        }

        // copy the `id` values into a temporary `oldId` column
        $this->addColumn($table, 'oldId', $this->integer()->after('id'));
        $this->update($table, [
            'oldId' => new Expression('[[id]]'),
        ]);

        // fetch the rows that are missing a corresponding row in the `elements` table
        $query = (new Query())
            ->select(['w.oldId', 'w.dateCreated', 'w.dateUpdated', 'w.uid'])
            ->from(['w' => $table])
            ->leftJoin(['e' => $table], [
                'and',
                '[[e.id]] = [[w.oldId]]',
                ['e.type' => Webmention::class],
            ])
            ->where(['e.id' => null]);

        // go through them and add element rows
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        foreach (Db::batch($query) as $rows) {
            $elementSitesData = [];
            foreach ($rows as $row) {
                $this->insert(Table::ELEMENTS, [
                    'type' => Webmention::class,
                    'dateCreated' => $row['dateCreated'],
                    'dateUpdated' => $row['dateUpdated'],
                    'uid' => $row['uid'],
                ]);
                $elementId = $this->db->getLastInsertID(Table::ELEMENTS);
                $elementSitesData[] = [
                    $elementId,
                    $siteId,
                    $row['dateCreated'],
                    $row['dateUpdated'],
                    StringHelper::UUID(),
                ];
                $this->update($table, ['id' => $elementId], ['oldId' => $row['oldId']]);
            }

            $this->batchInsert(
                Table::ELEMENTS_SITES,
                ['elementId', 'siteId', 'dateCreated', 'dateUpdated', 'uid'],
                $elementSitesData,
            );
        }

        // add a FK if it dosen't exist
        if (!Db::findForeignKey($table, 'id')) {
            $this->addForeignKey(null, $table, 'id', Table::ELEMENTS, 'id');
        }

        // drop the extra columns
        $this->dropColumn($table, 'oldId');
        $this->dropColumn($table, 'dateCreated');
        $this->dropColumn($table, 'dateUpdated');
        $this->dropColumn($table, 'uid');

        // Invalidate webmention query caches
        Craft::$app->elements->invalidateCachesForElementType(Webmention::class);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m250224_175749_elementize cannot be reverted.\n";
        return false;
    }
}
