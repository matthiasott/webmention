<?php
namespace Craft;

// Mf2 microformats-2 parser -- https://github.com/indieweb/php-mf2
use Mf2;


class Webmention_WebmentionController extends BaseController
{
	protected $allowAnonymous = true;
    //$settings = craft()->plugins->getPlugin('webmention')->getSettings();


    /**
     * Check a webmention for typical structures of a brid.gy webmention and update $results array accordingly.
     * 
     * @param string $src
     * @return string
     */
    public function checkResponseType(&$result, $entry, $src, $useBridgy) {

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
            if (isset($entry[properties][like-of]) || isset($entry[properties][like])) {
                $result['type'] = 'like';
            }
            if (isset($entry[properties][repost-of]) || isset($entry[properties][repost])) {
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
    private function get_gravatar( $email, $s = 80, $d = 'mm', $r = 'g', $img = false, $atts = array() ) {
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


    private function renderEndpoint()
    {
        $this->renderTemplate('webmention/_index');
    }


    public function actionSaveWebmention()
    {
        // @todo: Evaluate if changing webmentions via the backend is a thing
    }

    /**
     *
     * Check the response type and either start handling the webmention or render the webmention endpoint.
     * 
     */
    public function actionHandleRequest()
    {
        if (craft()->request->isPostRequest) {
            $this->actionHandleWebmention();
        } else
        if (craft()->request->isGetRequest) {
            craft()->userSession->setError(Craft::t(""));
            craft()->userSession->setNotice(Craft::t(""));
            $this->renderEndpoint();
        }
    }

    /**
     *
     * Handle webmention
     * 
     */
    public function actionHandleWebmention()
    {   
        $this->requirePostRequest();


        /* Get settings */
        $settings = craft()->plugins->getPlugin('webmention')->getSettings();

        
        /* Get source and target from post data */
        $src    = craft()->request->getPost('source');
        $target = craft()->request->getPost('target');

        /* Source and target must not match! */
        if ($src == $target){
            craft()->userSession->setError(Craft::t("The URLs provided for source and target must not match."));
            /* Stop and render endpoint */
            if (function_exists('http_response_code')) {
                http_response_code(400);
            }
            $this->renderEndpoint();
        }

        /* First check if both source and target are http(s) */
        if (!(substr($src, 0, 7) == 'http://' || substr($src, 0, 8) == 'https://') && (substr($target, 0, 7) == 'http://' || substr($target, 0, 8) == 'https://')) { 
            craft()->userSession->setError(Craft::t("The URLs provided do not match the required scheme (Must be http or https)."));
            /* Stop and render endpoint */
            if (function_exists('http_response_code')) {
                http_response_code(400);
            }
            $this->renderEndpoint();
        }

        /* Get HTML content */
        $html = @file_get_contents($src);

        if($html === FALSE) {
            craft()->userSession->setError(Craft::t("Specified source URL not found."));
            if (function_exists('http_response_code')) {
                http_response_code(400);
            }
            $this->renderEndpoint();
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
          if($url == $target) {
            // FOUND THE LINK!
            //echo "Found the link\n";
            $found = true;
            break;
          }
        }

        if(!$found) {
            craft()->userSession->setError(Craft::t("It seems like the source you provided does not include a link to the target."));
            /* Stop and render endpoint */
            if (function_exists('http_response_code')) {
                http_response_code(400);
            }
            $this->renderEndpoint();
        }

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
        $this->checkResponseType($result, $entry, $src, $settings->useBridgy);
        
        if (function_exists('http_response_code')) {
            http_response_code(202);
        }

        /* Get h-card and use data for author etc. if not present in h-entry */
        $representative = Mf2\HCard\representative($parsed, $src);

        /* If the source url doesn't give us a representative h-card, try to get one for author url from parsed html */
        if ($representative == null){
            $representative = Mf2\HCard\representative($parsed, $result['author']['url']);
        }

        /* If author name is empty use the one from the representative h-card */
        if(!$result['author']['name']){
            $result['author']['name'] = $representative['properties']['name'][0];
        }
        /* If author url is empty use the one from the representative h-card */
        if(!$result['author']['url']){
            $result['author']['url'] = $representative['properties']['url'][0];
        }
        /* If url is empty use source url */
        if(!$result['url']){
            $result['url'] = $src;
        }
        /* Use domain if 'site' ∉ {twitter, facebook, googleplus, instagram, flickr} */
        if(!$result['site']){
            $result['site'] = parse_url($result['url'], PHP_URL_HOST);
        }
        /* If no author photo is defined, check gravatar for image */
        if(!$result['author']['photo']){
            if ($representative['properties']['photo'][0]) {
                $result['author']['photo'] = $representative['properties']['photo'][0];
            } else {
                $email = $representative['properties']['email'][0];
                if($email){
                    $email = rtrim(str_replace('mailto:', '', $email));
                    $gravatar = $this->get_gravatar($email);
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

        // echo '<pre>';
        // print_r($model);
        // echo '</pre>';
        
        /* Now try to save it */
        if (craft()->webmention->saveWebmention($model)) {
            /* Success! Now update the frontend messages and render endpoint */
            craft()->userSession->setError(Craft::t(""));
            craft()->userSession->setNotice(Craft::t('Webmention saved. Thank you!'));
            craft()->userSession->setFlash('webmentionSaved', "Webmention saved!");
            if (function_exists('http_response_code')) {
                http_response_code(200);
            }
            $this->renderEndpoint();
            return $this->redirectToPostedUrl();
        } else {
            /* @todo: Provide more useful feedback / reason of failure */
            craft()->userSession->setError(Craft::t("Couldn't save webmention."));
        }
        
        $this->renderEndpoint();
            
    }

    /**
     *
     * Delete webmention
     * 
     * @todo: Add functionality to delete a webmention in the backend
     */
    public function actionDeleteWebmention()
    {
        //
    }
}