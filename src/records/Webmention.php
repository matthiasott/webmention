<?php

namespace matthiasott\webmention\records;

use craft\db\ActiveRecord;

/**
 * Webmention Record
 *
 * Provides a definition of the database tables required by our plugin,
 * and methods for updating the database. This class should only be called
 * by our service layer, to ensure a consistent API for the rest of the
 * application to use.
 *
 * @property int $id ID
 * @property string|null $authorName
 * @property int|null $avatarId
 * @property string|null $authorUrl
 * @property string|null $published
 * @property string|null $name
 * @property string|null $text
 * @property string|null $target
 * @property int|null $targetId
 * @property int|null $targetSiteId
 * @property string|null $source
 * @property string|null $hEntryUrl
 * @property string|null $host
 * @property string|null $type
 * @property string|null $rsvp
 */
class Webmention extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%webmentions}}';
    }
}
