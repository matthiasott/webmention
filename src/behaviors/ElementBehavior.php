<?php

namespace matthiasott\webmention\behaviors;

use craft\base\ElementInterface;
use matthiasott\webmention\elements\Webmention;
use matthiasott\webmention\Plugin;
use yii\base\Behavior;

/**
 * @property ElementInterface $owner
 */
class ElementBehavior extends Behavior
{
    /**
     * @return Webmention[]
     */
    public function getWebmentions(): array
    {
        return Plugin::getInstance()->webmentions->getWebmentionsForElement($this->owner);
    }
}
