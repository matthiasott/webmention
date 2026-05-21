<?php

namespace matthiasott\webmention\console\controllers;

use craft\console\Controller;
use matthiasott\webmention\migrations\m260521_000000_backfill_parent_ids_bluesky;
use yii\console\ExitCode;

/**
 * Re-runs parentId resolution for existing webmentions.
 */
class BackfillController extends Controller
{
    /**
     * If true, prints the rows that would change without writing anything.
     */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['dryRun']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['d' => 'dryRun']);
    }

    /**
     * Re-resolves parentId for Bluesky webmentions whose `in-reply-to` at://
     * URI converts to a DID-based bsky.app URL but whose parent's hEntryUrl
     * was stored with the user's handle.
     *
     * Usage:
     *   php craft webmention/backfill/bluesky --dry-run
     *   php craft webmention/backfill/bluesky
     */
    public function actionBluesky(): int
    {
        $migration = new m260521_000000_backfill_parent_ids_bluesky();
        $migration->dryRun = $this->dryRun;

        $this->stdout($this->dryRun
            ? "Running Bluesky parentId backfill in dry-run mode...\n\n"
            : "Running Bluesky parentId backfill...\n\n");

        try {
            $migration->safeUp();
        } catch (\Throwable $e) {
            $this->stderr("ERROR: " . $e->getMessage() . "\n");
            return ExitCode::SOFTWARE;
        }

        if ($this->dryRun) {
            $this->stdout("\nDry run complete. No rows were modified.\n");
        }

        return ExitCode::OK;
    }
}
