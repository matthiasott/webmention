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

    /**
     * @param string|null $type
     * @return Webmention[]
     */
    public function getWebmentionsByType(?string $type = null): array
    {
        return Plugin::getInstance()->webmentions->getWebmentionsForElementByType($this->owner, $type);
    }
}
