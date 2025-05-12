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
        if ($this->owner->hasEagerLoadedElements('webmentions')) {
            /** @phpstan-ignore-next-line */
            return $this->owner->getEagerLoadedElements('webmentions')->all();
        }

        return Plugin::getInstance()->webmentions->getWebmentionsForElement($this->owner);
    }

    /**
     * @return int
     */
    public function getTotalWebmentions(): int
    {
        $count = $this->owner->getEagerLoadedElementCount('webmentions');
        return $count ?? Plugin::getInstance()->webmentions->getTotalWebmentionsForElement($this->owner);
    }

    /**
     * @param string|null $type
     * @return Webmention[]
     */
    public function getWebmentionsByType(?string $type = null): array
    {
        if ($type === null) {
            return $this->getWebmentions();
        }

        if ($this->owner->hasEagerLoadedElements("webmentions:$type")) {
            /** @phpstan-ignore-next-line */
            return $this->owner->getEagerLoadedElements("webmentions:$type")->all();
        }

        return Plugin::getInstance()->webmentions->getWebmentionsForElementByType($this->owner, $type);
    }

    /**
     * @param string|null $type
     * @return int
     */
    public function getTotalWebmentionsByType(?string $type = null): int
    {
        if ($type === null) {
            return $this->getTotalWebmentions();
        }

        $count = $this->owner->getEagerLoadedElementCount("webmentions:$type");
        return $count ?? Plugin::getInstance()->webmentions->getTotalWebmentionsForElementByType($this->owner, $type);
    }
}
