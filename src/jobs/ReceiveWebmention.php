<?php

namespace matthiasott\webmention\jobs;

use Craft;
use craft\queue\BaseJob;
use matthiasott\webmention\Plugin;

class ReceiveWebmention extends BaseJob
{
    public string $source;
    public string $target;

    public function execute($queue): void
    {
        // Validate first
        $service = Plugin::getInstance()->webmentions;
        $html = $service->validateWebmention($this->source, $this->target);

        if (!$html) {
            return;
        }

        $webmention = $service->parseWebmention($html, $this->source, $this->target);
        if (!$webmention) {
            return;
        }

        Craft::$app->getElements()->saveElement($webmention);
    }
}
