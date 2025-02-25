<?php

namespace matthiasott\webmention\elements\conditions;

use Craft;
use craft\base\conditions\BaseTextConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use matthiasott\webmention\elements\db\WebmentionQuery;
use matthiasott\webmention\elements\Webmention;

class TargetConditionRule extends BaseTextConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('webmention', 'Target');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['target'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var WebmentionQuery $query */
        $query->target($this->paramValue());
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Webmention $element */
        return $this->matchValue($element->target);
    }
}
