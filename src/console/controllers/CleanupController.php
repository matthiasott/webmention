<?php

namespace matthiasott\webmention\console\controllers;

use craft\console\Controller;
use craft\helpers\Db;
use matthiasott\webmention\Plugin;
use matthiasott\webmention\records\WebmentionFailure;
use yii\console\ExitCode;

/**
 * Cleans up webmention failure records older than the configured retention period.
 */
class CleanupController extends Controller
{
    /**
     * Deletes failure records older than the configured failureRetentionDays setting.
     *
     * Usage: php craft webmention/cleanup/failures
     */
    public function actionFailures(): int
    {
        $days = Plugin::getInstance()->settings->failureRetentionDays;
        $cutoff = (new \DateTime())->modify("-{$days} days");

        $deleted = WebmentionFailure::deleteAll(
            ['<', 'dateCreated', Db::prepareDateForDb($cutoff)]
        );

        $this->stdout(sprintf(
            "Deleted %d failure record(s) older than %d days.\n",
            $deleted,
            $days,
        ));

        return ExitCode::OK;
    }
}
