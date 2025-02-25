<?php

namespace matthiasott\webmention\variables;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use craft\web\View;
use matthiasott\webmention\elements\Webmention;
use matthiasott\webmention\Plugin;
use Twig\Markup;

/**
 * Webmention Variable provides access to database objects from templates
 */
class WebmentionVariable
{
    /**
     * Gets all available webmentions for a URL
     *
     * @param string $url
     * @return Webmention[]
     */
    public function getWebmentionsForUrl(string $url): array
    {
        return Webmention::find()
            ->target($url)
            ->all();
    }

    /**
     * Gets all available webmentions for an entry
     *
     * @param string $url
     * @return Webmention[]
     * @deprecated
     */
    public function getAllWebmentionsForEntry(string $url): array
    {
        return $this->getWebmentionsForUrl($url);
    }

    /**
     * Gets all available webmentions for an element
     *
     * @param ElementInterface $element
     * @return Webmention[]
     */
    public function getWebmentionsForElement(ElementInterface $element): array
    {
        return Plugin::getInstance()->webmentions->getWebmentionsForElement($element);
    }

    /**
     * Get a specific webmention. If no webmention is found, returns null
     *
     * @param int $id
     * @return Webmention|null
     */
    public function getWebmentionById(int $id): ?Webmention
    {
        return Webmention::findOne($id);
    }

    /**
     * Returns the full URL for the webmention endpoint
     *
     * @return string
     */
    public function endpointUrl(): string
    {
        return UrlHelper::siteUrl(Plugin::getInstance()->settings->endpointSlug);
    }

    public function showWebmentions(string $url): Markup
    {
        $html = Craft::$app->getView()->renderTemplate('webmention/webmentions.twig', [
            'url' => $url,
        ], View::TEMPLATE_MODE_CP);

        return Template::raw($html);
    }

    public function webmentionForm(string $url)
    {
        $html = Craft::$app->getView()->renderTemplate('webmention/webmention-form.twig', [
            'url' => $url,
        ], View::TEMPLATE_MODE_CP);

        return Template::raw($html);
    }
}
