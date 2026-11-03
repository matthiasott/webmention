<?php

/**
 * Pretends to be someone else's website: an h-entry that links to any target.
 * Only for the local test site, which is never deployed.
 *
 *   source.php?target=<url>&name=Jane&type=reply&date=2026-01-31&id=abc
 *
 * `type` is reply, like or mention. `id` only makes the source URL unique.
 */

$param = fn(string $name, string $default = '') => htmlspecialchars((string)($_GET[$name] ?? $default), ENT_QUOTES);

if (!isset($_GET['target'])) {
    http_response_code(400);
    exit('Missing target');
}

$target = $param('target');
$name = $param('name', 'Test Author');
$date = $param('date', '2026-01-01');
$self = htmlspecialchars('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], ENT_QUOTES);
$relation = match ($_GET['type'] ?? 'mention') {
    'reply' => 'u-in-reply-to',
    'like' => 'u-like-of',
    default => '',
};
?>
<!doctype html>
<html lang="en">
<head><title>Source page</title></head>
<body>
  <article class="h-entry">
    <a class="u-url" href="<?= $self ?>">Permalink</a>
    <a class="p-author h-card" href="http://<?= htmlspecialchars($_SERVER['HTTP_HOST'], ENT_QUOTES) ?>/<?= urlencode($_GET['name'] ?? 'author') ?>">
      <img class="u-photo" src="http://<?= htmlspecialchars($_SERVER['HTTP_HOST'], ENT_QUOTES) ?>/sources/avatar.png" alt=""><?= $name ?>
    </a>
    <time class="dt-published" datetime="<?= $date ?>T12:00:00+00:00"><?= $date ?></time>
    <div class="e-content">A response from <?= $name ?> to <a class="<?= $relation ?>" href="<?= $target ?>">this post</a>.</div>
  </article>
</body>
</html>
