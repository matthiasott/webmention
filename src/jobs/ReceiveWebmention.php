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
        $service = Plugin::getInstance()->webmentions;

        try {
            // Validate first
            $html = $service->validateWebmention($this->source, $this->target);

            if (!$html) {
                throw new \RuntimeException(sprintf(
                    'Webmention validation failed: source "%s" does not contain a valid backlink to target "%s"',
                    $this->source,
                    $this->target
                ));
            }

            $webmention = $service->parseWebmention($html, $this->source, $this->target);
            if (!$webmention) {
                throw new \RuntimeException(sprintf(
                    'Unable to parse microformats data from source "%s" targeting "%s"',
                    $this->source,
                    $this->target
                ));
            }

            try {
                if (!Craft::$app->getElements()->saveElement($webmention)) {
                    throw new \RuntimeException(sprintf(
                        'Failed to save webmention element from source "%s": %s',
                        $this->source,
                        implode(', ', $webmention->getFirstErrors())
                    ));
                }
            } catch (\yii\db\IntegrityException $e) {
                // Race condition: another concurrent job inserted the same (source, target) first.
                // Re-query the existing row, copy our freshly-parsed attributes onto it, and save —
                // so the latest parse wins, matching the no-race "update existing" path in parseWebmention().
                $existing = \matthiasott\webmention\elements\Webmention::find()
                    ->source($webmention->source)
                    ->target($webmention->target)
                    ->one();
                if ($existing === null) {
                    throw new \RuntimeException('IntegrityException but no existing webmention found', 0, $e);
                }
                foreach ([
                    'authorName', 'avatarId', 'authorUrl', 'published', 'name', 'text',
                    'targetId', 'targetSiteId', 'hEntryUrl', 'host', 'type', 'rsvp',
                    'properties', 'parentId',
                ] as $attr) {
                    $existing->$attr = $webmention->$attr;
                }
                Craft::$app->getElements()->saveElement($existing);
                Craft::info("Resolved race condition for ({$webmention->source}, {$webmention->target}) — updated existing id={$existing->id}", __CLASS__);
                // Hand off the persisted element so resolveChildWebmentions below uses the correct id.
                $webmention = $existing;
            }

            // Success — clear any prior failure record for this source+target
            $service->deleteFailure($this->source, $this->target);

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

            $service->recordFailure($this->source, $this->target, $e);
        }
    }
}
