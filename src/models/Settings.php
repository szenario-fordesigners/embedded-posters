<?php

namespace szenario\craftembeddedposters\models;

use Craft;
use craft\base\Model;

/**
 * Embedded Posters settings, editable in the CP and overridable through `config/embedded-posters.php`.
 *
 * @author szenario
 * @since 1.0.0
 */
class Settings extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var ?string Handle of the volume posters are stored in. `null` stores each poster in its embed's own volume.
     */
    public ?string $volume = null;

    /**
     * @var string Folder inside that volume. An empty string means the volume root.
     */
    public string $folder = 'embed-posters';

    // Public Methods
    // =========================================================================

    /**
     * Checks that the volume handle belongs to an existing volume.
     *
     * @param string $attribute
     */
    public function validateVolume(string $attribute): void
    {
        if (!Craft::$app->getVolumes()->getVolumeByHandle($this->$attribute)) {
            $this->addError($attribute, Craft::t('embedded-posters', 'No volume with this handle exists.'));
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        // the CP select posts '' for "the embed's own volume"
        $rules[] = [['volume'], 'default', 'value' => null];
        $rules[] = [['volume'], 'validateVolume', 'skipOnEmpty' => true];
        $rules[] = [['folder'], 'trim', 'chars' => " /\\"];

        return $rules;
    }
}
