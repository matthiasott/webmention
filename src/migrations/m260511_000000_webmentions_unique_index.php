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

                // Delete duplicate rows in the correct order: first the webmention data
                // rows (FK from craft_webmentions.id → craft_elements.id is RESTRICT in
                // practice, so the child must go before the parent), then the element
                // rows. The elements delete then cascades down to elements_sites,
                // searchindex, and any other element-related tables via their own FKs.
                Craft::$app->db->createCommand()
                    ->delete($tableName, ['id' => $deleteIds])
                    ->execute();

                Craft::$app->db->createCommand()
                    ->delete(Table::ELEMENTS, ['id' => $deleteIds])
                    ->execute();
            } else {
                Craft::info("[DRY RUN] Would delete ids=[" . implode(',', $deleteIds) . "]", __METHOD__);
            }
        }

        // Step 2: Create unique composite index.
        // On MySQL with utf8mb4, indexing source+target (VARCHAR(384) each)
        // alongside two INTs exceeds InnoDB's 3072-byte key limit, so we use prefix indexes for the URL columns there.
        if (!$this->dryRun) {
            // Drop any index left behind by a prior failed/partial run so this
            // migration is safe to re-apply (the 1.4.3 release crashed here on
            // MySQL/utf8mb4, leaving the migration unrecorded and pending).
            $this->dropIndexIfExists($tableName, ['source', 'target', 'targetId', 'targetSiteId'], true);

            $db = Craft::$app->db;
            if ($db->getIsMysql()) {
                $rawTable = $db->getSchema()->getRawTableName($tableName);
                $indexName = $db->getSchema()->quoteSimpleTableName(
                    'idx_' . substr(md5($rawTable . 'source_target_targetId_targetSiteId_unique'), 0, 32)
                );
                $quotedTable = $db->quoteTableName($tableName);
                $db->createCommand(
                    "ALTER TABLE $quotedTable ADD UNIQUE INDEX $indexName (`source`(380), `target`(380), `targetId`, `targetSiteId`)"
                )->execute();
            } else {
                $this->createIndex(null, $tableName, ['source', 'target', 'targetId', 'targetSiteId'], true);
            }
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
