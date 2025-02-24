<?php

namespace matthiasott\webmention\models;

use Craft;
use craft\base\Model;
use craft\helpers\StringHelper;

class Settings extends Model
{
    public string $endpointSlug = 'webmention';
    public int $maxTextLength = 420;
    public bool $useBridgy = true;
    public ?string $avatarVolume = null;
    public string $avatarPath = 'avatars/';
    public array $entryTypes = [];

    public function init(): void
    {
        parent::init();

        if (!isset($this->entryTypes)) {
            $this->entryTypes = [];
            foreach (Craft::$app->entries->getAllEntryTypes() as $entryType) {
                $this->entryTypes[$entryType->uid] = [
                    'checked' => true,
                    'label' => $entryType->name,
                    'handle' => $entryType->handle,
                ];
            }
        }
    }

    public function setAttributes($values, $safeOnly = true): void
    {
        if (!empty($values['avatarVolume']) && !StringHelper::isUUID($values['avatarVolume'])) {
            if (is_numeric($values['avatarVolume'])) {
                $volume = Craft::$app->volumes->getVolumeById($values['avatarVolume']);
            } else {
                $volume = Craft::$app->volumes->getVolumeByHandle($values['avatarVolume']);
            }
            $values['avatarVolume'] = $volume?->uid;
        }
        parent::setAttributes($values, $safeOnly);
    }
}
