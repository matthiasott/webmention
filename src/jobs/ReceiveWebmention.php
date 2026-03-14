<?php

namespace matthiasott\webmention\jobs;

use Craft;
use craft\queue\BaseJob;
use matthiasott\webmention\Plugin;
use Throwable;

class ReceiveWebmention extends BaseJob
{
    public string $source;
    public string $target;

    protected function defaultDescription(): ?string
    {
        return Craft::t('webmention', 'Processing webmention');
    }

    public function execute($queue): void
    {
        try {
            $service = Plugin::getInstance()->webmentions;

            // Validate first
            $html = $service->validateWebmention($this->source, $this->target);

            if (!$html) {
                Craft::warning(sprintf(
                    'Webmention validation failed for source "%s" to target "%s"',
                    $this->source,
                    $this->target
                ), 'webmention');
                return;
            }

            $webmention = $service->parseWebmention($html, $this->source, $this->target);
            if (!$webmention) {
                Craft::warning(sprintf(
                    'Unable to parse webmention from source "%s" to target "%s"',
                    $this->source,
                    $this->target
                ), 'webmention');
                return;
            }

            Craft::$app->getElements()->saveElement($webmention);

            // Check if any existing webmentions are replies to this one (reverse resolution)
            $service->resolveChildWebmentions($webmention);
        } catch (Throwable $e) {
            // Log and complete gracefully - don't throw to avoid blocking queue
            Craft::error(sprintf(
                'Error processing webmention from "%s" to "%s": %s',
                $this->source,
                $this->target,
                $e->getMessage()
            ), 'webmention');
        }
    }
}
