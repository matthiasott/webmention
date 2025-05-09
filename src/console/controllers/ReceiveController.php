<?php

namespace matthiasott\webmention\console\controllers;

use Craft;
use craft\console\Controller;
use matthiasott\webmention\Plugin;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Updates webmentions’ avatar asset relations.
 */
class ReceiveController extends Controller
{
    public function actionIndex(string $source, string $target): int
    {
        // Validate first
        $service = Plugin::getInstance()->webmentions;
        $html = $service->validateWebmention($source, $target);

        if (!$html) {
            $this->stderr("Source didn’t validate.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $webmention = $service->parseWebmention($html, $source, $target);
        if (!$webmention) {
            $this->stderr("Couldn’t parse webmention.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!Craft::$app->getElements()->saveElement($webmention)) {
            $this->stderr(sprintf("Couldn’t save webmention: %s\n", implode(', ', $webmention->getFirstErrors())), Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Webmention processed successfully.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
}
