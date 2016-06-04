<?php 
namespace Craft;

class Webmention_UninstallService extends BaseApplicationComponent
{

  function __construct()
  {
    craft()->config->set('devMode', false);
    craft()->config->maxPowerCaptain();
  }


  // Public Methods
  // =========================================================================

  /**
   * Installs Webmention!
   *
   * @return null
   */
  public function run()
  {

    $this->_deleteTemplates();
    Craft::log('Finished uninstalling Webmention.', LogLevel::Info, true, 'application', 'Webmention');
    return true;
  }

  /**
   * Deletes template files from templates folder
   *
   * @return null
   */
  private function _deleteTemplates()
  {
    $craftTemplateFolder = realpath(CRAFT_TEMPLATES_PATH);
    $webmentionTargetFolder = $craftTemplateFolder.'/webmention';

    // Try nicely to delete files
    IOHelper::deleteFolder($webmentionTargetFolder, true);

    // If folder remains try to force it.
    if (is_dir($webmentionTargetFolder))
    {
      @system('/bin/rm -rf ' . escapeshellarg($webmentionTargetFolder));
    }
  }
}

?>