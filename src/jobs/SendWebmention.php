<?php
namespace matthiasott\webmention\jobs;

use Craft;
use craft\queue\BaseJob;
use matthiasott\webmention\Plugin;

class SendWebmention extends BaseJob
{
    public string $source;
    public string $target;

    protected function defaultDescription(): ?string
    {
        return Craft::t('webmention', 'Sending webmentions');
    }

    public function execute($queue): void
    {
        Plugin::getInstance()->sender->sendWebmention($this->source, $this->target);
    }
}
