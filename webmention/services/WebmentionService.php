<?php

namespace Craft;

/**
 * Webmention Service
 *
 * Provides a consistent API for our plugin to access the database
 */
class WebmentionService extends BaseApplicationComponent
{
    protected $webmentionRecord;

    /**
     * Create a new instance of the Webmention Service.
     * Constructor allows WebmentionRecord dependency to be injected to assist with unit testing.
     *
     * @param @webmentionRecord WebmentionRecord The ingredient record to access the database
     */
    public function __construct($webmentionRecord = null)
    {
        $this->webmentionRecord = $webmentionRecord;
        if (is_null($this->webmentionRecord)) {
            $this->webmentionRecord = Webmention_WebmentionRecord::model();
        }
    }

    /**
     * Get a new blank webmention
     * 
     * @param  array                           $attributes
     * @return Webmention_WebmentionModel
     */
    public function newWebmention($attributes = array())
    {
        $model = new Webmention_WebmentionModel();
        $model->setAttributes($attributes);

        return $model;
    }

    /**
     * Get all webmentions from the database.
     *
     * @return mixed
     */
    public function getAllWebmentions()
    {
        $records = $this->webmentionRecord->findAll(array('order'=>'t.id'));

        return Webmention_WebmentionModel::populateModels($records, 'id');
    }

    /**
     * Get all webmentions from the database.
     *
     * @return mixed
     */
    public function getAllWebmentionsForEntry($url)
    {
        //$Products = Product::model()->findAll($criteria);
        $records = $this->webmentionRecord->findAllByAttributes(array("target"=>$url),array('order'=>'id'));

        return Webmention_WebmentionModel::populateModels($records, 'id');
    }

    /**
     * Get a specific webmention from the database based on ID. If no webmention exists, null is returned.
     *
     * @param  int   $id
     * @return mixed
     */
    public function getWebmentionById($id)
    {
        if ($record = $this->webmentionRecord->findByPk($id)) {
            return Webmention_WebmentionModel::populateModel($record);
        }
    }

    /**
     * Get a specific webmention from the database based on the source url. If no webmention exists, null is returned.
     *
     * @param  int   $src
     * @return mixed
     */
    public function getWebmentionBySourceUrl($src)
    {
        if ($record = $this->webmentionRecord->findByAttributes(array("source"=>$src))) {
            return Webmention_WebmentionModel::populateModel($record);
        }
    }

    /**
     * Get a specific webmention from the database based on an array of attributes. If no webmention exists, null is returned.
     *
     * @param  array   $arr
     * @return mixed
     */
    public function getWebmentionByAttributes($arr)
    {
        if ($record = $this->webmentionRecord->findByAttributes($arr)) {
            return Webmention_WebmentionModel::populateModel($record);
        }
    }

    /**
     * Save a new or existing webmention back to the database.
     *
     * @param  Webmention_WebmentionModel $model
     * @return bool
     */
    public function saveWebmention(Webmention_WebmentionModel $model)
    {
        if ($id = $model->getAttribute('id')) {
            if (null === ($record = $this->webmentionRecord->findByPk($id))) {
                throw new Exception(Craft::t('Can\'t find webmention with ID "{id}"', array('id' => $id)));
            }
        } else {
            $record = $this->webmentionRecord->create();
        }

        $record->setAttributes($model->getAttributes(), false);

        if ($record->save()) {
            // update id on model (for new records)
            $model->setAttribute('id', $record->getAttribute('id'));

            return true;
        } else {
            $model->addErrors($record->getErrors());

            return false;
        }
    }

    /**
     * Delete a webmention from the database.
     *
     * @param  int $id
     * @return int The number of rows affected
     */
    public function deleteWebmentionById($id)
    {
        return $this->webmentionRecord->deleteByPk($id);
    }
}
