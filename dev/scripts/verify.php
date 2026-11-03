<?php

/**
 * Integration checks that need a real Craft install, HTTP and a database. The
 * scripts in ../tests cover what doesn't. Exits non-zero if a check fails.
 *
 * Each run makes its own entries and sources, so it can be re-run at any time.
 * Links in test entries use example.invalid, and queued sends are deleted before
 * the queue runs, so nothing here contacts another server.
 */

require __DIR__ . '/bootstrap.php';

use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\mcp\capabilities\Elements as McpElements;
use craft\mcp\ElementQueryFactory;
use craft\mcp\schema\CustomFieldSchema;
use craft\models\Site;
use craft\services\Mcp;
use craft\web\View;
use GuzzleHttp\RequestOptions;
use matthiasott\webmention\elements\Webmention;

$failures = 0;

function section(string $title): void
{
    echo "\n{$title}\n";
}

function check(bool $passed, string $label): void
{
    global $failures;
    echo($passed ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$passed) {
        $failures++;
    }
}

/**
 * @return array<int,array{id:int|string,targetId:int|string|null,targetSiteId:int|string|null}>
 */
function rowsForSource(string $source): array
{
    return (new Query())
        ->select(['id', 'targetId', 'targetSiteId'])
        ->from('{{%webmentions}}')
        ->where(['source' => $source])
        ->all();
}

function processQueue(): void
{
    clearQueuedSends();
    Craft::$app->getQueue()->run();
}

/**
 * Saves a live post on the given site and returns it.
 */
function makePost(Site $site, string $slug, mixed $switch = null, bool $setSwitch = true, string $title = 'Verify'): Entry
{
    $entries = Craft::$app->getEntries();
    $post = new Entry([
        'sectionId' => $entries->getSectionByHandle('blog')->id,
        'typeId' => $entries->getEntryTypeByHandle('post')->id,
        'siteId' => $site->id,
        'authorId' => 1,
        'slug' => $slug,
    ]);
    $post->title = $title;
    if ($setSwitch) {
        $post->setFieldValue('sendWebmentions', $switch);
    }
    if (!Craft::$app->getElements()->saveElement($post)) {
        fwrite(STDERR, 'Could not save post: ' . json_encode($post->getErrors()) . "\n");
        exit(1);
    }
    return $post;
}

function fetch(string $url): array
{
    $response = Craft::createGuzzleClient([RequestOptions::HTTP_ERRORS => false])->get($url);
    return [$response->getStatusCode(), (string)$response->getBody()];
}

Craft::$app->setEdition(CmsEdition::Pro);
$sites = Craft::$app->getSites();
$primary = $sites->getPrimarySite();
$second = $sites->getSiteByHandle('second');
if (!$second) {
    fwrite(STDERR, "The test site isn't seeded. Run `ddev setup` first.\n");
    exit(1);
}

$run = uniqid();
echo 'Craft ' . Craft::$app->getVersion() . ', PHP ' . PHP_VERSION . "\n";

section('Receiving over HTTP');
$postA = makePost($primary, "shared-$run", false);
$postB = makePost($second, "shared-$run", false);
$targetA = PRIMARY_URL . "blog/shared-$run";
$targetB = SECOND_URL . "blog/shared-$run";

$sourceA = sourceUrl($targetA, 'Verify Early', 'reply', '2026-02-01');
check(postWebmention($sourceA, $targetA) === 202, 'The endpoint accepts a webmention with HTTP 202');
check(postWebmention($sourceA, $targetA) === 202, 'A repeated webmention is accepted too');
check(postWebmention($sourceA, 'https://elsewhere.invalid/post', 'webmention-dev.ddev.site') === 400, 'A target on another host is rejected');
processQueue();
$rows = rowsForSource($sourceA);
check(count($rows) === 1, 'The repeated webmention creates one row');
check((int)($rows[0]['targetId'] ?? 0) === $postA->id, 'The webmention links to its entry');
$saved = Webmention::find()->source($sourceA)->one();
check($saved?->authorName === 'Verify Early', 'The author is parsed from the source page');
check($saved?->avatarId !== null, 'The avatar is downloaded');

section('Multi-domain sites');
$sourceB = sourceUrl($targetB, 'Verify Second');
postWebmention($sourceB, $targetB);
processQueue();
$rows = rowsForSource($sourceB);
check((int)($rows[0]['targetId'] ?? 0) === $postB->id, 'A target on the second domain links to the second site\'s entry');
check((int)($rows[0]['targetSiteId'] ?? 0) === $second->id, 'It records the second site as the target site');
$primaryPost = Entry::find()->id($postA->id)->siteId($primary->id)->one();
$authors = array_map(fn(Webmention $w) => $w->authorName, $primaryPost->getWebmentions());
check(!in_array('Verify Second', $authors, true), 'The primary site\'s entry with the same URI doesn\'t receive it');

section('Repairing webmentions saved without an entry');
$unlink = fn() => Craft::$app->getDb()->createCommand()
    ->update('{{%webmentions}}', ['targetId' => null, 'targetSiteId' => null], ['source' => $sourceB])
    ->execute();

$unlink();
(new matthiasott\webmention\jobs\ReceiveWebmention(['source' => $sourceB, 'target' => $targetB]))->execute(Craft::$app->getQueue());
$rows = rowsForSource($sourceB);
check(count($rows) === 1 && (int)$rows[0]['targetId'] === $postB->id, 'The Update action\'s job relinks the row without duplicating it');

$unlink();
$id = (int)$rows[0]['id'];
exec(sprintf('php %s/craft webmention/update --webmention-id=%d --interactive=0 2>&1', escapeshellarg(CRAFT_BASE_PATH), $id));
$rows = rowsForSource($sourceB);
check(count($rows) === 1 && (int)$rows[0]['targetId'] === $postB->id, 'The webmention/update command relinks the row without duplicating it');

section('Eager loading and avatars');
postWebmention(sourceUrl($targetA, 'Verify Late', 'reply', '2026-03-01'), $targetA);
postWebmention(sourceUrl($targetA, 'Verify Like', 'like', '2026-02-15'), $targetA);
processQueue();
$query = fn() => Entry::find()->id($postA->id)->siteId($primary->id);
$names = fn(array $webmentions) => array_map(fn(Webmention $w) => $w->authorName, $webmentions);

$post = $query()->with(['webmentions'])->one();
check($post->hasEagerLoadedElements('webmentions') && count($post->getWebmentions()) === 3, 'with([\'webmentions\']) loads all of them');
$post = $query()->with(['webmentions:like'])->one();
check($names($post->getWebmentionsByType('like')) === ['Verify Like'], 'with([\'webmentions:like\']) loads one type');
$post = $query()->with([['webmentions', ['count' => true]]])->one();
check($post->getTotalWebmentions() === 3, 'The count criteria gives the total');
$post = $query()->with(['webmentions.avatar'])->one();
check(!in_array(null, array_map(fn(Webmention $w) => $w->getAvatar()?->id, $post->getWebmentions()), true), 'with([\'webmentions.avatar\']) loads avatars');

if (version_compare(Craft::$app->getVersion(), '5.12', '>=')) {
    $post = $query()->with([['webmentions', ['orderBy' => 'webmentions.published desc']]])->one();
    check($names($post->getWebmentions())[0] === 'Verify Late', 'orderBy sorts eager-loaded webmentions, newest first');
    $post = $query()->with([['webmentions', ['orderBy' => 'webmentions.published asc']]])->one();
    check($names($post->getWebmentions())[0] === 'Verify Early', 'orderBy sorts eager-loaded webmentions, oldest first');
}

foreach ([$primary, $second] as $site) {
    $sites->setCurrentSite($site);
    $list = Webmention::find()->source([$sourceA, $sourceB])->all();
    check(count($list) === 2 && !in_array(null, array_map(fn(Webmention $w) => $w->getAvatar()?->id, $list), true), "Avatars load when the current site is {$site->handle}");
}
$sites->setCurrentSite($primary);

section('Templates');
$view = Craft::$app->getView();
$view->setTemplateMode(View::TEMPLATE_MODE_CP);
$twig = $view->getTwig();
$twig->setCache(false); // A cached template wouldn't report its deprecations
$deprecations = [];
set_error_handler(function(int $level, string $message) use (&$deprecations) {
    // Craft's own Twig setup triggers some of these on any template, without naming one.
    if (str_contains($message, '.twig')) {
        $deprecations[$message] = true;
    }
    return true;
}, E_USER_DEPRECATED);
foreach (['webmentions.twig', 'webmention-form.twig', 'index.twig', '_settings.twig', '_failures.twig'] as $template) {
    try {
        $twig->load("webmention/$template");
        check(true, "$template compiles");
    } catch (Throwable $e) {
        check(false, "$template compiles: " . $e->getMessage());
    }
}
restore_error_handler();
check($deprecations === [], 'The plugin\'s templates trigger no Twig deprecations' . ($deprecations ? ': ' . implode(' | ', array_keys($deprecations)) : ''));
$view->setTemplateMode(View::TEMPLATE_MODE_SITE);

[$status, $body] = fetch(internalUrl('webmention-dev.ddev.site', "blog/shared-$run"));
check($status === 200 && str_contains($body, 'Verify Early'), 'The primary site\'s post displays its webmentions');
[$status, $body] = fetch(internalUrl('webmention-dev-b.ddev.site', "blog/shared-$run"));
check($status === 200 && str_contains($body, 'Verify Second') && !str_contains($body, 'Verify Early'), 'The second site\'s post displays only its own webmentions');
[$status] = fetch(internalUrl('webmention-dev.ddev.site', 'webmention'));
check($status === 200, 'The endpoint page renders');

section('Webmention Switch (Default Value on)');
$link = fn() => 'See https://example.invalid/' . uniqid();
clearQueuedSends();
$post = makePost($primary, "default-$run", setSwitch: false, title: $link());
check($post->getFieldValue('sendWebmentions') === true && queuedSends() === 1, 'A new entry starts on and queues a send');
clearQueuedSends();
makePost($primary, "off-$run", false, title: $link());
check(queuedSends() === 0, 'Switching it off queues nothing');
clearQueuedSends();
$post = makePost($primary, "string-$run", 'false', title: $link());
check($post->getFieldValue('sendWebmentions') === false && queuedSends() === 0, 'The string "false" means off');

section('Resaving');
clearQueuedSends();
$post = makePost($primary, "resave-$run", true, title: $link());
check(queuedSends() === 1, 'Saving an entry with its switch on queues a send');
clearQueuedSends();
exec(sprintf('php %s/craft resave/entries --element-id=%d --interactive=0 2>&1', escapeshellarg(CRAFT_BASE_PATH), $post->id), $output, $exitCode);
check($exitCode === 0 && queuedSends() === 0, 'craft resave/entries queues nothing');

if (class_exists(Mcp::class)) {
    section('MCP');
    Craft::$app->getUser()->setIdentity(User::find()->admin()->one());
    $mcp = Craft::$app->get('mcp')->getCapabilityContainer();
    $entries = Craft::$app->getEntries();
    $attributes = fn() => [
        'sectionId' => $entries->getSectionByHandle('blog')->id,
        'typeId' => $entries->getEntryTypeByHandle('post')->id,
        'siteId' => $primary->id,
        'title' => $link(),
    ];

    $probe = new Entry(['sectionId' => $attributes()['sectionId'], 'typeId' => $attributes()['typeId'], 'siteId' => $primary->id]);
    $schema = $mcp->get(CustomFieldSchema::class)->forElement($probe)['properties']['sendWebmentions'] ?? [];
    check(in_array('boolean', (array)($schema['type'] ?? []), true), 'The switch is offered to agents as a boolean');

    $create = $mcp->get(McpElements::class);
    foreach ([['omitted', [], 1], ['true', ['sendWebmentions' => true], 1], ['false', ['sendWebmentions' => false], 0]] as [$label, $values, $expected]) {
        clearQueuedSends();
        $create->create('entry', $attributes(), $values);
        check(queuedSends() === $expected, "elements.create with the switch $label queues $expected send(s)");
    }

    clearQueuedSends();
    try {
        $create->create('entry', $attributes(), ['sendWebmentions' => 'false']);
        $rejected = false;
    } catch (Throwable) {
        $rejected = true;
    }
    check($rejected && queuedSends() === 0, 'elements.create rejects the string "false"');

    $types = (new ElementQueryFactory(Craft::$app->getElements()))->registeredTypes();
    check(!in_array(Webmention::class, $types, true), 'Webmentions stay out of the generic MCP element tools, because their text is untrusted');
}

clearQueuedSends();

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
