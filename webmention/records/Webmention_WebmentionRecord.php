<?php
namespace Craft;
/**
 * Webmention Record
 *
 * Provides a definition of the database tables required by our plugin,
 * and methods for updating the database. This class should only be called
 * by our service layer, to ensure a consistent API for the rest of the
 * application to use.
 */
class Webmention_WebmentionRecord extends BaseRecord
{
    /**
     * Gets the database table name
     *
     * @return string
     */
    public function getTableName()
    {
        return 'webmention_webmention';
    }
    /**
     * Define columns for our database table
     *
     * @return array
     */
    public function defineAttributes()
    {
        return array(
            'author_name'  => array(AttributeType::String),
            'author_photo'  => array(AttributeType::Url),
            'author_url'  => array(AttributeType::Url),
            'published' => array(AttributeType::DateTime),
            'name'  => array(AttributeType::String, 'column' => ColumnType::Text),
            'text'  => array(AttributeType::String, 'column' => ColumnType::Text),
            'target'  => array(AttributeType::Url),
            'source'  => array(AttributeType::Url),
            'url'  => array(AttributeType::Url),
            'site'  => array(AttributeType::String),
            'type'  => array(AttributeType::String), /* ['mention','reply','rsvp','like','repost'] */
            'rsvp' => array(AttributeType::String) /* [yes, no, maybe, interested] */
        );
    }
    /**
     * Create a new instance of the current class. This allows us to
     * properly unit test our service layer.
     *
     * @return BaseRecord
     */
    public function create()
    {
        $class = get_class($this);
        $record = new $class();
        return $record;
    }
}