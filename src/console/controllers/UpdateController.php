<?php

namespace matthiasott\webmention\console\controllers;

use Craft;
use craft\console\Controller;
use craft\errors\InvalidElementException;
use craft\helpers\Db;
use matthiasott\webmention\elements\Webmention;
use matthiasott\webmention\Plugin;
use yii\base\Exception;
use yii\console\ExitCode;
use yii\helpers\Console;
use yii\web\NotFoundHttpException;

/**
 * Updates webmentions’ avatar asset relations.
 */
class UpdateController extends Controller
{
    /**
     * @var int|string|null The ID(s) of the webmentions to resave. Can be set to multiple comma-separated statuses.
     */
    public string|int|null $webmentionId = null;

    /**
     * @var string|null The source URL to search for. Can be set to multiple comma-separated statuses.
     */
    public ?string $source = null;

    /**
     * @var string|null The target URL to search for. Can be set to multiple comma-separated statuses.
     */
    public ?string $target = null;

    public function options($actionID): array
    {
        return [
            ...parent::options($actionID),
            'webmentionId',
            'source',
            'target',
        ];
    }

    public function actionIndex(): int
    {
        $query = Webmention::find();

        if ($this->webmentionId) {
            $query->id(is_int($this->webmentionId) ? $this->webmentionId : explode(',', $this->webmentionId));
        }

        if ($this->source) {
            $query->source(explode(',', $this->source));
        }

        if ($this->target) {
            $query->target(explode(',', $this->target));
        }

        $total = $query->count();

        if (!$total) {
            $this->stdout("No matching webmentions found.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout(sprintf("%s %s found.\n", Craft::$app->formatter->asDecimal($total), $total === 1 ? 'webmention' : 'webmentions'));
        if (!$this->confirm('Proceed?', true)) {
            return ExitCode::OK;
        }

        $webmentionsService = Plugin::getInstance()->webmentions;
        $elementsService = Craft::$app->elements;
        $i = 0;

        foreach (Db::each($query) as $webmention) {
            try {
                $this->do(
                    sprintf('    - [%s/%s] %s (%s)', $i++, $total, $webmention->source, $webmention->id),
                    function() use ($webmentionsService, $elementsService, $webmention) {
                        $html = $webmentionsService->validateWebmention($webmention->source, $webmention->target);
                        if (!$html) {
                            throw new NotFoundHttpException('Not found');
                        }
                        $webmention = $webmentionsService->parseWebmention($html, $webmention->source, $webmention->target);
                        if (!$webmention) {
                            throw new Exception('Unable to parse webmention');
                        }
                        if (!$elementsService->saveElement($webmention)) {
                            throw new InvalidElementException($webmention);
                        }
                    },
                );
            } catch (Exception) {
            }
        }

        $this->stdout("Finished updating webmentions!\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
