<?php

namespace matthiasott\webmention\elements\conditions;

use craft\elements\conditions\ElementCondition;

class WebmentionCondition extends ElementCondition
{
    protected function selectableConditionRules(): array
    {
        return [
            ...parent::selectableConditionRules(),
            AuthorNameConditionRule::class,
            AuthorUrlConditionRule::class,
            PublishedConditionRule::class,
            SourceConditionRule::class,
            TargetConditionRule::class,
            TextConditionRule::class,
            TypeConditionRule::class,
        ];
    }
}
