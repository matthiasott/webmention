<?php

namespace matthiasott\webmention\variables;

use Craft;
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
     * Gets all available webmentions for an entry
     *
     * @return Webmention[]
     */
    public function getAllWebmentionsForEntry(string $url): array
    {
        return Webmention::find()->target($url)->all();
    }

    /**
     * Get a specific webmention. If no webmention is found, returns null
     *
     * @param int $id
     * @return Webmention|null
     */
    public function getWebmentionById($id): ?Webmention
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

    public function showWebmentions($url): Markup
    {
        $html = Craft::$app->getView()->renderTemplate('webmention/webmentions.twig', [
            'url' => $url,
        ], View::TEMPLATE_MODE_CP);

        return Template::raw($html);
    }

    public function webmentionForm($url)
    {
        $html = Craft::$app->getView()->renderTemplate('webmention/webmention-form.twig', [
            'url' => $url,
        ], View::TEMPLATE_MODE_CP);

        return Template::raw($html);
    }
}
