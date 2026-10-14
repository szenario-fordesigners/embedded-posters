<?php

namespace szenario\craftembeddedposters;

use Craft;
use craft\base\Element;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\DefineEagerLoadingMapEvent;
use craft\events\ModelEvent;
use craft\events\RegisterElementSourcesEvent;
use craft\fs\Local;
use craft\models\Volume;
use craft\web\twig\variables\CraftVariable;
use RuntimeException;
use szenario\craftembeddedposters\services\Posters;
use szenario\craftembeddedposters\variables\EmbeddedPostersVariable;
use yii\base\Event;

/**
 * Keeps a local copy of every Embedded Assets poster image, so a consent placeholder can show it
 * without the visitor's browser contacting the provider.
 *
 * @method static EmbeddedPosters getInstance()
 * @property-read Posters $posters
 * @author szenario
 * @copyright Studio Bendl OG
 * @license MIT
 */
class EmbeddedPosters extends Plugin
{
    // Const Properties
    // =========================================================================

    /**
     * Handle of the volume posters are stored in, and of the filesystem created with it.
     */
    public const VOLUME_HANDLE = 'embeddedPosters';

    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public string $schemaVersion = '1.0.0';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'posters' => Posters::class,
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('embeddedPosters', EmbeddedPostersVariable::class);
        });

        // `.with(['embeddedPoster'])` works whether or not Embedded Assets is enabled, like the variable
        Event::on(Asset::class, Element::EVENT_DEFINE_EAGER_LOADING_MAP, function(DefineEagerLoadingMapEvent $event) {
            if ($event->handle === Posters::EAGER_LOADING_HANDLE) {
                $event->elementType = Asset::class;
                $event->map = $this->getPosters()->eagerLoadingMap($event->sourceElements);
            }
        });

        // editors never need to browse posters; the volume stays selectable in field settings
        Event::on(Asset::class, Element::EVENT_REGISTER_SOURCES, function(RegisterElementSourcesEvent $event) {
            $uid = Craft::$app->getVolumes()->getVolumeByHandle(self::VOLUME_HANDLE)?->uid;

            if ($event->context === 'settings' || $uid === null) {
                return;
            }

            $key = "volume:$uid";
            $event->sources = array_values(array_filter($event->sources, fn(array $source) => ($source['key'] ?? null) !== $key));
        });

        // plugin enabled state is only reliable once every plugin has loaded
        Craft::$app->onInit(function() {
            if (!$this->getPosters()->embeddedAssetsEnabled()) {
                Craft::info('Embedded Assets is not enabled; no posters will be fetched.', __METHOD__);
                return;
            }

            $this->_attachAssetHandlers();
        });
    }

    /**
     * Returns the posters service.
     *
     * @return Posters
     */
    public function getPosters(): Posters
    {
        /** @var Posters */
        return $this->get('posters');
    }

    // Protected Methods
    // =========================================================================

    /**
     * Creates the posters volume. Never fails the install: until the volume exists, embeds simply
     * get no poster, and a warning says why.
     */
    protected function afterInstall(): void
    {
        $volumes = Craft::$app->getVolumes();

        // on deploy, the volume arrives through project config along with the install;
        // a reinstall reuses the one the previous install left behind
        if (
            !Craft::$app->getConfig()->getGeneral()->allowAdminChanges ||
            Craft::$app->getProjectConfig()->getIsApplyingExternalChanges() ||
            $volumes->getVolumeByHandle(self::VOLUME_HANDLE)
        ) {
            return;
        }

        try {
            $filesystems = Craft::$app->getFs();

            if (!$filesystems->getFilesystemByHandle(self::VOLUME_HANDLE)) {
                $fs = new Local([
                    'name' => 'Embedded Posters',
                    'handle' => self::VOLUME_HANDLE,
                    'hasUrls' => true,
                    'url' => '@web/embedded-posters',
                    'path' => '@webroot/embedded-posters',
                ]);

                if (!$filesystems->saveFilesystem($fs)) {
                    throw new RuntimeException('filesystem not saved: ' . implode('; ', $fs->getFirstErrors()));
                }
            }

            $volume = new Volume([
                'name' => 'Embedded Posters',
                'handle' => self::VOLUME_HANDLE,
                'fs' => self::VOLUME_HANDLE,
            ]);

            if (!$volumes->saveVolume($volume)) {
                throw new RuntimeException('volume not saved: ' . implode('; ', $volume->getFirstErrors()));
            }
        } catch (\Throwable $e) {
            Craft::warning("No poster volume created: {$e->getMessage()}", __METHOD__);
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Keeps each embed's poster in step with the embed. Posters are images, so the `kind` check
     * also stops the handlers from reacting to the poster saves they cause.
     */
    private function _attachAssetHandlers(): void
    {
        Event::on(Asset::class, Element::EVENT_AFTER_SAVE, function(ModelEvent $event) {
            /** @var Asset $asset */
            $asset = $event->sender;

            // assets are saved once per site; one download is enough
            if ($asset->kind === Asset::KIND_JSON && !$asset->propagating) {
                $this->getPosters()->sync($asset);
            }
        });

        Event::on(Asset::class, Element::EVENT_AFTER_DELETE, function(Event $event) {
            /** @var Asset $asset */
            $asset = $event->sender;

            if ($asset->kind === Asset::KIND_JSON) {
                $this->getPosters()->delete($asset);
            }
        });

        Event::on(Asset::class, Element::EVENT_AFTER_RESTORE, function(Event $event) {
            /** @var Asset $asset */
            $asset = $event->sender;

            if ($asset->kind === Asset::KIND_JSON) {
                $this->getPosters()->restore($asset);
            }
        });
    }
}
