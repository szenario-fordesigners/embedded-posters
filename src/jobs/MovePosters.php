<?php

namespace szenario\craftembeddedposters\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\elements\Asset;
use craft\queue\BaseBatchedJob;
use szenario\craftembeddedposters\EmbeddedPosters;

/**
 * Moves every poster to the volume and folder currently configured. Queued when those settings change.
 *
 * Reads the settings when it runs, not when it was queued, so the last change wins. Afterwards,
 * folders the posters left that are now unused are deleted (see Posters::deleteEmptyFolder()).
 *
 * @author szenario
 * @since 1.0.0
 */
class MovePosters extends BaseBatchedJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var ?string The `folder` setting posters are moved away from. Emptied folders at that path are deleted.
     */
    public ?string $oldFolder = null;

    /**
     * @var array<int, true> Folders posters were moved out of, collected across batches.
     */
    public array $sourceFolderIds = [];

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function loadData(): Batchable
    {
        return new QueryBatcher(Asset::find()->kind(Asset::KIND_JSON)->status(null));
    }

    /**
     * @inheritdoc
     */
    protected function processItem(mixed $item): void
    {
        try {
            if ($from = EmbeddedPosters::getInstance()->getPosters()->move($item)) {
                $this->sourceFolderIds[$from] = true;
            }
        } catch (\Throwable $e) {
            // one stuck poster shouldn't stop the rest; it's still found where it is
            Craft::warning("Poster of embedded asset $item->id not moved: {$e->getMessage()}", __METHOD__);
        }
    }

    /**
     * @inheritdoc
     */
    protected function after(): void
    {
        if ($this->oldFolder === null) {
            return;
        }

        foreach (array_keys($this->sourceFolderIds) as $folderId) {
            EmbeddedPosters::getInstance()->getPosters()->deleteEmptyFolder($folderId, $this->oldFolder);
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('embedded-posters', 'Moving embed posters');
    }
}
