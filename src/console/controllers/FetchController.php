<?php

namespace szenario\craftembeddedposters\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use szenario\craftembeddedposters\EmbeddedPosters;
use szenario\craftembeddedposters\services\Posters;
use yii\console\ExitCode;

/**
 * Fetches posters for existing embedded assets.
 *
 * Unlike resave/assets, this touches only JSON assets, so other plugins' save listeners stay quiet.
 * Posters whose image hasn't changed are left alone.
 *
 * @author szenario
 * @since 1.0.0
 */
class FetchController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var ?string Handle of the volume to fetch for. Defaults to all volumes.
     */
    public ?string $volume = null;

    /**
     * @var string
     */
    public $defaultAction = 'index';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return [...parent::options($actionID), 'volume'];
    }

    /**
     * Fetches the poster of every embedded asset and prints one line per JSON asset.
     *
     * @return int
     */
    public function actionIndex(): int
    {
        $posters = EmbeddedPosters::getInstance()->getPosters();

        if (!$posters->embeddedAssetsEnabled()) {
            $this->stderr("Embedded Assets is not enabled.\n", Console::FG_RED);
            return ExitCode::UNAVAILABLE;
        }

        $query = Asset::find()->kind(Asset::KIND_JSON);

        if ($this->volume) {
            if (!Craft::$app->getVolumes()->getVolumeByHandle($this->volume)) {
                $this->stderr("No volume with the handle \"$this->volume\".\n", Console::FG_RED);
                return ExitCode::USAGE;
            }

            $query->volume($this->volume);
        }

        $failed = 0;

        foreach ($query->each() as $asset) {
            /** @var Asset $asset */
            $result = $posters->sync($asset);
            $color = match (true) {
                $result === Posters::RESULT_FETCHED => Console::FG_GREEN,
                str_starts_with($result, 'failed') => Console::FG_RED,
                default => Console::FG_GREY,
            };

            if (str_starts_with($result, 'failed')) {
                $failed++;
            }

            $this->stdout("$asset->id $asset->filename: ");
            $this->stdout("$result\n", $color);
        }

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
