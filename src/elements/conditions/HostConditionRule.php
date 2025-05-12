<?php

namespace matthiasott\webmention\elements\conditions;

use Craft;
use craft\base\conditions\BaseTextConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\ArrayHelper;
use matthiasott\webmention\elements\db\WebmentionQuery;
use matthiasott\webmention\elements\Webmention;

class HostConditionRule extends BaseTextConditionRule implements ElementConditionRuleInterface
{
    public function getLabel(): string
    {
        return Craft::t('webmention', 'Host');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['host'];
    }

    protected function operators(): array
    {
        $operators = parent::operators();
        ArrayHelper::removeValue($operators, self::OPERATOR_NOT_EMPTY);
        ArrayHelper::removeValue($operators, self::OPERATOR_EMPTY);
        return $operators;
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var WebmentionQuery $query */
        $query->host($this->paramValue());
    }

    public function matchElement(ElementInterface $element): bool
    {
        /** @var Webmention $element */
        return $this->matchValue($element->host);
    }
}
