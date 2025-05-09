# Release Notes for Webmention for Craft CMS

## 1.0.3 – 2025-05-09
- `authorName` values now use the h-card’s `nickname` property as a fallback. ([#10](https://github.com/matthiasott/webmention/pull/10))
- Fixed a bug where webmention validation wasn’t catching `ConnectException` errors.
- Fixed a bug where jobs for webmentions whithou a valid backlink to the target got stuck in the queue

## 1.0.2 – 2025-03-21
- Fixed a bug where getting the the avatar photo from the parsed representative h-card would fail because the URL was the value inside of an array instead of being a string. Now the plugin supports both cases.

## 1.0.1 – 2025-03-15
- Added `avatarId`, `host`, and `properties` as optional table attributes
- Fixed Bluesky (via Bridgy) avatars: if an avatar image has no extension, the extension is now determined by the respective MIME type

## 1.0.0 – 2025-03-09
- Added Craft 5 compatibility.
- Added the “Avatar Location” setting.
- Avatar assets are now accessible via `webmention.avatar`.
- Webmentions now store which element they are associated with.
- Added the ability to update webmentions from the control panel.
- Added the `resave/webmentions` CLI command.
- Added the `webmention/example-template` CLI command.
- Added the `webmention/update` CLI command.
- Added the `webmention/update-avatars` CLI command.
- Added the `getWebmentions()` and `getWebmentionsByType()` element behaviors
- Added support for the Bridgy site types `mastodon`, `bluesky`, `github`, and `reddit`.
- Added a new icon based on Paul Robert Lloyd’s IndieWeb icon designs
- Fixed the regex that scans for URLs in entries so that it now correctly handles Markdown links
- Lots of smaller bugfixes and improvements

## 0.3.1 - 2017-04-02
- Changed the retrieval method for links within an entry to fix a bug where a very long article with many links would lead to a PHP execution timeout
- Minor bugfixes and improvements

## 0.3.0 - 2017-01-06
- Webmention sending functionality implemented
- Setting added: Entry Types (for Webmention sending)
- New “Webmention Switch” field type

## 0.2.0 - 2016-06-07
- Webmentions are now stored as Craft elements (ElementType: `Webmention_webmention`)
- Improved backend functionality: Webmentions are displayed under the tab *Webmentions* and can be deleted
- The plugin now sets the `type` property of an incoming Webmention correctly, based on the [Microformats](http://microformats.org/wiki/h-entry) properties `u-like-of`, `u-like`, `u-repost-of`, and `u-repost`.

## 0.1.0 - 2016-06-03
- First version
