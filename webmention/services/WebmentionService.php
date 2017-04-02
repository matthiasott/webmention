<?php

namespace Craft;

// Mf2 microformats-2 parser -- https://github.com/indieweb/php-mf2
use Mf2;

/**
 * Webmention Service
 *
 * Provides a consistent API for the plugin to access the database
 */
class WebmentionService extends BaseApplicationComponent
{
    protected $webmentionRecord;
    private $queue = array();

    public function addToQueue($endpoint, $source, $target) {
        array_push($this->queue, array(
            'endpoint' => $endpoint,
            'source' => $source,
            'target' => $target
        ));
    }

    /**
     * Create a new instance of the Webmention Service.
     * Constructor allows WebmentionRecord dependency to be injected to assist with unit testing.
     *
     * @param @webmentionRecord WebmentionRecord The ingredient record to access the database
     */
    public function __construct($webmentionRecord = null)
    {
        $this->webmentionRecord = $webmentionRecord;
        if (is_null($this->webmentionRecord)) {
            $this->webmentionRecord = Webmention_WebmentionRecord::model();
        }
    }

    /**
     * Get a new blank webmention
     * 
     * @param  array                           $attributes
     * @return Webmention_WebmentionModel
     */
    public function newWebmention($attributes = array())
    {
        $model = new Webmention_WebmentionModel();
        $model->setAttributes($attributes);

        return $model;
    }

    /**
     * Get all webmentions from the database.
     *
     * @return mixed
     */
    public function getAllWebmentions()
    {
        $records = $this->webmentionRecord->findAll(array('order'=>'t.id'));

        return Webmention_WebmentionModel::populateModels($records, 'id');
    }

    /**
     * Get all webmentions from the database.
     *
     * @return mixed
     */
    public function getAllWebmentionsForEntry($url)
    {
        //$Products = Product::model()->findAll($criteria);
        $records = $this->webmentionRecord->findAllByAttributes(array("target"=>$url),array('order'=>'id'));

        return Webmention_WebmentionModel::populateModels($records, 'id');
    }

    /**
     * Get a specific webmention from the database based on ID. If no webmention exists, null is returned.
     *
     * @param  int   $id
     * @return mixed
     */
    public function getWebmentionById($id)
    {
        if ($record = $this->webmentionRecord->findByPk($id)) {
            return Webmention_WebmentionModel::populateModel($record);
        }
    }

    /**
     * Get a specific webmention from the database based on the source url. If no webmention exists, null is returned.
     *
     * @param  int   $src
     * @return mixed
     */
    public function getWebmentionBySourceUrl($src)
    {
        if ($record = $this->webmentionRecord->findByAttributes(array("source"=>$src))) {
            return Webmention_WebmentionModel::populateModel($record);
        }
    }

    /**
     * Get a specific webmention from the database based on an array of attributes. If no webmention exists, null is returned.
     *
     * @param  array   $arr
     * @return mixed
     */
    public function getWebmentionByAttributes($arr)
    {
        if ($record = $this->webmentionRecord->findByAttributes($arr)) {
            return Webmention_WebmentionModel::populateModel($record);
        }
    }

    /**
     * Save a new or existing webmention back to the database.
     *
     * @param  Webmention_WebmentionModel $model
     * @return bool
     */
    public function saveWebmention(Webmention_WebmentionModel $model)
    {
        

        $isNewWebmention = !$model->getAttribute('id');

        if ($id = $model->getAttribute('id')) {
            if (null === ($record = $this->webmentionRecord->findByPk($id))) {
                throw new Exception(Craft::t('Can\'t find webmention with ID "{id}"', array('id' => $id)));
            }
        } else {
            $record = $this->webmentionRecord->create();
        }

        $record->setAttributes($model->getAttributes(), false);

        $success = craft()->elements->saveElement($model, false);

        if (!$success) {
            return array('error' => $model->getErrors());
        }

        if ($isNewWebmention) {
            $record->id = $model->id;
        }

        if ($record->save(false)) {
            // update id on model (for new records)
            $model->setAttribute('id', $record->getAttribute('id'));

            return true;
        } else {
            $model->addErrors($record->getErrors());

            return false;
        }
    }

    /**
     * Delete a webmention from the database.
     *
     * @param  int $id
     * @return int The number of rows affected
     */
    public function deleteWebmentionById($id)
    {
        return $this->webmentionRecord->deleteByPk($id);
    }

    /**
     * Delete a webmention from the database.
     *
     * @param  int $id
     * @return int The number of rows affected
     */
    public function deleteWebmentions($ids)
    {
            foreach ($ids as $id)
            {
                $this->webmentionRecord->deleteByPk($id);
            }
        return true;
    }

    /**
     * Check a webmention for typical structures of a brid.gy webmention and update $results array accordingly.
     * 
     * @param string $src
     * @return string
     */
    private function _checkResponseType(&$result, $entry, $src, $useBridgy) {

        /* Check for brid.gy first */
        if(!empty($src) and ($useBridgy == true) and (preg_match('!http(.*?)://brid-gy.appspot.com!', $src) or preg_match('!http(.*?)://brid.gy!', $src))) {

            /* Is it Twitter? */
            if(!empty($result['url']) and preg_match('!http(.*?)://twitter.com/(.*?)/status!', $result['url'])) {
                $result['site'] = 'twitter';
            }
            /* Is it The Facebook? */
            if(!empty($result['url']) and preg_match('!http(.*?)facebook.com!', $result['url'])) {
                $result['site'] = 'facebook';
            }
            /* Is it Instagram? */
            if(!empty($result['url']) and preg_match('!http(.*?)instagram.com!', $result['url'])) {
                $result['site'] = 'instagram';
            }
            /* Or even G+? */
            if(!empty($result['url']) and preg_match('!http(.*?)plus.google.com!', $result['url'])) {
                $result['site'] = 'googleplus';
            }
            /* Flickr? */
            if(!empty($result['url']) and preg_match('!http(.*?)flickr.com!', $result['url'])) {
                $result['site'] = 'flickr';
            }

            /* Get the type of mention from brid.gy URL */
            if(preg_match('/post/', $src)) {
                $result['type'] = 'mention';
            }
            if(preg_match('/comment/', $src)) {
                $result['type'] = 'comment';
            }
            if(preg_match('/like/', $src)) {
                $result['type'] = 'like';
            }
            if(preg_match('/repost/', $src)) {
                $result['type'] = 'repost';
            }
            if(preg_match('/rsvp/', $src)) {
                $result['type'] = 'rsvp';
            }
        } else {
            if (isset($entry['properties']['like-of']) || isset($entry['properties']['like'])) {
                $result['type'] = 'like';
            }
            if (isset($entry['properties']['repost-of']) || isset($entry['properties']['repost'])) {
                $result['type'] = 'repost';
            }
        }
    }

    /**
     * Get either a Gravatar URL or complete image tag for a specified email address.
     *
     * @param string $email The email address
     * @param string $s Size in pixels, defaults to 80px [ 1 - 2048 ]
     * @param string $d Default imageset to use [ 404 | mm | identicon | monsterid | wavatar ]
     * @param string $r Maximum rating (inclusive) [ g | pg | r | x ]
     * @param boole $img True to return a complete IMG tag False for just the URL
     * @param array $atts Optional, additional key/value attributes to include in the IMG tag
     * @return String containing either just a URL or a complete image tag
     * @source http://gravatar.com/site/implement/images/php/
     */
    private function _get_gravatar( $email, $s = 80, $d = 'mm', $r = 'g', $img = false, $atts = array() ) {
        $url = 'https://www.gravatar.com/avatar/';
        $url .= md5( strtolower( trim( $email ) ) );
        //$url .= "?s=$s&d=$d&r=$r";
        if ( $img ) {
            $url = '<img src="' . $url . '"';
            foreach ( $atts as $key => $val )
                $url .= ' ' . $key . '="' . $val . '"';
            $url .= ' />';
        }
        return $url;
    }

    /**
     * Validate a Webmention
     * Check if source URL is valid and if it contains a backlink to the target
     *
     * @param Array $settings Webmention plugin settings
     * @param String $src The source URL
     * @param String $target The target URL
     * @return String The HTML of the valid Webmention source
     *
     */
    public function validateWebmention( $settings, $src, $target ) {

        /* Source and target must not match! */
        if ($src == $target){
            craft()->userSession->setError(Craft::t("The URLs provided for source and target must not match."));
            /* Stop and render endpoint */
            if (function_exists('http_response_code')) {
                http_response_code(400);
            }
            return false;
        }

        /* First check if both source and target are http(s) */
        if (!(substr($src, 0, 7) == 'http://' || substr($src, 0, 8) == 'https://') && (substr($target, 0, 7) == 'http://' || substr($target, 0, 8) == 'https://')) { 
            craft()->userSession->setError(Craft::t("The URLs provided do not match the required scheme (Must be http or https)."));
            /* Stop and render endpoint */
            if (function_exists('http_response_code')) {
                http_response_code(400);
            }
            return false;      
        }

        /* Get HTML content */
        $html = @file_get_contents($src);

        if($html === FALSE) {
            craft()->userSession->setError(Craft::t("Specified source URL not found."));
            if (function_exists('http_response_code')) {
                http_response_code(400);
            }
            return false;        
        }
        
        /* and go find a backlink */
        $found = false;
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true); # suppress parse errors and warnings
        $body = mb_convert_encoding($html, 'HTML-ENTITIES', mb_detect_encoding($html));
        @$doc->loadHTML($body, LIBXML_NOWARNING|LIBXML_NOERROR);
        libxml_clear_errors();
        $xpath = new \DOMXPath($doc);
        
        foreach($xpath->query('//a[@href]') as $href) {
            $url = $href->getAttribute('href');
            $longurl = "";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            $a = curl_exec($ch);
            if(preg_match('#Location: (.*)#', $a, $r))
            $longurl = trim($r[1]);
          if($url == $target || $longurl == $target) {
            // FOUND THE LINK!
            $found = true;
            break;
          }
        }

        if(empty($found)) {
            craft()->userSession->setError(Craft::t("It seems like the source you provided does not include a link to the target."));
            // Stop and render endpoint 
            if (function_exists('http_response_code')) {
                http_response_code(400);
            }
            return false;        
        }

        return $html;
    }

    /**
     * Parse HTML of a source and populate model
     *
     * @param String $html The HTML of the source
     * @param Array $settings Webmention plugin settings
     * @param String $src The source URL
     * @param String $target The target URL
     * @return Webmention_WebmentionModel Webmention Model
     *
     */
    public function parseWebmention($html, $settings, $src, $target) {
        
        /* XSS Protection */

        /* Decode entities: E.g. converts &#00060script> into <script> */
        $convmap = array(0x0, 0x2FFFF, 0, 0xFFFF);
        $html = mb_decode_numericentity($html, $convmap, 'UTF-8');
        $html = mb_convert_encoding($html, 'HTML-ENTITIES');
        $html = htmlspecialchars_decode($html);
        $html = preg_replace('~&#([0-9]+)~', "&#\\1;", $html);
        $html = preg_replace('~(?!.*;$)&#x([0-9a-fA-F]+)~i', "&#x\\1;", $html);
        $html = html_entity_decode($html, ENT_QUOTES, "utf-8");

        /* HTMLPurifier doesn't know HTML5 tags, so we'll replace the structural tags (http://developers.whatwg.org/sections.html) with div tags 
        This is a working workaround :) */
        $html = preg_replace('/(<|\/)(section|article|nav|aside|hgroup|header|footer|address)(\s|>)/i', '$1div$3', $html);

        /* Purify HTML with Yii's HTMLPurifier wrapper */
        $purifier = new \CHtmlPurifier();
        $purifier->options = array('URI.AllowedSchemes'=>array(
              'http' => true,
              'https' => true,
            ));
        $html = $purifier->purify($html);

        /* Now the HTML is ready to be parsed with Mf2 */
        $parsed = Mf2\parse($html, $src);

        /* Let's look up where the h-entry is and use this array */
        foreach($parsed['items'] as $item){
          if ( in_array('h-entry', $item['type']) || in_array('p-entry', $item['type'])) {
             $entry = $item;
          }
        }

        /* Parse comment – with max text length from settings */
        $maxLength = $settings->maxTextLength;
        $result = \IndieWeb\comments\parse($entry, $src, $maxLength, 100);

        if(empty($result)) {
          throw new Exception('Probably spam');
        }

        /* Determine the type of the repsonse */
        $this->_checkResponseType($result, $entry, $src, $settings->useBridgy);
        
        if (function_exists('http_response_code')) {
            http_response_code(202);
        }

        /* Get h-card and use data for author etc. if not present in h-entry */
        $representative = Mf2\HCard\representative($parsed, $src);

        /* If the source url doesn't give us a representative h-card, try to get one for author url from parsed html */
        if ($representative == null){
            $representative = Mf2\HCard\representative($parsed, $result['author']['url']);
        }
        /* If this also doesn't work, maybe the h-card can be found in the parsed HTML directly */
        if ($representative == null){
            foreach($parsed['items'] as $item){
              if ( in_array('h-card', $item['type'])) {
                 $representative = $item;
              }
            }
        }

        /* If author name is empty use the one from the representative h-card */
        if(empty($result['author']['name'])){
            if ($representative){
                $result['author']['name'] = $representative['properties']['name'][0];
            }
        }
        /* If author url is empty use the one from the representative h-card */
        if(empty($result['author']['url'])){
            if ($representative){
                $result['author']['url'] = $representative['properties']['url'][0];
            }
        }
        /* If url is empty use source url */
        if(empty($result['url'])){
            $result['url'] = $src;
        }
        /* Use domain if 'site' ∉ {twitter, facebook, googleplus, instagram, flickr} */
        if(empty($result['site'])){
            $result['site'] = parse_url($result['url'], PHP_URL_HOST);
        }
        /* If no author photo is defined, check gravatar for image */
        if(empty($result['author']['photo'])){
            if ($representative['properties']['photo'][0]) {
                $result['author']['photo'] = $representative['properties']['photo'][0];
            } else {
                $email = $representative['properties']['email'][0];
                if($email){
                    $email = rtrim(str_replace('mailto:', '', $email));
                    $gravatar = $this->_get_gravatar($email);
                    $result['author']['photo'] = $gravatar . ".jpg";
                }
            }
        }

        /* Author photo should be saved locally to avoid exploits.
        So if an author photo is available get the image and save it to assets */

        if ($result['author']['photo']) {
            /* get remote image and store in temp path with a hashed filename */
            $hashedFileName = sha1(pathinfo($result['author']['photo'], PATHINFO_FILENAME));
            $fileExtension = (pathinfo($result['author']['photo'], PATHINFO_EXTENSION));
            $fileName = $hashedFileName . "." . $fileExtension;
            $tempPath = craft()->path->getAssetsTempSourcePath() . $fileName;
            $response = \Guzzle\Http\StaticClient::get($result['author']['photo'], array(
                'save_to' => $tempPath
            ));

            /* If it's an image, cleanse it of any malicious scripts that may be embedded */
            /* (recommended unless you completely trust everyone that’s uploading images) */
            $ext = IOHelper::getExtension($tempPath);
            if (ImageHelper::isImageManipulatable($ext) && $ext != 'svg')
            {
                craft()->images->cleanImage($tempPath);
            }

            /* Find the target folder */
            $avatarFolder = $settings->avatarPath;
            /* Add trailing slash */
            if (substr($avatarFolder, -1) != "/") {
                $avatarFolder = $avatarFolder . "/";
            }
            $folder = craft()->assets->findFolder(array(
                'path'     => $avatarFolder
            ));

            /* If the folder doesn't exist yet, create it */
            if(empty($folder)) {
                craft()->assets->createFolder(1, trim($avatarFolder, "/"));
                $folder = craft()->assets->findFolder(array(
                    'path'     => $avatarFolder
                ));
            }

            /* Save avatar to asset folder */
            $response = craft()->assets->insertFileByLocalPath(
                $tempPath,
                $fileName,
                $folder->id,
                AssetConflictResolution::Replace
            );
            if ($response->isSuccess()) {
                $fileId = $response->getDataItem('fileId');
                $file = craft()->assets->getFileById($fileId);
                $result['author']['photo'] = craft()->assets->getUrlForFile($file);
            }
        }
        
        /* Check if webmention for combination of src and target exists */
        if (craft()->webmention->getWebmentionByAttributes(array("source"=>$src, "target"=>$target))) {
            /* we will want to update the existing webmention */
            $model = craft()->webmention->getWebmentionBySourceUrl($src);
        } else {
            /* create new webmention */
            $model = craft()->webmention->newWebmention();
        }

        /* assign attributes */
        $webmentionAttrs = array(
            'author_name'  => $result['author']['name'],
            'author_photo'  => $result['author']['photo'],
            'author_url'  => $result['author']['url'],
            'published'  => strtotime($result['published']),
            'name'  => $result['name'],
            'text'  => $result['text'],
            'target'  => $target,
            'source'  => $src,
            'url'  => $result['url'],
            'site'  => $result['site'],
            'type'  => $result['type']
        );

        /* apply attributes to model */
        $model->setAttributes($webmentionAttrs);

        return $model;
    }

    /**
     * Sync all existing entry types with settings
     *
     * @param array $settings An optional settings array
     * @return boolean
     *
     */
    public function syncEntryTypes($settings = null) {

        if ($settings == null) {
            $settings = craft()->plugins->getPlugin('webmention')->getSettings();
        }

        $entryTypes = $settings->entryTypes;
        
        // get all existing entry types
        $entryTypesExisting = [];

        foreach(craft()->sections->getAllSections() as $section) {

            foreach($section->getEntryTypes() as $entryType) {

                $entryTypesExisting[$entryType->handle] = ['checked' => true, 'label' => $entryType->name, 'handle' => $entryType->handle];
            }
        }

        // now diff and sync settings with existing entry types
        foreach ($entryTypes as $key => $value) {
            if (!array_key_exists($key, $entryTypesExisting)) {
                unset($entryTypes[$key]);
            }
        }

        foreach ($entryTypesExisting as $key => $value) {
            if (!array_key_exists($key, $entryTypes)) {
                $entryTypes[$key] = ['checked' => true, 'label' => $entryTypesExisting[$key]['label'], 'handle' => $key];
            }
        }

        // update $settings with new key / values
        $settings->entryTypes = $entryTypes;

        // save new settings
        $webmention = craft()->plugins->getPlugin( 'webmention' );
        $success = craft()->plugins->savePluginSettings( $webmention, $settings );

        if ($success) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Prepare sending of Webmentions for an entry on save and add them to task queue
     *
     * @param Event $event Craft's onSaveEntry event
     *
     */
    public function onSaveEntry($event) {

        // WebmentionPlugin::log('onSaveEntry.', LogLevel::Info, true);
        $settings = craft()->plugins->getPlugin('webmention')->getSettings();

        $fieldTypeName = craft()->fields->getFieldType('Webmention_WebmentionSwitch')->getName();

        $entry = $event->params['entry'];
        $entryType = $entry->getType()->handle;

        $targets = array();
        $values = array();
        $webmentionSetting = false;

        $str = "";

        // check if Webmention sending is allowed for this entry type (CP settings)
        // first check if entry type is known
        if (array_key_exists($entryType, $settings->entryTypes)) {
            
            // if, so check if sending is activated value
            if ($settings->entryTypes[$entryType]['checked'] == true) {
                $webmentionSetting = true;
            }   

        } else {
            // if not, then please sync the settings – a new entry type has appeared!
            $this->syncEntryTypes($settings);
        }

        // check if entry has Webmention sending disabled (overrides entry type settings from the CP)
        foreach ($entry->getFieldLayout()->getFields() as $fieldLayoutField) {
            $field = $fieldLayoutField->getField();

            if ($field->getFieldType()->name == $fieldTypeName) {
                $send = $entry->getFieldValue($field->handle);

                if ($send == 1) {
                    $webmentionSetting = true;
                } else {
                    $webmentionSetting = false;
                }
                break;
            }
        }

        // only send Webmentions if entry is enabled
        if ($entry->getStatus() == 'live' && $webmentionSetting == true) {
            $str = $str . $entry->title;
            // get all values from all fields
            foreach ($entry->getFieldLayout()->getFields() as $fieldLayoutField) {
                // get the FieldModel 
                $field = $fieldLayoutField->getField();
                $fieldhandle = $field->handle;
                $fieldcontent = $entry->$fieldhandle;

                if (is_string($fieldcontent)) {
                    $str = $str . $entry->$fieldhandle;
                } else if (is_bool($fieldcontent)) {
                    $str = $str . $entry->$fieldhandle;
                } else {
                    switch (get_class($fieldcontent)) {
                        case 'Craft\ElementCriteriaModel':
                            if (get_class($fieldcontent->getElementType()) == 'Craft\MatrixBlockElementType') {
                                foreach($fieldcontent as $block) {
                                    foreach ($block->getFieldLayout()->getFields() as $blockLayoutField){
                                        $class = get_class($blockLayoutField->getField()->getFieldType());
                                        if ($class == 'Craft\RichTextFieldType' || $class == 'Craft\PlainTextFieldType' || $class == '') {
                                            $nomen = $blockLayoutField->getField()->handle;
                                            $str = $str . $block->$nomen;
                                        }
                                    }
                                }
                            }
                            break;
                        case 'Craft\RichTextData':
                            $str = $str . $fieldcontent->getRawContent();
                            break;
                        default:
                            break;
                    }
                }
                
            }

            // Get the URLs!
            $targets = $this->_extractUrls( $str );

            // Add all targets to the queue
            foreach ($targets as $target) {
                // but only if the target has a Webmention endpoint
                if ($endpoint = craft()->webmention_sender->getEndpoint($target)) {
                    $this->addToQueue($endpoint, $entry->url, $target);
                } else {
                    // Do nothing
                }
            }

            // If the queue has elements, create a task. This will send the Webmentions asynchronously
            $rows = count($this->queue);

            if ($rows > 0) {
                craft()->tasks->createTask('Webmention', 'Sending Webmentions…', array(
                    'queue' => $this->queue,
                    'rows'  => $rows
                ));
            }
        }
    }

    /**
     * Extract valid URLs from a given string
     *
     * @param String $string
     * @return Array containing all valid URLs
     *
     */
    private function _extractUrls( $string ) {
        // https://regex101.com/r/LHqKuO/1
        preg_match_all("/(?:(?:https?|ftp):\/\/)(?:\S+(?::\S*)?@)?(?:(?!10(?:\.\d{1,3}){3})(?!127(?:\.\d{1,3}){3})(?!169\.254(?:\.\d{1,3}){2})(?!192\.168(?:\.\d{1,3}){2})(?!172\.(?:1[6-9]|2\d|3[0-1])(?:\.\d{1,3}){2})(?:[1-9]\d?|1\d\d|2[01]\d|22[0-3])(?:\.(?:1?\d{1,2}|2[0-4]\d|25[0-5])){2}(?:\.(?:[1-9]\d?|1\d\d|2[0-4]\d|25[0-4]))|(?:(?:[a-z\x{00a1}-\x{ffff}0-9]+-?)*[a-z\x{00a1}-\x{ffff}0-9]+)(?:\.(?:[a-z\x{00a1}-\x{ffff}0-9]+-?)*[a-z\x{00a1}-\x{ffff}0-9]+)*(?:\.(?:[a-z\x{00a1}-\x{ffff}]{2,})))(?::\d{2,5})?(?:\/[^\"\'\s]*)?/uix", $string, $post_links);

        $post_links = array_unique( array_map( 'html_entity_decode', $post_links[0] ) );

        return array_values( $post_links );
    }

    /**
     * Send a Webmention to a given Webmention endpoint
     *
     * @param String $endpoint The endpoint URL
     * @param String $source The source URL
     * @param String $target The target URL
     * @return Array containing the response of the HTTP request
     *
     */
    public function sendWebmentions($endpoint, $source, $target) {

        $body = http_build_query(array(
            'source' => $source,
            'target' => $target
        ));

        $ch = curl_init($endpoint);

        curl_setopt_array($ch, array(
            CURLOPT_HTTPHEADER => array('Content-type: application/x-www-form-urlencoded'),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true
        ));

        curl_exec($ch);

        $response = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // WebmentionPlugin::log($response, LogLevel::Info, true);

        curl_close($ch);

        return in_array($response, array(200, 202));
    }
}
