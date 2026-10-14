<?php

namespace szenario\craftembeddedposters\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\Assets;
use craft\helpers\FileHelper;
use craft\helpers\Image;
use craft\helpers\Json;
use craft\models\VolumeFolder;
use RuntimeException;
use spicyweb\embeddedassets\Plugin as EmbeddedAssets;
use spicyweb\embeddedassets\Service as EmbeddedAssetsService;
use szenario\craftembeddedposters\EmbeddedPosters;
use szenario\craftembeddedposters\helpers\PosterSource;
use yii\base\InvalidConfigException;

/**
 * Fetches, finds, deletes and restores the local poster of each embedded asset.
 *
 * A poster is an ordinary image asset named `{embed uid}.{ext}`. The name is the only link
 * between the two: Embedded Assets rejects JSON with unknown keys, so the embed can't point at it.
 *
 * @author szenario
 * @since 1.0.0
 */
class Posters extends Component
{
    // Const Properties
    // =========================================================================

    public const RESULT_FETCHED = 'fetched';
    public const RESULT_UNCHANGED = 'unchanged';

    /**
     * Eager-loading handle for an embed's poster: `.with(['myAssetField.embeddedPoster'])`.
     */
    public const EAGER_LOADING_HANDLE = 'embeddedPoster';

    // Public Methods
    // =========================================================================

    /**
     * Whether Embedded Assets is installed and enabled.
     *
     * @return bool
     */
    public function embeddedAssetsEnabled(): bool
    {
        return $this->_embeddedAssets() !== null;
    }

    /**
     * The local poster for an embedded asset, if one was fetched.
     *
     * @param Asset $embed
     * @param bool $trashed Find a soft-deleted poster instead.
     * @return ?Asset
     */
    public function poster(Asset $embed, bool $trashed = false): ?Asset
    {
        // no volume filter: the uid is unique
        return Asset::find()
            ->filename($embed->uid . '.*')
            ->kind(Asset::KIND_IMAGE)
            ->trashed($trashed)
            ->one();
    }

    /**
     * The local poster for an embedded asset, using eager-loaded posters where possible.
     *
     * If the embed came from a query with other results and nothing was eager-loaded yet, the
     * posters of the whole result set are eager-loaded in one query, as Craft's `eagerly()` does.
     *
     * @param Asset $embed
     * @return ?Asset
     * @throws \yii\base\InvalidConfigException
     */
    public function eagerPoster(Asset $embed): ?Asset
    {
        $handle = self::EAGER_LOADING_HANDLE;

        if (!$embed->hasEagerLoadedElements($handle) && count($embed->elementQueryResult ?? []) > 1) {
            Craft::$app->getElements()->eagerLoadElements(Asset::class, $embed->elementQueryResult, [$handle]);
        }

        if ($embed->hasEagerLoadedElements($handle)) {
            /** @var ?Asset */
            return $embed->getEagerLoadedElements($handle)?->first();
        }

        return $this->poster($embed);
    }

    /**
     * Maps embeds to their posters for eager loading, in one query.
     *
     * @param \craft\base\ElementInterface[] $sourceElements
     * @return array<array{source: int, target: int}>
     */
    public function eagerLoadingMap(array $sourceElements): array
    {
        $embeds = array_filter($sourceElements, fn($e) => $e instanceof Asset && $e->kind === Asset::KIND_JSON);

        if (!$embeds) {
            return [];
        }

        /** @var Asset[] $embeds */
        $embedIds = array_column(array_map(fn(Asset $e) => [$e->uid, $e->id], $embeds), 1, 0);

        // ponytail: one `LIKE` per embed; fine for a page of embeds, a link table if it ever isn't
        $posters = Asset::find()
            ->filename(array_map(fn($uid) => "$uid.*", array_keys($embedIds)))
            ->kind(Asset::KIND_IMAGE)
            ->select(['elements.id', 'assets.filename'])
            ->pairs();

        $map = [];

        foreach ($posters as $posterId => $filename) {
            $uid = pathinfo($filename, PATHINFO_FILENAME);

            if (isset($embedIds[$uid])) {
                $map[] = ['source' => $embedIds[$uid], 'target' => (int)$posterId];
            }
        }

        return $map;
    }

    /**
     * Brings an embed's poster up to date with its `image`. Never throws: a missing poster only
     * means a plain placeholder, so it must never fail the embed's save.
     *
     * @param Asset $embed
     * @return string `fetched`, `unchanged`, `skipped (reason)` or `failed (reason)`
     */
    public function sync(Asset $embed): string
    {
        try {
            $data = Json::decodeIfJson($embed->getContents());
        } catch (\Throwable $e) {
            return $this->_fail($embed, null, "file not readable: {$e->getMessage()}");
        }

        // not every JSON file in a volume is an embed
        if (!is_array($data) || !$this->_embeddedAssets()?->isValidEmbeddedAssetData($data)) {
            $this->delete($embed);
            return 'skipped (not an embed)';
        }

        $url = $data['image'] ?? null;

        // better no poster than stale artwork
        if (!$url) {
            $this->delete($embed);
            return 'skipped (no image)';
        }

        try {
            $folder = $this->_folder();
        } catch (\Throwable $e) {
            return $this->_fail($embed, $url, $e->getMessage());
        }

        try {
            [$mimeType, $body] = $this->_download($url);
        } catch (\Throwable $e) {
            return $this->_fail($embed, $url, $e->getMessage());
        }

        $filename = PosterSource::filename($embed->uid, $mimeType);
        $poster = $this->poster($embed);

        try {
            $tempPath = $this->_tempFile($body, $mimeType);

            // Craft re-encodes images on upload; do it here once, so the result can be compared
            // with the stored poster, and tell Craft not to do it again
            Image::cleanImageByPath($tempPath);

            if ($poster) {
                $titleChanged = $poster->title !== $this->_title($embed);
                $poster->title = $this->_title($embed);

                // replacing identical bytes would only regenerate transforms and bust caches
                if ($poster->filename === $filename && sha1_file($tempPath) === sha1($poster->getContents())) {
                    FileHelper::unlink($tempPath);

                    if ($titleChanged && !Craft::$app->getElements()->saveElement($poster)) {
                        return $this->_fail($embed, $url, 'title not saved');
                    }

                    return self::RESULT_UNCHANGED;
                }

                $poster->sanitizeOnUpload = false;
                Craft::$app->getAssets()->replaceAssetFile($poster, $tempPath, $filename);
                return self::RESULT_FETCHED;
            }

            $poster = new Asset();
            $poster->title = $this->_title($embed);
            $poster->tempFilePath = $tempPath;
            $poster->filename = $filename;
            $poster->newFolderId = $folder->id;
            $poster->volumeId = $folder->volumeId;
            $poster->sanitizeOnUpload = false;
            $poster->setScenario(Asset::SCENARIO_CREATE);

            if (!Craft::$app->getElements()->saveElement($poster)) {
                return $this->_fail($embed, $url, 'not saved: ' . implode('; ', $poster->getFirstErrors()));
            }
        } catch (\Throwable $e) {
            return $this->_fail($embed, $url, $e->getMessage());
        }

        return self::RESULT_FETCHED;
    }

    /**
     * Deletes an embed's poster, hard-deleting it if the embed itself is being hard-deleted.
     *
     * @param Asset $embed
     * @throws \Throwable
     */
    public function delete(Asset $embed): void
    {
        // a hard delete from the trash finds the poster already trashed
        $poster = $this->poster($embed) ?? ($embed->hardDelete ? $this->poster($embed, true) : null);

        if ($poster) {
            // Craft only lets an asset be restored if its file was kept; keep the poster's whenever the embed's is
            $poster->keepFileOnDelete = $embed->keepFileOnDelete;
            Craft::$app->getElements()->deleteElement($poster, $embed->hardDelete);
        }
    }

    /**
     * Restores an embed's soft-deleted poster. Only reached when Craft kept the embed's file on delete
     * (folder or volume deletion); an embed deleted in the CP loses its file and can't be restored.
     *
     * @param Asset $embed
     * @throws \Throwable
     */
    public function restore(Asset $embed): void
    {
        if ($poster = $this->poster($embed, true)) {
            Craft::$app->getElements()->restoreElement($poster);
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Downloads a poster. The URL comes from third-party oEmbed data, so only an allowlisted image
     * type under the size cap gets through.
     *
     * @param string $url
     * @return array{string, string} MIME type and body
     * @throws RuntimeException if the response isn't an acceptable image
     * @throws \GuzzleHttp\Exception\GuzzleException if the request fails
     */
    private function _download(string $url): array
    {
        if (!PosterSource::isAcceptedUrl($url)) {
            throw new RuntimeException('unsupported URL');
        }

        $response = Craft::createGuzzleClient([
            'timeout' => 10,
            'allow_redirects' => ['max' => 3, 'protocols' => ['http', 'https']],
        ])->get($url, ['stream' => true]);

        $mimeType = PosterSource::mimeType($response->getHeaderLine('Content-Type'));

        if (!PosterSource::extension($mimeType)) {
            throw new RuntimeException("Content-Type is $mimeType");
        }

        $body = PosterSource::readCapped($response->getBody());

        if ($body === null) {
            throw new RuntimeException('larger than ' . PosterSource::MAX_BYTES . ' bytes');
        }

        return [$mimeType, $body];
    }

    /**
     * Writes a downloaded body to a temp file for Craft to move into the volume.
     *
     * @param string $body
     * @param string $mimeType
     * @return string
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     */
    private function _tempFile(string $body, string $mimeType): string
    {
        $path = Assets::tempFilePath(PosterSource::extension($mimeType));
        FileHelper::writeToFile($path, $body);

        return $path;
    }

    /**
     * Logs why an embed has no fresh poster and returns the matching result.
     *
     * @param Asset $embed
     * @param ?string $url
     * @param string $reason
     * @return string
     */
    private function _fail(Asset $embed, ?string $url, string $reason): string
    {
        Craft::warning("No poster for embedded asset $embed->id ($url): $reason", __METHOD__);

        return "failed ($reason)";
    }

    /**
     * @param Asset $embed
     * @return string
     */
    private function _title(Asset $embed): string
    {
        return "$embed->title (poster)";
    }

    /**
     * The root folder of the posters volume.
     *
     * @return VolumeFolder
     * @throws InvalidConfigException if the posters volume doesn't exist
     */
    private function _folder(): VolumeFolder
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle(EmbeddedPosters::VOLUME_HANDLE);

        if (!$volume) {
            throw new InvalidConfigException('volume "' . EmbeddedPosters::VOLUME_HANDLE . '" not found');
        }

        return Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
    }

    /**
     * The Embedded Assets service, or `null` if the plugin is disabled or not installed.
     *
     * @return ?EmbeddedAssetsService
     */
    private function _embeddedAssets(): ?EmbeddedAssetsService
    {
        /** @var ?EmbeddedAssets $plugin */
        $plugin = Craft::$app->getPlugins()->getPlugin('embeddedassets');

        /** @var ?EmbeddedAssetsService */
        return $plugin?->get('methods');
    }
}
