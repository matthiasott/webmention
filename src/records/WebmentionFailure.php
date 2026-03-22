<?php

namespace matthiasott\webmention\records;

use craft\db\ActiveRecord;

/**
 * WebmentionFailure Record
 *
 * Stores transient failure data for incoming webmentions that could not be processed.
 *
 * @property int $id
 * @property string $source
 * @property string $target
 * @property string $errorMessage
 * @property string|null $errorTrace
 * @property int $attempts
 * @property string $lastAttemptedAt
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class WebmentionFailure extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%webmention_failures}}';
    }
}
