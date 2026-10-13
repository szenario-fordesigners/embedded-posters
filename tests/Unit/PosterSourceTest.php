<?php

namespace szenario\craftembeddedposters\tests\Unit;

use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use szenario\craftembeddedposters\helpers\PosterSource;

class PosterSourceTest extends TestCase
{
    public static function urls(): array
    {
        return [
            ['https://i1.sndcdn.com/artworks-x-t500x500.jpg', true],
            ['http://i.ytimg.com/vi/x/hqdefault.jpg', true],
            ['HTTPS://example.com/x.jpg', true],
            ['file:///etc/passwd', false],
            ['data:image/png;base64,iVBORw0KGgo=', false],
            ['ftp://example.com/x.jpg', false],
            ['//cdn.example/x.jpg', false],
            ['https://', false],
            ['', false],
            [null, false],
            [['https://example.com/x.jpg'], false],
        ];
    }

    #[DataProvider('urls')]
    public function testIsAcceptedUrl(mixed $url, bool $expected): void
    {
        $this->assertSame($expected, PosterSource::isAcceptedUrl($url));
    }

    public function testMimeTypeStripsParametersAndCase(): void
    {
        $this->assertSame('image/jpeg', PosterSource::mimeType('image/JPEG; charset=binary'));
        $this->assertSame('', PosterSource::mimeType(''));
    }

    public function testAllowlist(): void
    {
        $this->assertSame('jpg', PosterSource::extension('image/jpeg'));
        $this->assertSame('png', PosterSource::extension('image/png'));
        $this->assertSame('webp', PosterSource::extension('image/webp'));
        $this->assertSame('gif', PosterSource::extension('image/gif'));
        $this->assertSame('avif', PosterSource::extension('image/avif'));

        $this->assertNull(PosterSource::extension('image/svg+xml'));
        $this->assertNull(PosterSource::extension('text/html'));
        $this->assertNull(PosterSource::extension('application/octet-stream'));
    }

    public function testFilename(): void
    {
        $this->assertSame('6f1c-uid.png', PosterSource::filename('6f1c-uid', 'image/png'));
        $this->assertNull(PosterSource::filename('6f1c-uid', 'image/svg+xml'));
    }

    public function testReadCappedReturnsBodyUpToCap(): void
    {
        $this->assertSame(str_repeat('a', 100), PosterSource::readCapped(Utils::streamFor(str_repeat('a', 100)), 100));
    }

    public function testReadCappedStopsPastCap(): void
    {
        $stream = Utils::streamFor(str_repeat('a', 1_000_000));

        $this->assertNull(PosterSource::readCapped($stream, 100_000));
        $this->assertFalse($stream->eof(), 'reader should stop early, not read the whole stream');
    }
}
