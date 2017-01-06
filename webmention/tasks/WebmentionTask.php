<?php
namespace Craft;

class WebmentionTask extends BaseTask {
	protected function defineSettings() {
		return array(
			'queue' => AttributeType::Mixed,
			'rows'  => AttributeType::Number
		);
	}

	public function getDescription() {
		return Craft::t('Sending Webmentions');
	}

	public function getTotalSteps() {
		return $this->getSettings()->rows;
	}

	public function runStep($step) {
		$queue = $this->getSettings()->queue;
		$row = $queue[$step];

		craft()->webmention->sendWebmentions($row['endpoint'], $row['source'], $row['target']);

		return true;
	}
}