<?php

namespace matthiasott\webmention\console\controllers;

use craft\console\Controller;
use craft\db\Query;
use craft\helpers\Console;
use craft\helpers\Queue;
use matthiasott\webmention\jobs\ReceiveWebmention;
use matthiasott\webmention\records\Webmention;
use yii\console\ExitCode;

/**
 * Maintenance commands for the Webmention plugin.
 */
class WebmentionsController extends Controller
{
    /**
     * @var string SQL LIKE pattern matched against `authorName`. The default
     * targets the bad rows whose author was overwritten with an @-handle by the
     * old representative-h-card fallback. Use `%` as the wildcard.
     */
    public string $like = '@%';

    /**
     * @var string|null Optional SQL LIKE pattern matched against `target`, to
     * limit the refetch to a single post (e.g. `%/notes/streams-of-consciousness`).
     */
    public ?string $target = null;

    /**
     * @var bool List the affected webmentions without queueing anything.
     */
    public bool $dryRun = false;

    /**
     * @var int|null Optional cap on how many webmentions to queue.
     */
    public ?int $limit = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'like',
            'target',
            'dryRun',
            'limit',
        ]);
    }

    /**
     * Re-fetches and re-parses webmentions whose stored author name looks wrong,
     * so the corrected parser can overwrite the bad `authorName`/avatar in place.
     *
     * Each matching (source, target) pair is pushed onto the queue as a
     * ReceiveWebmention job — exactly what the CP "Update" action does, but in bulk.
     * The queue must be running (or run `php craft queue/run`) for the jobs to process.
     *
     *     php craft webmention/webmentions/refetch-bad-authors --dry-run
     *     php craft webmention/webmentions/refetch-bad-authors
     *     php craft webmention/webmentions/refetch-bad-authors --target=%/notes/streams-of-consciousness
     *
     * @return int
     */
    public function actionRefetchBadAuthors(): int
    {
        $query = (new Query())
            ->select(['id', 'source', 'target', 'authorName'])
            ->from(Webmention::tableName())
            // 4th arg `false` => use the pattern verbatim (no auto-wrapping in %…%)
            ->where(['like', 'authorName', $this->like, false])
            ->andWhere(['not', ['source' => null]])
            ->andWhere(['not', ['target' => null]])
            ->orderBy(['id' => SORT_ASC]);

        if ($this->target !== null) {
            $query->andWhere(['like', 'target', $this->target, false]);
        }

        if ($this->limit !== null) {
            $query->limit($this->limit);
        }

        $rows = $query->all();
        $count = count($rows);

        if ($count === 0) {
            $this->stdout("No webmentions matched authorName LIKE '{$this->like}'" .
                ($this->target !== null ? " AND target LIKE '{$this->target}'" : '') . ".\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("Matched {$count} webmention(s):\n\n", Console::FG_GREEN);
        foreach ($rows as $row) {
            $this->stdout(sprintf("  #%-6s %-30s %s\n",
                $row['id'],
                mb_strimwidth((string)$row['authorName'], 0, 30, '…'),
                $row['source'],
            ));
        }
        $this->stdout("\n");

        if ($this->dryRun) {
            $this->stdout("Dry run — nothing queued. Re-run without --dry-run to refetch.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        if (!$this->confirm("Queue {$count} ReceiveWebmention job(s) to refetch and re-parse these?")) {
            $this->stdout("Aborted.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $queued = 0;
        foreach ($rows as $row) {
            Queue::push(new ReceiveWebmention([
                'source' => $row['source'],
                'target' => $row['target'],
            ]));
            $queued++;
        }

        $this->stdout("Queued {$queued} job(s). Run `php craft queue/run` if your queue isn't already processing.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
}
