<?php

namespace matthiasott\webmention\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\Db;
use matthiasott\webmention\Plugin;
use matthiasott\webmention\records\Webmention as WebmentionRecord;

/**
 * m250224_200159_avatar_ids migration.
 */
class m250224_200159_avatar_ids extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $avatarFolder = Plugin::getInstance()->webmentions->getAvatarFolder();
        if (!$avatarFolder) {
            return true;
        }

        $tableName = WebmentionRecord::tableName();

        // avatarUrl => avatarId for any assets that exist
        $query = (new Query())
            ->select(['id', 'avatarUrl'])
            ->from($tableName)
            ->where(['not', ['avatarUrl' => null]]);

        foreach (Db::each($query) as $row) {
            $filename = pathinfo($row['avatarUrl'], PATHINFO_BASENAME);
            $assetId = Asset::find()
                ->folderId($avatarFolder->id)
                ->filename($filename)
                ->ids()[0] ?? null;
            if ($assetId) {
                $this->update($tableName, [
                    'avatarId' => $assetId,
                    'avatarUrl' => null,
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
        echo "m250224_200159_avatar_ids cannot be reverted.\n";
        return false;
    }
}
