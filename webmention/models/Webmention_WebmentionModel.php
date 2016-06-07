<?php
namespace Craft;
/**
 * Webmention Model
 *
 * Provides a read-only object representing a webmention, which is returned
 * by the service class and can be used in templates and controllers.
 */
class Webmention_WebmentionModel extends BaseElementModel
{   
    protected $elementType = 'Webmention_Webmention';
    /**
     * Defines what is returned when someone puts {{ webmention }} directly
     * in their template.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->id;
    }
    public function isEditable()
    {
        return false;
    }

    public function hasTitles()
    {
        return false;
    }

    public function isLocalized()
    {
        return false;
    }
    /**
     * Define the attributes this model will have.
     *
     * @return array
     */
    public function defineAttributes()
    {
        return array_merge(parent::defineAttributes(), array(
            'id'    => array(AttributeType::Number),
            'author_name'  => array(AttributeType::String),
            'author_photo'  => array(AttributeType::Url),
            'author_url'  => array(AttributeType::Url),
            'published' => array(AttributeType::DateTime),
            'name'  => array(AttributeType::String),
            'text'  => array(AttributeType::String),
            'target'  => array(AttributeType::Url),
            'source'  => array(AttributeType::Url),
            'url'  => array(AttributeType::Url),
            'site'  => array(AttributeType::String),
            'type'  => array(AttributeType::String), /* ['comment','mention','reply','rsvp','like','repost'] */
            'rsvp' => array(AttributeType::String) /* [yes, no, maybe, interested] */
        ));
    }
}