<?php

namespace matthiasott\webmention\elements\conditions;

use Craft;
use craft\base\conditions\BaseSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use matthiasott\webmention\elements\db\WebmentionQuery;
use matthiasott\webmention\elements\Webmention;

class TypeConditionRule extends BaseSelectConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('webmention', 'Type');
    }

    protected function options(): array
    {
        return [
            ['label' => Craft::t('webmention', 'Mention'), 'value' => 'mention'],
            ['label' => Craft::t('webmention', 'Comment'), 'value' => 'comment'],
            ['label' => Craft::t('webmention', 'Like'), 'value' => 'like'],
            ['label' => Craft::t('webmention', 'Repost'), 'value' => 'repost'],
            ['label' => Craft::t('webmention', 'Rsvp'), 'value' => 'rsvp'],
        ];
    }

    public function getExclusiveQueryParams(): array
    {
        return ['type'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var WebmentionQuery $query */
        $query->type($this->value);
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Webmention $element */
        return $this->matchValue($element->type);
    }
}
