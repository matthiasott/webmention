<?php

namespace matthiasott\webmention\elements\conditions;

use Craft;
use craft\base\conditions\BaseTextConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use matthiasott\webmention\elements\db\WebmentionQuery;
use matthiasott\webmention\elements\Webmention;

class AuthorNameConditionRule extends BaseTextConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('webmention', 'Author Name');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['authorName'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var WebmentionQuery $query */
        $query->authorName($this->paramValue());
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Webmention $element */
        return $this->matchValue($element->authorName);
    }
}
