<?php
namespace Craft;

/**
 * Webmention Delete Element Action
 *
 */
class Webmention_DeleteElementAction extends DeleteElementAction
{
	
	/**
	 * @inheritDoc IElementAction::performAction()
	 *
	 * @param ElementCriteriaModel $criteria
	 *
	 * @return bool
	 */
	public function performAction(ElementCriteriaModel $criteria)
	{
		craft()->webmention->deleteWebmentions($criteria->ids());

		$this->setMessage(Craft::t('Wementions deleted.'));

		return true;
	}

}
