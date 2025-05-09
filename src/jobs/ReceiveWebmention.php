<?php

namespace matthiasott\webmention\jobs;

use Craft;
use craft\queue\BaseJob;
use yii\base\Exception;
use matthiasott\webmention\Plugin;

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

            // Validate first
            $service = Plugin::getInstance()->webmentions;
            $html = $service->validateWebmention($this->source, $this->target);

            if (!$html) {
                Craft::info('FALSE!', 'webmention');
                throw new Exception('Job canceled. No backlink found in source.');
            }

            $webmention = $service->parseWebmention($html, $this->source, $this->target);
            if (!$webmention) {
                throw new Exception('Job canceled. Unable to parse webmention.');
            }

            Craft::$app->getElements()->saveElement($webmention);

        } catch (Exception $e) {
            Craft::error('Job failed: ' . $e->getMessage(), __METHOD__);
            throw $e;
        }

    }
}
