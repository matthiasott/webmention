<?php

namespace matthiasott\webmention;

use Craft;
use craft\base\Element;
use craft\base\Event;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\console\Controller as ConsoleController;
use craft\console\controllers\ResaveController;
use craft\elements\Entry;
use craft\events\DefineBehaviorsEvent;
use craft\events\DefineConsoleActionsEvent;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Entries;
use craft\services\Fields;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use matthiasott\webmention\behaviors\ElementBehavior;
use matthiasott\webmention\elements\Webmention;
use matthiasott\webmention\fields\WebmentionSwitch;
use matthiasott\webmention\models\Settings;
use matthiasott\webmention\services\Sender;
use matthiasott\webmention\services\Webmentions;
use matthiasott\webmention\variables\WebmentionVariable;
use yii\base\Event as YiiEvent;

/**
 * @property-read Sender $sender
 * @property-read Webmentions $webmentions
 * @property-read Settings $settings
 */
class Plugin extends BasePlugin
{
    public static function config(): array
    {
        return [
            'components' => [
                'sender' => Sender::class,
                'webmentions' => Webmentions::class,
            ],
        ];
    }

    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;
    public bool $hasReadOnlyCpSettings = true;
    public string $schemaVersion = '1.0.0.4';

    public function init(): void
    {
        parent::init();

        Event::on(Entry::class, Entry::EVENT_AFTER_SAVE, function(ModelEvent $event) {
            $this->webmentions->onSaveEntry($event);
        });

        Event::on(Entries::class, Entries::EVENT_AFTER_SAVE_SECTION, function() {
            $this->webmentions->syncEntryTypes();
        });

        Event::on(Entries::class, Entries::EVENT_AFTER_DELETE_SECTION, function() {
            $this->webmentions->syncEntryTypes();
        });

        Event::on(Entries::class, Entries::EVENT_AFTER_SAVE_ENTRY_TYPE, function() {
            $this->webmentions->syncEntryTypes();
        });

        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = WebmentionSwitch::class;
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                $this->settings->endpointSlug => 'webmention/webmention/handle-request',
            ];
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'settings/webmention' => ['template' => 'webmention/settings'],
            ];
        });

        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(YiiEvent $event) {
            $event->sender->set('webmention', WebmentionVariable::class);
        });

        Event::on(Element::class, Model::EVENT_DEFINE_BEHAVIORS, function(DefineBehaviorsEvent $event) {
            $event->behaviors['webmention'] = ElementBehavior::class;
        });

        Event::on(ResaveController::class, ConsoleController::EVENT_DEFINE_ACTIONS, static function(DefineConsoleActionsEvent $e) {
            $e->actions['webmentions'] = [
                'action' => function(): int {
                    /** @var ResaveController $controller */
                    $controller = Craft::$app->controller;
                    return $controller->resaveElements(Webmention::class);
                },
                'helpSummary' => 'Re-saves webmentions.',
            ];
        });
    }

    public function getCpNavItem(): ?array
    {
        return [
            ...parent::getCpNavItem(),
            'label' => Craft::t('webmention', 'Webmentions'),
        ];
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    public function getSettingsResponse(): mixed
    {
        $this->webmentions->syncEntryTypes();
        return parent::getSettingsResponse();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('webmention/_settings.twig', [
            'settings' => $this->settings,
            'readOnly' => !Craft::$app->config->general->allowAdminChanges,
        ]);
    }
}
