<?php
namespace Craft;

class WebmentionPlugin extends BasePlugin
{   
    function init() 
    {
        // Require dependencies (composer)
        require CRAFT_PLUGINS_PATH.'/webmention/vendor/autoload.php';

        craft()->on('entries.saveEntry', function(Event $event) {
            craft()->webmention->onSaveEntry($event);
        });

        # sections.onDeleteSection
        craft()->on('sections.onDeleteSection', function(Event $event) {
            craft()->webmention->syncEntryTypes();
        });
        # sections.onSaveSection
        craft()->on('sections.onSaveSection', function(Event $event) {
            craft()->webmention->syncEntryTypes();
        });
        # sections.onSaveEntryType
        craft()->on('sections.onSaveEntryType', function(Event $event) {
            craft()->webmention->syncEntryTypes();
        });
    }

    public function getName()
    {
         return Craft::t('Webmention');
    }

    public function getVersion()
    {
        return '0.1.0';
    }

    public function getDeveloper()
    {
        return 'Matthias Ott';
    }

    public function getDeveloperUrl()
    {
        return 'https://matthiasott.com';
    }
    public function getDocumentationUrl()
    {
    return 'https://github.com/matthiasott/webmention';
    }
    public function getDescription()
    {
        return 'Receive Webmentions and show them on your site.';
    }

    public function hasCpSection()
    {
        return true;
    }

    public function registerSiteRoutes()
    {   
        // Get endpoint slug from plugin settings
        $settings = craft()->plugins->getPlugin('webmention')->getSettings();
        $endpointSlug = $settings->endpointSlug;
        // Return route for webmention endpoint
        return array(
            $endpointSlug => array('action' => 'webmention/webmention/handleRequest'),
        );
    }
    protected function defineSettings()
    {   
        $entryTypes = [];

        foreach(craft()->sections->getAllSections() as $section) {

            foreach($section->getEntryTypes() as $entryType) {
                $entryTypes[$entryType->handle] = ['checked' => true, 'label' => $entryType->name, 'handle' => $entryType->handle];
            }
        }

        // Define Plugin Settings for the CP
        return array(
            'layout'  => array(AttributeType::String, 'required' => false, 'default' => '_layout'),
            'endpointSlug'      => array( AttributeType::String, 'label' => 'Webmention Endpoint Route (Slug)', 'default' => 'webmention' ),
            'maxTextLength'      => array( AttributeType::String, 'label' => 'Maximum length for Webmention text', 'default' => '420' ),
            'useBridgy'            => array( AttributeType::Bool, 'default' => true ),
            'avatarPath'      => array( AttributeType::String, 'label' => 'Avatar storage path', 'default' => 'avatars/' ),
            'entryTypes' => array( AttributeType::Mixed, 'default' => $entryTypes )
        );
    }
    public function getSettingsUrl()
    {
    return 'webmention/settings';
    }
    public function onBeforeInstall()
    {
        $craftTemplateFolder = realpath(CRAFT_TEMPLATES_PATH);

        if ((!IOHelper::isWritable($craftTemplateFolder)))
        {
          throw new Exception(Craft::t('Your Template folder is not writeable by PHP. '
            . 'Webmention needs PHP to have permissions to create template files. Give PHP write permissions to '
            . $craftTemplateFolder . ' and try to install again.'));
        }
    }
    public function onAfterInstall()
    {
        craft()->webmention_install->run();
    }
    public function onBeforeUninstall()
    {
        craft()->webmention_uninstall->run();
    }
}
