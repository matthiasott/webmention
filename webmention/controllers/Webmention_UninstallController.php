<?php
namespace Craft;

class Webmention_UninstallController extends BaseController
{
    public function actionRun()
    {
        $this->requirePostRequest();
        craft()->userSession->requireAdmin();

        if (craft()->webmention_uninstall->run())
        {
            craft()->userSession->setNotice(Craft::t('Webmention Uninstall Successfully.'));
            $this->redirectToPostedUrl();
        }
        else
        {
            // Prepare a flash error message for the user.
            craft()->userSession->setError(Craft::t('Couldn’t Uninstall Webmention. Check Craft Log.'));
            $this->redirectToPostedUrl();

        }
    }
}