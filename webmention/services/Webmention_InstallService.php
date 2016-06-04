<?php 
namespace Craft;

class Webmention_InstallService extends BaseApplicationComponent
{

  function __construct()
  {
    craft()->config->set('devMode', false);
    craft()->config->maxPowerCaptain();
  }


  // Public Methods
  // =========================================================================

  /**
   * Installs Webmention
   *
   * @return null
   */
  public function run()
  {

    $primaryLocaleId = craft()->i18n->getPrimarySiteLocaleId();
    $error = null;

    try
    {
      $this->_copyTemplates();
    }
    catch(\Exception $e)
    {
      $error = 'An exception was thrown: '.$e->getMessage();
    }

    if ($error === null)
    {
      Craft::log('Finished installing Webmention',
       LogLevel::Info, true, 'application', 'Webmention');
      return true;
    }
    else
    {
      Craft::log('Failed installing Webmention: ' . $error,
       LogLevel::Error, true, 'application', 'Webmention');
      return false;
    }
  }

  /**
   * Copies the template files to templates folder
   *
   * @return null
   */
  private function _copyTemplates()
  {
    $webmentionFolder = trim(realpath(dirname(__FILE__)), 'services')
      .'resources/_templates/';
    $craftTemplateFolder = realpath(CRAFT_TEMPLATES_PATH);
    $webmentionTargetFolder = $craftTemplateFolder.'/webmention/';

    Craft::log('Creating webmention folder in templates directory.',
     LogLevel::Info, true, '_copyTemplates', 'Webmention');

    IOHelper::createFolder($webmentionTargetFolder,0755,true);

    Craft::log('Copying Webmention templates to templates/webmention directory.',
     LogLevel::Info, true, '_copyTemplates', 'Webmention');
    
    if (IOHelper::copyFolder($webmentionFolder, $webmentionTargetFolder, true))
    {
      Craft::log($webmentionFolder.' copied to '.$webmentionTargetFolder.' successfully.',
       LogLevel::Info, true, '_copyTemplates', 'Webmention');
    }
    else
    {
      Craft::log('Failed copying '.$webmentionFolder.' to '.$webmentionTargetFolder,
       LogLevel::Error, true, '_copyTemplates', 'Webmention');
    }
  }
}


?>