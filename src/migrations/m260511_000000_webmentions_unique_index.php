<?php

namespace matthiasott\webmention\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Table;
use matthiasott\webmention\records\Webmention;

class m260511_000000_webmentions_unique_index extends Migration
{
    public bool $dryRun = false;

    public function safeUp(): bool
    {
        $tableName = Webmention::tableName();

        // Step 1: Identify duplicate groups
        $duplicates = (new \craft\db\Query())
            ->select(['source', 'target', 'targetId', 'targetSiteId'])
            ->from($tableName)
            ->groupBy(['source', 'target', 'targetId', 'targetSiteId'])
            ->having('COUNT(*) > 1')
            ->all();

        foreach ($duplicates as $dup) {
            $source = $dup['source'];
            $target = $dup['target'];
            $targetId = $dup['targetId'];
            $targetSiteId = $dup['targetSiteId'];

            // Fetch all rows in this group, ordered by parentId DESC, id DESC
            $rows = (new \craft\db\Query())
                ->from($tableName)
                ->where([
                    'source' => $source,
                    'target' => $target,
                    'targetId' => $targetId,
                ])
                ->andWhere(['targetSiteId' => $targetSiteId])
                ->orderBy(['parentId' => SORT_DESC, 'id' => SORT_DESC])
                ->all();

            if (count($rows) <= 1) {
                continue;
            }

            $keepId = (int) $rows[0]['id'];
            $deleteIds = array_map(fn($r) => (int) $r['id'], array_slice($rows, 1));

            Craft::info("Dedup group source={$source} target={$target}: keeping id={$keepId}, deleting ids=[" . implode(',', $deleteIds) . "]", __METHOD__);

            if (!$this->dryRun) {
                // Update parentId references BEFORE deleting
                if (!empty($deleteIds)) {
                    Craft::$app->db->createCommand()
                        ->update($tableName, ['parentId' => $keepId], ['parentId' => $deleteIds])
                        ->execute();
                }

                // Delete duplicate rows via the elements table so the cascade cleans up
                // webmentions, elements_sites, searchindex, and any other element-related
                // tables in one shot. Deleting directly from the webmentions table would
                // leave orphaned rows in elements (the FK cascade only flows elements → webmentions).
                Craft::$app->db->createCommand()
                    ->delete(Table::ELEMENTS, ['id' => $deleteIds])
                    ->execute();
            } else {
                Craft::info("[DRY RUN] Would delete ids=[" . implode(',', $deleteIds) . "]", __METHOD__);
            }
        }

        // Step 2: Create unique composite index
        if (!$this->dryRun) {
            $this->createIndex(null, $tableName, ['source', 'target', 'targetId', 'targetSiteId'], true);
        } else {
            Craft::info("[DRY RUN] Would create unique index on (source, target, targetId, targetSiteId)", __METHOD__);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $tableName = Webmention::tableName();
        $this->dropIndexIfExists($tableName, ['source', 'target', 'targetId', 'targetSiteId'], true);
        return true;
    }
}
