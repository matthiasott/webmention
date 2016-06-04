<?php

namespace Craft;

/**
 * Webmention Variable provides access to database objects from templates
 */
class WebmentionVariable
{   
    /**
     * Get plugin name
     *
     * @return string
     */
    public function getName()
    {
        $plugin = craft()->plugins->getPlugin('webmention');
        return $plugin->getName();
    }

    /**
     * Get plugin settings
     *
     * @return array
     */
    public function getSettings()
    {
        $plugin = craft()->plugins->getPlugin('webmention');
        return $plugin->getSettings();
    }
    /**
     * Gets all available webmentions for an entry
     *
     * @return array
     */
    public function getAllWebmentionsForEntry($url)
    {
        return craft()->webmention->getAllWebmentionsForEntry($url);
    }

    /**
     * Get all available webmentions
     *
     * @return array
     */
    public function getAllWebmentions()
    {
        return craft()->webmention->getAllWebmentions();
    }

    /**
     * Get a specific webmention. If no webmention is found, returns null
     *
     * @param  int   $id
     * @return mixed
     */
    public function getWebmentionById($id)
    {
        return craft()->webmention->getWebmentionById($id);
    }
    /**
     * Returns the full URL for the webmention endpoint
     *
     * @return string
     */
    public function endpointUrl()
    {
        return  craft()->getSiteUrl() . $this->getSettings()->endpointSlug;
    }
    public function showWebmentions($url){
        $settings = craft()->plugins->getPlugin('webmention')->getSettings();
        $oldPath = craft()->path->getTemplatesPath();

        $templateFile = 'webmentions';

        craft()->path->setTemplatesPath(craft()->path->getPluginsPath() . 'webmention/templates');

        $html = craft()->templates->render($templateFile, [ 'url' => $url ]);

        craft()->path->setTemplatesPath($oldPath);

        return new \Twig_Markup($html, craft()->templates->getTwig()->getCharset());
    }
    public function webmentionForm($url){
        $settings = craft()->plugins->getPlugin('webmention')->getSettings();
        $oldPath = craft()->path->getTemplatesPath();

        $templateFile = 'webmention-form';

        craft()->path->setTemplatesPath(craft()->path->getPluginsPath() . 'webmention/templates');

        $html = craft()->templates->render($templateFile, [ 'url' => $url ]);

        craft()->path->setTemplatesPath($oldPath);

        return new \Twig_Markup($html, craft()->templates->getTwig()->getCharset());
    }

}