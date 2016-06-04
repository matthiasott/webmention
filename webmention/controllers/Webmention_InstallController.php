<?php
namespace Craft;

class Webmention_InstallController extends BaseController
{
    public function actionRun()
    {
        $this->requirePostRequest();
        craft()->userSession->requireAdmin();

        if (craft()->webmention_install->run())
        {
            craft()->userSession->setNotice(Craft::t('Webmention for Craft Installed Successfully.'));
            $this->redirectToPostedUrl();
        }
        else
        {
            // Prepare a flash error message for the user.
            craft()->userSession->setError(Craft::t('Couldn’t Install Webmention for Craft. Check Craft Log.'));
            $this->redirectToPostedUrl();
        }
    }
}