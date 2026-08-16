<?php

namespace matthiasott\webmention\controllers;

use Craft;
use craft\helpers\Queue;
use craft\web\Controller;
use matthiasott\webmention\jobs\ReceiveWebmention;
use matthiasott\webmention\records\WebmentionFailure;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class FailuresController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('webmentions-view');

        $failures = WebmentionFailure::find()
            ->orderBy(['lastAttemptedAt' => SORT_DESC])
            ->all();

        return $this->renderTemplate('webmention/_failures', [
            'failures' => $failures,
        ]);
    }

    public function actionRetry(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requirePermission('webmentions-manage');

        $id = $this->request->getRequiredBodyParam('id');
        $failure = WebmentionFailure::findOne($id);

        if (!$failure) {
            throw new NotFoundHttpException('Failure record not found.');
        }

        Queue::push(new ReceiveWebmention([
            'source' => $failure->source,
            'target' => $failure->target,
        ]));

        $failure->delete();

        $this->setSuccessFlash(Craft::t('webmention', 'Webmention queued for retry.'));
        return $this->redirect('webmention/failures');
    }

    public function actionDismiss(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requirePermission('webmentions-manage');

        $id = $this->request->getRequiredBodyParam('id');
        $failure = WebmentionFailure::findOne($id);

        if (!$failure) {
            throw new NotFoundHttpException('Failure record not found.');
        }

        $failure->delete();

        $this->setSuccessFlash(Craft::t('webmention', 'Failed Webmention record dismissed.'));
        return $this->redirect('webmention/failures');
    }

    public function actionRetryAll(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requirePermission('webmentions-manage');

        foreach (WebmentionFailure::find()->all() as $failure) {
            /** @var WebmentionFailure $failure */
            Queue::push(new ReceiveWebmention([
                'source' => $failure->source,
                'target' => $failure->target,
            ]));
        }

        WebmentionFailure::deleteAll();

        $this->setSuccessFlash(Craft::t('webmention', 'All failed Webmentions queued for retry.'));
        return $this->redirect('webmention/failures');
    }

    public function actionDismissAll(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requirePermission('webmentions-manage');

        WebmentionFailure::deleteAll();

        $this->setSuccessFlash(Craft::t('webmention', 'All failed Webmention records dismissed.'));
        return $this->redirect('webmention/failures');
    }
}
