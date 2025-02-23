<?php

namespace matthiasott\webmention\elements;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\Html;
use DateTime;
use matthiasott\webmention\elements\db\WebmentionQuery;
use matthiasott\webmention\records\Webmention as WebmentionRecord;
use yii\base\InvalidConfigException;

/**
 * Webmention - Webmention element type
 */
class Webmention extends Element
{
    public static function displayName(): string
    {
        return Craft::t('webmention', 'Webmention');
    }

    /**
     * @return WebmentionQuery
     */
    public static function find(): ElementQueryInterface
    {
        return new WebmentionQuery(static::class);
    }

    protected static function defineSources(string $context): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('webmention', 'All Webmentions'),
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            ...parent::defineTableAttributes(),
            'author_name' => Craft::t('webmention', 'Author'),
            'text' => Craft::t('webmention', 'Text'),
            'target' => Craft::t('webmention', 'Target'),
            'type' => Craft::t('webmention', 'Type'),
        ];
    }

    public ?string $authorName = null;
    public ?string $authorPhoto = null;
    public ?string $authorUrl = null;
    public ?DateTime $published = null;
    public ?string $name = null;
    public ?string $text = null;
    public ?string $target = null;
    public ?string $source = null;
    public ?string $hEntryUrl = null;
    public ?string $host = null;
    public ?string $type = null;
    public ?string $rsvp = null;

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'authorName' => Html::tag('strong', $this->$attribute),
            'text' => $this->$attribute,
            'target' => Html::a($this->$attribute, $this->$attribute),
            default => parent::attributeHtml($attribute),
        };
    }

    public function canView(User $user): bool
    {
        return true;
    }

    public function canSave(User $user): bool
    {
        return true;
    }

    public function canDelete(User $user): bool
    {
        return true;
    }

    public function afterSave(bool $isNew): void
    {
        if (!$isNew) {
            $record = WebmentionRecord::findOne($this->id);
            if (!$record) {
                throw new InvalidConfigException("Invalid webmention ID: $this->id");
            }
        } else {
            $record = new WebmentionRecord();
            $record->id = $this->id;
        }

        $record->authorName = $this->authorName;
        $record->authorPhoto = $this->authorPhoto;
        $record->authorUrl = $this->authorUrl;
        $record->published = Db::prepareDateForDb($this->published);
        $record->name = $this->name;
        $record->text = $this->text;
        $record->target = $this->target;
        $record->source = $this->source;
        $record->hEntryUrl = $this->hEntryUrl;
        $record->host = $this->host;
        $record->type = $this->type;
        $record->rsvp = $this->rsvp;

        // Capture the dirty attributes from the record
        $dirtyAttributes = array_keys($record->getDirtyAttributes());
        $record->save(false);

        $this->setDirtyAttributes($dirtyAttributes);

        parent::afterSave($isNew);
    }
}
