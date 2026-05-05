<?php

namespace matthiasott\webmention\controllers;

use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use matthiasott\webmention\jobs\ReceiveWebmention;
use matthiasott\webmention\Plugin;
use yii\web\Response;

class WebmentionController extends Controller
{
    protected array|bool|int $allowAnonymous = true;
    public $enableCsrfValidation = false;

    /**
     * Check the response type and either start handling the webmention or render the webmention endpoint.
     */
    public function actionHandleRequest(): ?Response
    {
        if ($this->request->isPost) {
            $response = $this->actionHandleWebmention();

            $isFormSubmission = $this->request->getBodyParam('origin') === 'form';

            if ($isFormSubmission) {
                return $this->redirectToPostedUrl(null, UrlHelper::url($this->request->absoluteUrl, [
                    'success' => 1,
                ]));
            }

            return $response;
        }

        if ($this->request->isGet) {
            return $this->renderEndpoint();
        }

        return null;
    }

    /**
     * Handle webmention
     */
    public function actionHandleWebmention(): ?Response
    {
        $this->requirePostRequest();

        // Get source and target from post data
        $source = $this->request->getRequiredBodyParam('source');
        $target = $this->request->getRequiredBodyParam('target');

        $webmentions = \matthiasott\webmention\Plugin::getInstance()->webmentions;
        if (!$webmentions->safeUrl($source) || !$webmentions->safeUrl($target)) {
            return $this->asRaw('Invalid source or target URL')->setStatusCode(400);
        }

        $targetHost = parse_url($target, PHP_URL_HOST);

        if ($targetHost !== $this->request->getHostName()) {
            return $this->asRaw('Target does not belong to this site')->setStatusCode(400);
        }

        $cache = Craft::$app->cache;
        $pairKey = 'webmention:pair:' . hash('sha256', $source . '|' . $target);

        if ($cache->get($pairKey)) {
            // Already accepted this pair recently — don't queue a duplicate.
            return $this->asRaw('')->setStatusCode(202);
        }
        $cache->set($pairKey, true, 300);

        Queue::push(new ReceiveWebmention([
            'source' => $source,
            'target' => $target,
        ]));

        // Return 202 Accepted, according to https://www.w3.org/TR/webmention/#h-sender-notifies-receiver
        return $this->asRaw('')->setStatusCode(202);
    }

    private function renderEndpoint(): Response
    {
        $settings = Plugin::getInstance()->settings;
        return $this->renderTemplate("$settings->endpointSlug/_index.twig");
    }
}
