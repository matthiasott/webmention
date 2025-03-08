<?php

namespace matthiasott\webmention\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Cp;
use craft\helpers\Db;
use yii\db\Schema;

class WebmentionSwitch extends Field
{
    public static function displayName(): string
    {
        return Craft::t('webmention', 'Webmention Switch');
    }

    public static function phpType(): string
    {
        return 'bool';
    }

    public static function icon(): string
    {
        return 'toggle-on';
    }

    public static function queryCondition(array $instances, mixed $value, array &$params): array
    {
        $valueSql = static::valueSql($instances);
        return Db::parseBooleanParam($valueSql, $value, $instances[0]->default, Schema::TYPE_JSON);
    }

    public static function dbType(): array|string|null
    {
        return Schema::TYPE_BOOLEAN;
    }

    public bool $default = false;

    public function getSettingsHtml(): ?string
    {
        return $this->settingsHtml(false);
    }

    public function getReadOnlySettingsHtml(): ?string
    {
        return $this->settingsHtml(true);
    }

    private function settingsHtml(bool $readOnly): string
    {
        return Cp::lightswitchFieldHtml([
            'label' => Craft::t('app', 'Default Value'),
            'id' => 'default',
            'name' => 'default',
            'on' => $this->default,
            'disabled' => $readOnly,
        ]);
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        return $this->inputHtmlInternal($value, false);
    }

    public function getStaticHtml(mixed $value, ?ElementInterface $element = null): string
    {
        return $this->inputHtmlInternal($value, true);
    }

    private function inputHtmlInternal(mixed $value, bool $static): string
    {
        $id = $this->getInputId();
        return Craft::$app->getView()->renderTemplate('_includes/forms/lightswitch.twig', [
            'id' => $id,
            'labelId' => $this->getLabelId(),
            'describedBy' => $this->describedBy,
            'name' => $this->handle,
            'on' => (bool)$value,
            'disabled' => $static,
        ]);
    }
}
