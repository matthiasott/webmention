<?php

namespace matthiasott\webmention\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use matthiasott\webmention\elements\Webmention;

/**
 * @template TKey of array-key
 * @template TElement of Webmention
 * @extends ElementQuery<TKey,TElement>
 */
class WebmentionQuery extends ElementQuery
{
    public mixed $authorName = null;
    public mixed $authorUrl = null;
    public mixed $published = null;
    public mixed $name = null;
    public mixed $text = null;
    public mixed $target = null;
    public mixed $targetId = null;
    public mixed $targetSiteId = null;
    public mixed $source = null;
    public mixed $hEntryUrl = null;
    public mixed $host = null;
    public mixed $type = null;
    public mixed $rsvp = null;

    public function authorName(mixed $value): static
    {
        $this->authorName = $value;
        return $this;
    }

    public function authorUrl(mixed $value): static
    {
        $this->authorUrl = $value;
        return $this;
    }

    public function published(mixed $value): static
    {
        $this->published = $value;
        return $this;
    }

    public function name(mixed $value): static
    {
        $this->name = $value;
        return $this;
    }

    public function text(mixed $value): static
    {
        $this->text = $value;
        return $this;
    }

    public function target(mixed $value): static
    {
        $this->target = $value;
        return $this;
    }

    public function targetId(mixed $value): static
    {
        $this->targetId = $value;
        return $this;
    }

    public function targetSiteId(mixed $value): static
    {
        $this->targetSiteId = $value;
        return $this;
    }

    public function source(mixed $value): static
    {
        $this->source = $value;
        return $this;
    }

    public function hEntryUrl(mixed $value): static
    {
        $this->hEntryUrl = $value;
        return $this;
    }

    public function host(mixed $value): static
    {
        $this->host = $value;
        return $this;
    }

    public function type(mixed $value): static
    {
        $this->type = $value;
        return $this;
    }

    public function rsvp(mixed $value): static
    {
        $this->rsvp = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $this->joinElementTable('webmentions');

        $this->query->addSelect([
            'webmentions.authorName',
            'webmentions.avatarId',
            'webmentions.authorUrl',
            'webmentions.published',
            'webmentions.name',
            'webmentions.text',
            'webmentions.target',
            'webmentions.targetId',
            'webmentions.targetSiteId',
            'webmentions.source',
            'webmentions.hEntryUrl',
            'webmentions.host',
            'webmentions.type',
            'webmentions.rsvp',
        ]);

        if ($this->authorName) {
            $this->subQuery->andWhere(Db::parseParam('webmentions.authorName', $this->authorName));
        }

        if ($this->authorUrl) {
            $this->subQuery->andWhere(Db::parseParam('webmentions.authorUrl', $this->authorUrl));
        }

        if ($this->published) {
            $this->subQuery->andWhere(Db::parseDateParam('webmentions.published', $this->published));
        }

        if ($this->name) {
            $this->subQuery->andWhere(Db::parseParam('webmentions.name', $this->name));
        }

        if ($this->text) {
            $this->subQuery->andWhere(Db::parseParam('webmentions.text', $this->text));
        }

        if ($this->target) {
            $this->subQuery->andWhere(Db::parseParam('webmentions.target', $this->target));
        }

        if ($this->targetId) {
            $this->subQuery->andWhere(Db::parseParam('webmentions.targetId', $this->targetId));
        }

        if ($this->targetSiteId) {
            $this->subQuery->andWhere([
                'or',
                Db::parseParam('webmentions.targetSiteId', $this->targetSiteId),
                ['webmentions.targetSiteId' => null],
            ]);
        }

        if ($this->source) {
            $this->subQuery->andWhere(Db::parseParam('webmentions.source', $this->source));
        }

        if ($this->hEntryUrl) {
            $this->subQuery->andWhere(Db::parseParam('webmentions.hEntryUrl', $this->hEntryUrl));
        }

        if ($this->host) {
            $this->subQuery->andWhere(Db::parseParam('webmentions.host', $this->host));
        }

        if ($this->type) {
            $this->subQuery->andWhere(Db::parseParam('webmentions.type', $this->type));
        }

        if ($this->rsvp) {
            $this->subQuery->andWhere(Db::parseParam('webmentions.rsvp', $this->rsvp));
        }

        return true;
    }
}
