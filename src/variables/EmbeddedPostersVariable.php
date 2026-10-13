<?php

namespace szenario\craftembeddedposters\variables;

use craft\elements\Asset;
use szenario\craftembeddedposters\EmbeddedPosters;

/**
 * `craft.embeddedPosters`. Read-only on purpose: templates get no way to fetch or delete posters.
 *
 * @author szenario
 * @since 1.0.0
 */
class EmbeddedPostersVariable
{
    // Public Methods
    // =========================================================================

    /**
     * The local poster for an embedded asset, if one was fetched. Inside a loop over query results,
     * the first call loads the posters of all results in one query.
     *
     * ```twig
     * {% set poster = craft.embeddedPosters.poster(asset) %}
     * ```
     *
     * @param ?Asset $embed
     * @return ?Asset
     */
    public function poster(?Asset $embed): ?Asset
    {
        if (!$embed || $embed->kind !== Asset::KIND_JSON) {
            return null;
        }

        return EmbeddedPosters::getInstance()->getPosters()->eagerPoster($embed);
    }
}
