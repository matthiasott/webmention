<?php
require __DIR__ . '/../vendor/autoload.php';

use craft\elements\Entry;
use craft\events\ModelEvent;
use matthiasott\webmention\services\Webmentions;

$failures = 0;

function assert_true(bool $result, string $label): void {
    global $failures;
    if (!$result) {
        echo "FAIL: {$label}\n";
        $failures++;
    } else {
        echo "PASS: {$label}\n";
    }
}

/**
 * Stands in for a live entry. getUrl() is the first thing onSaveEntry() needs
 * once it has decided the save is eligible for sending, so whether it was
 * called shows which side of that decision the save landed on. It returns null
 * so the method stops there, before it needs a running Craft application.
 */
class EntryDouble
{
    public bool $propagating = false;
    public bool $urlRequested = false;

    public function __construct(public bool $resaving)
    {
    }

    public function getIsDraft(): bool
    {
        return false;
    }

    public function getIsRevision(): bool
    {
        return false;
    }

    public function getStatus(): string
    {
        return Entry::STATUS_LIVE;
    }

    public function getUrl(): ?string
    {
        $this->urlRequested = true;
        return null;
    }
}

function saveEventFor(EntryDouble $entry): ModelEvent {
    $event = new ModelEvent();
    $event->sender = $entry;
    return $event;
}

$service = new Webmentions();

// `craft resave/entries` and resave jobs change nothing an author wrote, so they
// mustn't notify every site the entries link to.
$resaved = new EntryDouble(resaving: true);
$service->onSaveEntry(saveEventFor($resaved));
assert_true(!$resaved->urlRequested, 'A resave is not eligible for sending');

// Control: proves the double can tell the two cases apart.
$saved = new EntryDouble(resaving: false);
$service->onSaveEntry(saveEventFor($saved));
assert_true($saved->urlRequested, 'A normal save of a live entry is eligible for sending');

echo $failures === 0 ? "\nAll tests passed.\n" : "\n{$failures} test(s) failed.\n";
exit($failures === 0 ? 0 : 1);
