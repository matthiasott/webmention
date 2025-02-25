# Release Notes for CKEditor for Craft CMS

## Unreleased
- Added Craft 5 compatibility.
- Added the “Avatar Location” setting.
- Avatar assets are now accessible via `webmention.avatar`.
- Webmentions now store which element they are associated with.
- Added the ability to update webmentions from the control panel.
- Added the `resave/webmentions` CLI command.
- Added the `webmention/example-template` CLI command.
- Added the `webmention/update` CLI command.
- Added the `webmention/update-avatars` CLI command.

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
