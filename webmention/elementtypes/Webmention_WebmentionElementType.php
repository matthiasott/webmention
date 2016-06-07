<?php
namespace Craft;

/**
 * Webmention - Webmention element type
 */
class Webmention_WebmentionElementType extends BaseElementType
{
	/**
	 * Returns the element type name.
	 *
	 * @return string
	 */
	public function getName()
	{
		return Craft::t('Webmention');
	}

	/**
	 * Returns whether this element type has content.
	 *
	 * @return bool
	 */
	public function hasContent()
	{
		return false;
	}

	/**
	 * Returns whether this element type has titles.
	 *
	 * @return bool
	 */
	public function hasTitles()
	{
		return false;
	}

	/**
	 * Returns this element type's sources.
	 *
	 * @param string|null $context
	 * @return array|false
	 */
	public function getSources($context = null)
	{
		$sources = array(
			'*' => array(
				'label'    => Craft::t('All Webmentions'),
			)
		);

		return $sources;
	}

	/**
	 * Returns the attributes that can be shown/sorted by in table views.
	 *
	 * @param string|null $source
	 * @return array
	 */
	public function defineAvailableTableAttributes($source = null)
	{
		return array(
			'id'  => Craft::t('ID'),
			'author_name'   => Craft::t('Author'),
			'text' 					=> Craft::t('Text'),
			'target' 					=> Craft::t('Target'),
			'type' 	=> Craft::t('Type'),
			'dateUpdated' 	=> Craft::t('Date'),
		);
	}

	public function defineSortableAttributes()
    {
      return array(
          'id'  => Craft::t('ID'),
          'author_name'   => Craft::t('Author'),
          'text' 					=> Craft::t('Text'),
          'target' 					=> Craft::t('Target'),
          'type' 	=> Craft::t('Type'),
          'dateUpdated' 	=> Craft::t('Date'),
      );
    }

	/**
	 * Returns the table view HTML for a given attribute.
	 *
	 * @param BaseElementModel $element
	 * @param string $attribute
	 * @return string
	 */
	public function getTableAttributeHtml(BaseElementModel $element, $attribute)
	{	
		switch ($attribute)
		{
			case 'author_name':
			{
				return '<strong>'.$element->$attribute.'</strong>';
			}
			case 'text':
			{
				return $element->$attribute;
			}
			case 'dateUpdated':
			{
				$date = $element->$attribute;
				if ($date)
				{
					return $date->localeDate();
				}
				else
				{
					return '';
				}
			}
			case 'target':
			{
				return '<a href="' . $element->$attribute . '">'. $element->$attribute .'</a>';
			}
			default:
			{
				return parent::getTableAttributeHtml($element, $attribute);
			}
		}
	}

	/**
	 * Defines any custom element criteria attributes for this element type.
	 *
	 * @return array
	 */
	public function defineCriteriaAttributes()
	{
		return array(
			'author_photo'  => AttributeType::Url,
			'author_name'  => AttributeType::Mixed,
			'author_url'  => AttributeType::Url,
			'published' => AttributeType::DateTime,
			'name'  => AttributeType::Mixed,
			'text'  => AttributeType::Mixed,
			'target'  => AttributeType::Url,
			'source'  => AttributeType::Url,
			'url'  => AttributeType::Url,
			'site'  => AttributeType::Mixed,
			'type'  => AttributeType::Mixed,
			'rsvp' => AttributeType::Mixed,
			'order' => array(AttributeType::String, 'default' => 'webmention_webmention.dateUpdated desc'),
		);
	}

	/**
	 * Modifies an element query targeting elements of this type.
	 *
	 * @param DbCommand $query
	 * @param ElementCriteriaModel $criteria
	 * @return mixed
	 */
	public function modifyElementsQuery(DbCommand $query, ElementCriteriaModel $criteria)
	{
		$query
			->addSelect('	webmention_webmention.id,
				webmention_webmention.author_name,
			webmention_webmention.author_photo,
			webmention_webmention.author_url,
			webmention_webmention.published,
			webmention_webmention.name,
			webmention_webmention.text,
			webmention_webmention.target,
			webmention_webmention.source,
			webmention_webmention.url,
			webmention_webmention.site, 
			webmention_webmention.type, 
			webmention_webmention.rsvp')
			->join('webmention_webmention webmention_webmention', 'webmention_webmention.id = elements.id');

		if ($criteria->author_name)
		{
			$query->andWhere(DbHelper::parseParam('webmention_webmention.author_name', $criteria->author_name, $query->params));
		}

		if ($criteria->author_photo)
		{
			$query->andWhere(DbHelper::parseParam('webmention_webmention.author_photo', $criteria->author_photo, $query->params));
		}

		if ($criteria->published)
		{
			$query->andWhere(DbHelper::parseParam('webmention_webmention.published', $criteria->published, $query->params));
		}

		if ($criteria->name)
		{
			$query->andWhere(DbHelper::parseParam('webmention_webmention.name', $criteria->name, $query->params));
		}

		if ($criteria->text)
		{
			$query->andWhere(DbHelper::parseParam('webmention_webmention.text', $criteria->text, $query->params));
		}

		if ($criteria->target)
		{
			$query->andWhere(DbHelper::parseParam('webmention_webmention.target', $criteria->target, $query->params));
		}

		if ($criteria->source)
		{
			$query->andWhere(DbHelper::parseParam('webmention_webmention.source', $criteria->source, $query->params));
		}

		if ($criteria->url)
		{
			$query->andWhere(DbHelper::parseParam('webmention_webmention.url', $criteria->url, $query->params));
		}

		if ($criteria->site)
		{
			$query->andWhere(DbHelper::parseParam('webmention_webmention.site', $criteria->site, $query->params));
		}

		if ($criteria->type)
		{
			$query->andWhere(DbHelper::parseParam('webmention_webmention.type', $criteria->type, $query->params));
		}

		if ($criteria->rsvp)
		{
			$query->andWhere(DbHelper::parseParam('webmention_webmention.rsvp', $criteria->rsvp, $query->params));
		}

	}

	/**
	 * Populates an element model based on a query result.
	 *
	 * @param array $row
	 * @return array
	 */
	public function populateElementModel($row)
	{
		return Webmention_WebmentionModel::populateModel($row);
	}

	/**
	 * Returns the HTML for an editor HUD for the given element.
	 *
	 * @param BaseElementModel $element
	 * @return string
	 */
	public function getEditorHtml(BaseElementModel $element)
	{
		// Start/End Dates
		// $html = craft()->templates->render('events/_edit', array(
		// 	'element' => $element,
		// ));

		// Everything else
		$html .= parent::getEditorHtml($element);

		return $html;
	}

	public function getAvailableActions($source = null)
	{
		return array('Webmention_Delete');
	}
}