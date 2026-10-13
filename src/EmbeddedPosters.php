<?php

namespace szenario\craftembeddedposters;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\ConfigEvent;
use craft\events\DefineEagerLoadingMapEvent;
use craft\events\ModelEvent;
use craft\helpers\Queue;
use craft\web\twig\variables\CraftVariable;
use szenario\craftembeddedposters\jobs\MovePosters;
use szenario\craftembeddedposters\models\Settings;
use szenario\craftembeddedposters\services\Posters;
use szenario\craftembeddedposters\variables\EmbeddedPostersVariable;
use yii\base\Event;

/**
 * Keeps a local copy of every Embedded Assets poster image, so a consent placeholder can show it
 * without the visitor's browser contacting the provider.
 *
 * @method static EmbeddedPosters getInstance()
 * @method Settings getSettings()
 * @property-read Posters $posters
 * @author szenario
 * @copyright Studio Bendl OG
 * @license MIT
 */
class EmbeddedPosters extends Plugin
{
    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public string $schemaVersion = '1.0.0';

    /**
     * @var bool
     */
    public bool $hasCpSettings = true;

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

        // fires for CP saves and for project config applied on deploy, in every environment
        $queuedFor = null;
        $moveOnChange = function(ConfigEvent $event) use (&$queuedFor) {
            $location = fn(?array $settings) => [$settings['volume'] ?? null, $settings['folder'] ?? null];
            $target = $location($event->newValue);

            // applying YAML fires this once per changed key; one job per new location is enough
            if ($target !== $location($event->oldValue) && $target !== $queuedFor) {
                // before the first save there is no old value; posters then sit in the default folder
                Queue::push(new MovePosters(['oldFolder' => $event->oldValue['folder'] ?? (new Settings())->folder]));
                $queuedFor = $target;
            }
        };
        Craft::$app->getProjectConfig()
            ->onAdd('plugins.embedded-posters.settings', $moveOnChange)
            ->onUpdate('plugins.embedded-posters.settings', $moveOnChange);

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
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \yii\base\Exception
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('embedded-posters/_settings.twig', [
            'settings' => $this->getSettings(),
            // keys set in config/embedded-posters.php win over what's saved here
            'config' => Craft::$app->getConfig()->getConfigFromFile('embedded-posters'),
        ]);
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
