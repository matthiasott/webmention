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
     * @param string|null $url
     * @return Webmention[]
     */
    public function getWebmentions(?string $url = null): array
    {
        return Webmention::find()
            ->target($url ?? Craft::$app->request->getAbsoluteUrl())
            ->all();
    }

    /**
     * Gets all available webmentions for an entry
     *
     * @param string|null $url
     * @return Webmention[]
     * @deprecated
     */
    public function getAllWebmentionsForEntry(?string $url = null): array
    {
        return $this->getWebmentions($url);
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
     * Gets all available webmentions for an element by type (e.g. `mention`, `like`, or `repost`)
     *
     * @param ElementInterface $element
     * @param string|null $type
     * @return Webmention[]
     */
    public function getWebmentionsForElementByType(ElementInterface $element, ?string $type = null): array
    {
        return Plugin::getInstance()->webmentions->getWebmentionsForElementByType($element, $type);
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

    /**
     * Returns the default template for showing webmentions
     *
     * @return Markup
     */
    public function showWebmentions(?string $url = null): Markup
    {
        $html = Craft::$app->getView()->renderTemplate('webmention/webmentions.twig', [
            'url' => $url ?? Craft::$app->request->getAbsoluteUrl(),
        ], View::TEMPLATE_MODE_CP);

        return Template::raw($html);
    }

    /**
     * Show the default webmention form template
     *
     * @return Markup
     */
    public function webmentionForm(?string $url = null)
    {
        $html = Craft::$app->getView()->renderTemplate('webmention/webmention-form.twig', [
            'url' => $url ?? Craft::$app->request->getAbsoluteUrl(),
        ], View::TEMPLATE_MODE_CP);

        return Template::raw($html);
    }
}
