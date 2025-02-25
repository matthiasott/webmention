<?php

namespace matthiasott\webmention\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;
use craft\helpers\Queue;
use matthiasott\webmention\elements\Webmention;
use matthiasott\webmention\jobs\ReceiveWebmention;

class Update extends ElementAction
{
    public function getTriggerLabel(): string
    {
        return Craft::t('webmention', 'Update');
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        foreach (Db::each($query) as $webmention) {
            /** @var Webmention $webmention */
            Queue::push(new ReceiveWebmention([
                'description' => Craft::t('webmention', 'Updating webmention'),
                'source' => $webmention->source,
                'target' => $webmention->target,
            ]));
        }

        return true;
    }

    public function getMessage(): ?string
    {
        return Craft::t('webmention', 'Webmention updates queued.');
    }
}
