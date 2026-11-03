# Webmention test site

A disposable Craft CMS site for developing and testing the plugin locally, so nobody has to test on a real site. It's left out of the published package by `/dev export-ignore` in the repository's `.gitattributes`.

It runs on [DDEV](https://ddev.com) and uses the plugin code in this repository, so changes apply immediately.

## Getting started

```sh
cd dev
ddev start
ddev setup
ddev verify
```

| | |
|---|---|
| Primary site | <https://webmention-dev.ddev.site/blog/hello-world> |
| Second site, on its own domain | <https://webmention-dev-b.ddev.site/blog/hello-world> |
| Control panel | <https://webmention-dev.ddev.site/admin> |
| Login | `admin` / `webmention-dev` |

If your browser doesn't trust the HTTPS certificates, run `mkcert -install` once.

## Commands

| Command | What it does |
|---|---|
| `ddev setup` | Builds the site and adds sample content. Safe to re-run. |
| `ddev setup --fresh` | Deletes all test site data and rebuilds it. |
| `ddev verify` | Runs the integration checks. Safe to re-run. |
| `ddev webmention <url>` | Sends a test webmention and processes it. |
| `ddev craft <command>` | Runs a Craft command, such as `ddev craft queue/run`. |
| `ddev stop` | Stops the site and keeps its data. |

The scripts in [`../tests`](../tests) cover what doesn't need a running Craft site. Run those from the repository root with `php tests/test_<name>.php`.

## What's in the site

- **Two sites**, each at the root of its own domain, with a post at the same URI on both.
- **A Blog section** whose entries have a Webmention Switch field, with its default set to on.
- **An avatar volume** that lets each asset choose its sites, which is the default for new volumes since Craft 5.12.
- **Fake source pages** at `web/sources/source.php`, which link to any target you give them.

## Sending a test webmention

```sh
ddev webmention https://webmention-dev.ddev.site/blog/hello-world Jane reply
```

This creates a fake source page by Jane, sends the webmention to the real endpoint and processes the queue. The last two arguments are optional, and the type can be `reply`, `like` or `mention`.

## Testing other versions

```sh
# Another Craft version. Composer refuses versions with known security advisories
# unless you allow them. Undo it with `git checkout composer.json && ddev composer update`.
ddev composer config audit.block-insecure false
ddev composer require craftcms/cms:5.6.10 --with-all-dependencies --no-update
ddev composer update --with-all-dependencies

# Another checkout of the plugin, such as a git worktree or an unpacked tag
WEBMENTION_PLUGIN_PATH=/path/to/checkout ddev restart
ddev restart # back to this repository
```

Run `ddev setup --fresh` afterwards if the database no longer matches. `ddev verify` skips checks for features the installed Craft version doesn't have.
