<?php

namespace matthiasott\webmention\console\controllers;

use craft\console\Controller;
use matthiasott\webmention\migrations\m250224_200159_avatar_ids;
use matthiasott\webmention\Plugin;
use yii\base\InvalidConfigException;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Updates webmentions’ avatar asset relations.
 */
class UpdateAvatarsController extends Controller
{
    public function actionIndex(): int
    {
        try {
            $avatarFolder = Plugin::getInstance()->webmentions->getAvatarFolder();
        } catch (InvalidConfigException) {
            $this->stdout(sprintf("%s\n", $this->markdownToAnsi('The plugin’s `avatarVolume` setting is set to an invalid volume. Set a new avatar location in the plugin’s settings.')));
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$avatarFolder) {
            $this->stdout("No avatar volume has been selected in the plugin’s settings yet.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $migration = new m250224_200159_avatar_ids();
        if (!$migration->up()) {
            $this->stdout("Unable to update webmention avatars for some reason.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Done!\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
