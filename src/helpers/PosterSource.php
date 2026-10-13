<?php

namespace szenario\craftembeddedposters\helpers;

use Psr\Http\Message\StreamInterface;

/**
 * The rules for what a poster download may be. Pure, so the unit tests run without Craft.
 *
 * @author szenario
 * @since 1.0.0
 */
class PosterSource
{
    // Const Properties
    // =========================================================================

    /**
     * Accepted MIME types and the extension each is saved with.
     * No SVG: served from our own origin, it could run script when opened directly.
     */
    public const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
    ];

    /**
     * Largest poster accepted, in bytes.
     */
    public const MAX_BYTES = 10 * 1024 * 1024;

    // Public Methods
    // =========================================================================

    /**
     * Whether a poster URL from oEmbed data may be downloaded at all.
     *
     * @param mixed $url
     * @return bool
     */
    public static function isAcceptedUrl(mixed $url): bool
    {
        if (!is_string($url)) {
            return false;
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && parse_url($url, PHP_URL_HOST);
    }

    /**
     * The bare, lower-case MIME type of a Content-Type header value.
     *
     * @param string $contentType
     * @return string
     */
    public static function mimeType(string $contentType): string
    {
        return strtolower(trim(explode(';', $contentType)[0]));
    }

    /**
     * The file extension for an accepted MIME type, or `null` if the type isn't accepted.
     *
     * @param string $mimeType
     * @return ?string
     */
    public static function extension(string $mimeType): ?string
    {
        return self::EXTENSIONS[$mimeType] ?? null;
    }

    /**
     * The poster filename for an embed, or `null` if the MIME type isn't accepted.
     *
     * @param string $embedUid
     * @param string $mimeType
     * @return ?string
     */
    public static function filename(string $embedUid, string $mimeType): ?string
    {
        $extension = self::extension($mimeType);

        return $extension ? "$embedUid.$extension" : null;
    }

    /**
     * Reads a stream to the end, or returns `null` as soon as it passes `$maxBytes`.
     * Content-Length isn't trusted; only the bytes actually received count.
     *
     * @param StreamInterface $stream
     * @param int $maxBytes
     * @return ?string
     */
    public static function readCapped(StreamInterface $stream, int $maxBytes = self::MAX_BYTES): ?string
    {
        $body = '';

        while (!$stream->eof()) {
            $body .= $stream->read(65536);

            if (strlen($body) > $maxBytes) {
                return null;
            }
        }

        return $body;
    }
}
