<?php

namespace matthiasott\webmention\elements;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\conditions\ElementConditionInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Html;
use DateTime;
use matthiasott\webmention\elements\actions\Update;
use matthiasott\webmention\elements\conditions\WebmentionCondition;
use matthiasott\webmention\elements\db\WebmentionQuery;
use matthiasott\webmention\records\Webmention as WebmentionRecord;
use yii\base\InvalidConfigException;

/**
 * Webmention - Webmention element type
 *
 * @property Asset|null $avatar
 * @property-read string|null $authorPhoto
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
        return Craft::createObject(WebmentionQuery::class, [static::class]);
    }

    public static function createCondition(): ElementConditionInterface
    {
        return Craft::createObject(WebmentionCondition::class);
    }

    protected static function defineSearchableAttributes(): array
    {
        return [
            'authorName',
            'source',
            'target',
            'text',
        ];
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
            'authorName' => Craft::t('webmention', 'Author'),
            'text' => Craft::t('webmention', 'Text'),
            'source' => Craft::t('webmention', 'Source'),
            'target' => Craft::t('webmention', 'Target'),
            'type' => Craft::t('webmention', 'Type'),
            'published' => Craft::t('webmention', 'Published on'),
            ...parent::defineTableAttributes(),
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return [
            'source',
            'target',
            'type',
            'dateCreated',
        ];
    }

    public ?string $authorName = null;
    public ?int $avatarId = null;
    public ?string $authorUrl = null;
    public ?DateTime $published = null;
    public ?string $name = null;
    public ?string $text = null;
    public ?string $target = null;
    public ?int $targetId = null;
    public ?int $targetSiteId = null;
    public ?string $source = null;
    public ?string $hEntryUrl = null;
    public ?string $host = null;
    public ?string $type = null;
    public ?string $rsvp = null;
    public ?string $properties = null;

    private Asset|null|false $_avatar = null;

    protected function uiLabel(): ?string
    {
        return Craft::t('webmention', '{author} on {source}', [
            'author' => $this->authorName,
            'source' => parse_url($this->source, PHP_URL_HOST),
        ]);
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'authorName' => $this->authorAttributeHtml(),
            'source' => $this->sourceAttributeHtml(),
            'target' => $this->targetAttributeHtml(),
            'type' => Html::encode(ucfirst($this->type)),
            default => parent::attributeHtml($attribute),
        };
    }

    private function authorAttributeHtml(bool $chromeless = false): string
    {
        $html = Html::beginTag('div', [
            'class' => array_filter([
                'chip',
                Cp::CHIP_SIZE_SMALL,
                $chromeless ? 'chromeless' : null,
            ]),
        ]);

        $avatar = $this->getAvatar();
        if ($avatar) {
            $html .= $avatar->getThumbHtml(30);
        }

        $html .= Html::beginTag('div', ['class' => 'chip-content']);

        if ($this->authorUrl) {
            $html .= Html::a(Html::encode($this->authorName), $this->authorUrl, ['target' => '_blank']);
        } else {
            $html .= Html::encode($this->authorName);
        }

        $html .= Html::endTag('div') .
            Html::endTag('div');

        return $html;
    }

    private function sourceAttributeHtml(bool $shorten = true): string
    {
        if ($shorten) {
            $label = parse_url($this->source, PHP_URL_HOST);
        } else {
            $label = preg_replace('/^https?:\/\//', '', $this->target);
        }

        return Html::a(Html::encode($label), $this->source, ['target' => '_blank']);
    }

    private function targetAttributeHtml(bool $chromeless = false): string
    {
        if ($this->targetId) {
            $element = Craft::$app->elements->getElementById($this->targetId, siteId: $this->targetSiteId);
            if ($element) {
                $html = Cp::elementChipHtml($element);
                if ($chromeless) {
                    $html = Html::modifyTagAttributes($html, ['class' => 'chromeless']);
                }
                return $html;
            }
        }

        $label = preg_replace('/^https?:\/\/.*?\//', '', $this->target);
        return Html::a(Html::encode($label), $this->target, ['target' => '_blank']);
    }

    protected function metadata(): array
    {
        return [
            Craft::t('webmention', 'Author') => $this->authorAttributeHtml(true),
            Craft::t('webmention', 'Type') => $this->attributeHtml('type'),
            Craft::t('webmention', 'Text') => $this->attributeHtml('text'),
            Craft::t('webmention', 'Source') => $this->sourceAttributeHtml(false),
            Craft::t('webmention', 'Target') => $this->targetAttributeHtml(true),
            Craft::t('webmention', 'Published on') => $this->attributeHtml('published'),
        ];
    }

    protected static function defineActions(string $source): array
    {
        return [
            Update::class,
        ];
    }

    protected function safeActionMenuItems(): array
    {
        $updateId = sprintf('action-update-%s', mt_rand());

        Craft::$app->view->registerJsWithVars(fn($id, $source, $target, $message) => <<<JS
(() => {
  $('#' + $id).on('activate', async () => {
    await Craft.sendActionRequest('POST', 'webmention/webmention/handle-webmention', {
      data: {
        source: $source,
        target: $target,
      },
    });
    Craft.cp.runQueue();
    Craft.cp.displaySuccess($message);
  });
})();
JS, [
            Craft::$app->view->namespaceInputId($updateId),
            $this->source,
            $this->target,
            Craft::t('webmention', 'Webmention update queued.'),
        ]);

        return [
            ...parent::safeActionMenuItems(),
            [
                'id' => $updateId,
                'icon' => 'arrows-rotate',
                'label' => Craft::t('webmention', 'Update'),
            ],
        ];
    }

    /**
     * Returns the avatar
     *
     * @return Asset|null
     */
    public function getAvatar(): ?Asset
    {
        if (!isset($this->_avatar)) {
            if (!$this->avatarId) {
                return null;
            }

            $this->_avatar = Craft::$app->getAssets()->getAssetById($this->avatarId) ?? false;
        }

        return $this->_avatar ?: null;
    }

    /**
     * Sets the entry’s author.
     *
     * @param Asset|null $avatar
     */
    public function setAvatar(?Asset $avatar = null): void
    {
        $this->_avatar = $avatar;
        $this->avatarId = $avatar->id ?? null;
    }

    /**
     * Returns the avatar URL.
     *
     * @return string|null
     * @deprecated
     */
    public function getAuthorPhoto(): ?string
    {
        return $this->getAvatar()?->url;
    }

    public function canView(User $user): bool
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
        $record->avatarId = $this->avatarId;
        $record->authorUrl = $this->authorUrl;
        $record->published = Db::prepareDateForDb($this->published);
        $record->name = $this->name;
        $record->text = $this->text;
        $record->target = $this->target;
        $record->targetId = $this->targetId;
        $record->targetSiteId = $this->targetSiteId;
        $record->source = $this->source;
        $record->hEntryUrl = $this->hEntryUrl;
        $record->host = $this->host;
        $record->type = $this->type;
        $record->rsvp = $this->rsvp;
        $record->properties = $this->properties;

        // Capture the dirty attributes from the record
        $dirtyAttributes = array_keys($record->getDirtyAttributes());
        $record->save(false);

        $this->setDirtyAttributes($dirtyAttributes);

        parent::afterSave($isNew);
    }

    /**
     * @inheritdoc
     */
    public static function eagerLoadingMap(array $sourceElements, string $handle): array|null|false
    {
        if ($handle === 'avatar') {
            $sourceElementIds = array_map(fn(ElementInterface $element) => $element->id, $sourceElements);
            $map = (new Query())
                ->select(['id as source', 'avatarId as target'])
                ->from(WebmentionRecord::tableName())
                ->where(['id' => $sourceElementIds])
                ->andWhere(['not', ['avatarId' => null]])
                ->all();

            return [
                'elementType' => Asset::class,
                'map' => $map,
            ];
        }

        return parent::eagerLoadingMap($sourceElements, $handle);
    }
}
