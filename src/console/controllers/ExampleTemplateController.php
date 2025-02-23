<?php

namespace matthiasott\webmention\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use matthiasott\webmention\Plugin;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\console\ExitCode;

/**
 * Copies the example template.
 */
class ExampleTemplateController extends Controller
{
    /**
     * @inheritdoc
     */
    public $defaultAction = 'copy';

    /**
     * @var string|null Name of the target folder the templates will be copied to.
     */
    public ?string $folderName = null;

    /**
     * @var bool Whether to overwrite an existing folder. Must be passed if a folder with that name already exists.
     */
    public bool $overwrite = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return [
            ...parent::options($actionID),
            'folderName',
            'overwrite',
        ];
    }

    /**
     * Copies the example template.
     *
     * @throws ErrorException
     * @throws Exception
     */
    public function actionCopy(): int
    {
        $sourcePath = sprintf('%s/matthiasott/webmention/example-template', Craft::$app->path->getVendorPath());

        if (isset($this->folderName)) {
            $folderName = $this->folderName;
        } else {
            $this->output($this->markdownToAnsi('A folder will be copied to your `templates/` folder.'));
            $folderName = $this->prompt('Choose folder name:', [
                'default' => Plugin::getInstance()->settings->endpointSlug,
            ]);
        }

        $targetPath = sprintf('%s/%s', Craft::$app->path->getSiteTemplatesPath(), $folderName);

        if (file_exists($targetPath)) {
            if (!$this->overwrite) {
                $this->output($this->markdownToAnsi("$folderName already exists. Pass `--overwrite` to replace it."));
                return ExitCode::UNSPECIFIED_ERROR;
            }

            if (is_dir($targetPath)) {
                FileHelper::removeDirectory($targetPath);
            } else {
                FileHelper::unlink($targetPath);
            }
        }

        FileHelper::copyDirectory($sourcePath, $targetPath);

        if (!is_dir($targetPath)) {
            $this->stdout("Could not copy the folder.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Done!\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
}
