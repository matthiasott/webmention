# Webmention Plugin for Craft CMS

This plugin provides a [Webmention](https://www.w3.org/TR/webmention/) endpoint for [Craft CMS](https://craftcms.com).

## Installation

1. Download & unzip the file and place the `webmention/` directory into your `craft/plugins/` directory.
2.  -OR- do a `git clone https://github.com/matthiasott/webmention.git` directly into your `craft/plugins` folder.  You can then update it with `git pull`.
3. In the Craft Control Panel go to Settings > Plugins and click the “Install” button next to “Webmention”.


## Configuration

### Webmention endpoint
In order to receive Webmentions, the Webmention endpoint for your site needs to be discoverable by the server sending the Webmention. So you will need to add the following line in the `<head>` section of your main layout template:

```
<link rel="webmention" href="{{ craft.webmention.endpointUrl }}" />
```

And/or you can set an HTTP Link header by adding this line to your main layout template:

```
{% header "Link: <" ~ craft.webmention.endpointUrl ~ ">; rel=\"webmention\"" %}
```

The plugin comes with a „human-friendly“ endpoint that will present a form with input fields for `source` and `target` to users visiting your sites endoint route. The Twig template for the Webmention endpoint will extend your standard template and is copied to `craft/templates/webmention/_index.html` on install. You can then adjust the template to your need. Note: Even if you define a different route for the endpoint, the plugin will still look for the template in this folder.

### Displaying Webmentions
To output all Webmentions for the current request URL, you can use the following helper in your templates:

```
{{ craft.webmention.showWebmentions(craft.request.url) }}
```

### Display a Webmention form for the current URL
You can output a form in your entry template that provides the user with the opportunity to send you the URL of a response.
Simply use this helper:
```
{{ craft.webmention.webmentionForm(craft.request.url) }}
```

## Craft Plugin Settings

The Webmention plugin comes with a settings page for the Craft backend. You can change the following options:

* **Webmention Endpoint Route (Slug)**    
Set the URL slug of your Webmention endpoint. Defaults to `webmention`, but you can insert anything that makes sense to you.

* **Webmention Endpoint Layout Template**    
The Twig template for the Webmention endpoint will extend your standard template. Tell the plugin which template to use. Default is `_layout`.

* **Maximum Length of Webmention Text**    
Set the maximum character count for summaries, comments and text excerpts from posts. Default: `420`

* **Parse Brid.gy Webmentions**    
Toggle if you want the plugin to parse [Brid.gy](https://brid.gy) Webmentions.

* **Avatar Storage Folder**     
The plugin saves user photos (avatars) for incoming Webmentions for better performance and to avoid exploits. You can set the name of the folder where user avatars will be stored. 
*Note: For now, this will create a new subfolder in your default assets folder. So there has to be at least one asset source defined! ;)*
Also, if you change this value, avatars that have been stored before won't be moved to the new path.

## Features

### Receiving Webmentions

When the plugin receives a Webmention, it performs several checks and then parses the source’s HTML with both [php-mf2](https://github.com/indieweb/php-mf2), a generic [microformats-2](http://microformats.org/wiki/microformats-2) parser, and Aaron Parecki’s [php-comments helper](https://github.com/indieweb/php-comments), which returns author info as well as truncated post text for an [h-entry](http://indiewebcamp.com/h-entry). The plugin will try to get all attributes for the data model from the parsed h-entry and also the representative h-card. If no user photo is provided, it will also try to get one from Gravatar as a fallback, using the author’s email from the h-card.

The following attributes are looked up:  

* `author_name`
* `author_photo`
* `author_url`
* `published`
* `name`
* `text`
* `target`
* `source`
* `url`
* `site`
* `type`

Lastly, the Webmention record is saved to the database. Already existing Webmentions (which is determined by a comparison of the `source` and `target` of the POST request) are updated in the database.

### XSS Protection
To prevent Cross Site Scripting (XSS) attacks, the HTML of the source first gets decoded (which for example converts `&#00060script>` into `<script>`) and is then purified with [CHTMLPurifier](http://www.yiiframework.com/doc/api/CHtmlPurifier), Yii’s wrapper for [HTML Purifier](http://htmlpurifier.org/), which “removes all malicious code with a thoroughly audited, secure yet permissive whitelist”.

### Brid.gy

You can use Brid.gy for receiving Webmentions for posts, comments, retweets, likes, etc. from Twitter, Instagram, Facebook, Flickr, and Google+. This plugin will understand the Webmention and set the 'type' of the Webmention accordingly. So if someone retweets a tweet with a URL you shared, the Webmention will be of the type 'retweet'. To determine the interaction type, the plugin looks at the brid.gy URL format, for more information on the different types of URLs visit [the section about source URLs on the brid.gy website](https://brid.gy/about#source-urls).

If you don't use Brid.gy you can easily deactivate the parsing in the plugin settings.

### HTTP Responses

The Webmention plugin validates and processes the request and then returns HTTP status codes for certain errors or the successful processing of the Webmention:
* If the URLs provided for `source` and `target` do not match an http(s) scheme, a **400 Bad Request** status code is returned.
* If the specified target URL is not found, a **400 Bad Request** status code is returned.
* Also, if the provided `source` is not linking back to `target`, the answer will be a resounding **400 Bad Request**!
* On success, the plugin responds with a status of **200 OK**.

**Note: Currently, the plugin does not process the Webmention verification asynchronously yet.**

## Thank You!
Thanks to everyone who helped me setting this up:
* [Aaron Parecki](https://aaronparecki.com/) (@aaronpk) for support and feedback – and also for the great work he does related to Webmention.
* [Bastian Allgeier](http://bastianallgeier.com) (@bastianallgeier) for allowing me to get highly inspired by his [Kirby Webmentions Plugin](https://github.com/bastianallgeier/kirby-webmentions)
* [Tom Arnold](https://www.webrocker.de/) (@webrocker) for relentlessly sending test Webmentions. ;)
* [Jeremy Keith](https://adactio.com) (@adactio) for the feedback and also for giving the initial spark.
* Everyone at the IndieWebCamp Düsseldorf 2016 and the IndieWeb Community

## Changelog

### 0.1.0

* First version

## Roadmap
* Process Webmentions asynchronously
* Provide an interface for managing Webmentions (deletion!) in the backend
* Provide an easy way to change how Webmentions are displayed (e. g. grouping y/n)
* …

## License 

Code released under [the MIT license](https://github.com/matthiasott/webmention/LICENSE).

## Author

Matthias Ott   
<mail@matthiasott.com>  
<https://matthiasott.com>  
<https://twitter.com/m_ott>
