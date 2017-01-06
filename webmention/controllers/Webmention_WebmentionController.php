<?php
namespace Craft;

// Mf2 microformats-2 parser -- https://github.com/indieweb/php-mf2
use Mf2;


class Webmention_WebmentionController extends BaseController
{
	protected $allowAnonymous = true;

    private function renderEndpoint()
    {
        $this->renderTemplate('webmention/_index');
    }

    /**
     *
     * Check the response type and either start handling the webmention or render the webmention endpoint.
     * 
     */
    public function actionHandleRequest()
    {
        if (craft()->request->isPostRequest) {
            $this->actionHandleWebmention();
        } else
        if (craft()->request->isGetRequest) {
            craft()->userSession->setError(Craft::t(""));
            craft()->userSession->setNotice(Craft::t(""));
            $this->renderEndpoint();
        }
    }

    /**
     *
     * Handle webmention
     * 
     */
    public function actionHandleWebmention()
    {   
        $this->requirePostRequest();

        /* Get source and target from post data */
        $src    = craft()->request->getPost('source');
        $target = craft()->request->getPost('target');

        /* Get settings */
        $settings = craft()->plugins->getPlugin('webmention')->getSettings();
        
        /* Validate first */
        $html = craft()->webmention->validateWebmention($settings, $src, $target);

        /* if valid parse HTML */
        if ($html) {
            $model = craft()->webmention->parseWebmention($html, $settings, $src, $target);
        } else {
            $this->renderEndpoint();
        }
        
        /* Now try to save it */
        if (craft()->webmention->saveWebmention($model)) {
            /* Success! Now update the frontend messages and render endpoint */
            craft()->userSession->setError(Craft::t(""));
            craft()->userSession->setNotice(Craft::t('Webmention saved. Thank you!'));
            craft()->userSession->setFlash('webmentionSaved', "Webmention saved!");
            if (function_exists('http_response_code')) {
                http_response_code(200);
            }
            $this->renderEndpoint();
            return $this->redirectToPostedUrl();
        } else {
            /* @todo: Provide more useful feedback / reason of failure */
            craft()->userSession->setError(Craft::t("Couldn't save webmention."));
        }
        
        $this->renderEndpoint();
            
    }
}