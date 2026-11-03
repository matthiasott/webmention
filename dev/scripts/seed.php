<?php

/**
 * Creates the test site's content model and sample content. Safe to re-run:
 * it does nothing if the second site already exists.
 */

require __DIR__ . '/bootstrap.php';

use craft\elements\Entry;
use craft\enums\CmsEdition;
use craft\enums\PropagationMethod;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\PlainText;
use craft\fs\Local;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use craft\models\Volume;
use matthiasott\webmention\fields\WebmentionSwitch;
use matthiasott\webmention\Plugin;

function must(bool $saved, object $model): void
{
    if (!$saved) {
        fwrite(STDERR, 'Could not save ' . get_class($model) . ': ' . json_encode($model->getErrors()) . "\n");
        exit(1);
    }
}

$sites = Craft::$app->getSites();

if ($sites->getSiteByHandle('second', true)) {
    echo "Already seeded. Use `ddev setup --fresh` to start over.\n";
    exit(0);
}

// Multi-site needs the Pro edition, which is free on local domains.
Craft::$app->setEdition(CmsEdition::Pro);

// Two sites, each at the root of its own domain.
$primary = $sites->getPrimarySite();
$primary->name = 'Primary';
$primary->setBaseUrl(PRIMARY_URL);
must($sites->saveSite($primary), $primary);

$second = new Site([
    'groupId' => $primary->getGroup()->id,
    'name' => 'Second',
    'handle' => 'second',
    'language' => 'en',
    'hasUrls' => true,
    'baseUrl' => SECOND_URL,
    'primary' => false,
    'enabled' => true,
]);
must($sites->saveSite($second), $second);

// Avatars. New volumes let each asset choose its sites, so that case is the one covered.
$filesystems = Craft::$app->getFs();
$filesystem = $filesystems->createFilesystem([
    'type' => Local::class,
    'name' => 'Avatars',
    'handle' => 'avatars',
    'hasUrls' => true,
    'url' => '/volumes/avatars',
    'path' => CRAFT_BASE_PATH . '/web/volumes/avatars',
]);
must($filesystems->saveFilesystem($filesystem), $filesystem);

$volume = new Volume(['name' => 'Avatars', 'handle' => 'avatars']);
if (property_exists($volume, 'propagationMethod')) { // Craft 5.12+
    $volume->propagationMethod = PropagationMethod::Custom;
}
$volume->setFsHandle('avatars');
must(Craft::$app->getVolumes()->saveVolume($volume), $volume);

// Fields. The switch defaults to on, so saving a post queues sends unless it's turned off.
$fields = Craft::$app->getFields();
$switch = new WebmentionSwitch(['name' => 'Send Webmentions', 'handle' => 'sendWebmentions', 'default' => true]);
must($fields->saveField($switch), $switch);
$body = new PlainText(['name' => 'Body', 'handle' => 'body', 'multiline' => true]);
must($fields->saveField($body), $body);

$entries = Craft::$app->getEntries();
$postType = new EntryType(['name' => 'Post', 'handle' => 'post', 'hasTitleField' => true]);
$layout = new FieldLayout(['type' => Entry::class]);
$tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout, 'sortOrder' => 1]);
$tab->setElements([new EntryTitleField(), new CustomField($body), new CustomField($switch)]);
$layout->setTabs([$tab]);
$postType->setFieldLayout($layout);
must($entries->saveEntryType($postType), $postType);

// Posts exist per site, so the same URI can hold different entries on each domain.
$blog = new Section([
    'name' => 'Blog',
    'handle' => 'blog',
    'type' => Section::TYPE_CHANNEL,
    'propagationMethod' => PropagationMethod::None,
]);
$blog->setEntryTypes([$postType]);
$siteSettings = [];
foreach ([$primary, $second] as $site) {
    $siteSettings[$site->id] = new Section_SiteSettings([
        'siteId' => $site->id,
        'enabledByDefault' => true,
        'hasUrls' => true,
        'uriFormat' => 'blog/{slug}',
        'template' => 'blog/_entry',
    ]);
}
$blog->setSiteSettings($siteSettings);
must($entries->saveSection($blog), $blog);

// Plugin settings. Trusting ddev.site is what lets the plugin fetch the fake source
// pages, which resolve to private IPs. Don't use this site to test the SSRF guards.
$plugin = Plugin::getInstance();
$plugin->settings->avatarVolume = $volume->uid;
$plugin->settings->avatarPath = 'avatars/';
$plugin->settings->trustedSourceHosts = ['ddev.site'];
$plugin->settings->entryTypes[$postType->uid] = ['checked' => true, 'label' => 'Post', 'handle' => 'post'];
if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $plugin->settings->toArray())) {
    fwrite(STDERR, "Could not save the plugin settings\n");
    exit(1);
}

// The scripts have no end-of-request step to write project config, and other
// processes (web requests, the craft command) read it from those files.
Craft::$app->getProjectConfig()->saveModifiedConfigData();

// Sample posts, with sending off so that seeding sends nothing.
foreach ([$primary, $second] as $site) {
    $post = new Entry([
        'sectionId' => $blog->id,
        'typeId' => $postType->id,
        'siteId' => $site->id,
        'authorId' => 1,
        'slug' => 'hello-world',
    ]);
    $post->title = "Hello from the {$site->name} site";
    $post->setFieldValue('body', 'A sample post. Links to example.invalid never reach a real server.');
    $post->setFieldValue('sendWebmentions', false);
    must(Craft::$app->getElements()->saveElement($post), $post);
}

// Sample webmentions through the real endpoint.
$primaryPost = PRIMARY_URL . 'blog/hello-world';
$secondPost = SECOND_URL . 'blog/hello-world';
foreach ([
    [sourceUrl($primaryPost, 'Ada Reply', 'reply', '2026-01-10'), $primaryPost],
    [sourceUrl($primaryPost, 'Grace Reply', 'reply', '2026-01-20'), $primaryPost],
    [sourceUrl($primaryPost, 'Linus Like', 'like', '2026-01-15'), $primaryPost],
    [sourceUrl($secondPost, 'Margaret Reply', 'reply', '2026-01-25'), $secondPost],
] as [$source, $target]) {
    $status = postWebmention($source, $target);
    if ($status !== 202) {
        fwrite(STDERR, "Sample webmention to {$target} returned HTTP {$status}\n");
        exit(1);
    }
}
Craft::$app->getQueue()->run();

echo <<<TXT

Test site ready.
  Primary site:  {$primaryPost}
  Second site:   {$secondPost}
  Control panel: https://webmention-dev.ddev.site/admin (admin / webmention-dev)

TXT;
