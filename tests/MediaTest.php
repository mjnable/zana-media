<?php

declare(strict_types=1);

namespace Zana\Media\Tests;

use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\TestCase;
use Zana\Filesystem\InMemoryFilesystem;
use Zana\Media\Media;
use Zana\Media\MediaException;
use Zana\Media\MediaLibrary;
use Zana\Media\Picture;
use Zana\Media\Variant;

final class MediaTest extends TestCase
{
    private InMemoryFilesystem $public;

    private InMemoryFilesystem $private;

    private MediaLibrary $library;

    protected function setUp(): void
    {
        $this->public = new InMemoryFilesystem('https://cdn.example.com');
        $this->private = new InMemoryFilesystem();
        $this->library = new MediaLibrary($this->public, $this->private, [480, 960, 1600], ['avif', 'webp'], 60);
    }

    public function testVariantsInEveryWidthAndFormatNoWiderThanTheOriginal(): void
    {
        $media = $this->library->store(self::photo(1200, 800), ['en' => 'The team', 'fr' => 'L’équipe']);

        $this->assertSame([1200, 800], [$media->width, $media->height]);
        $this->assertSame([480, 960, 1200], array_map(static fn (Variant $v): int => $v->width, $media->variants('webp')));
        $this->assertSame([480, 960, 1200], array_map(static fn (Variant $v): int => $v->width, $media->variants('avif')));
        $this->assertSame([1200, 800, 'jpeg'], [$media->fallback()->width, $media->fallback()->height, $media->fallback()->format]);
        $this->assertSame([320], [$media->variants('webp')[0]->height]);

        foreach ($media->variants as $variant) {
            $info = getimagesizefromstring($this->public->read($variant->path));
            $this->assertNotFalse($info);
            $this->assertSame([$variant->width, $variant->mimeType()], [$info[0], $info['mime']]);
        }
        $this->assertTrue($this->private->exists("media/{$media->id}/original"), 'the original stays private');
        $this->assertFalse($this->public->exists("media/{$media->id}/original"));
    }

    public function testMetadataIsStripped(): void
    {
        $original = self::withExif(self::photo(800, 600));
        $this->assertStringContainsString('GPSLatitude', $original);

        $media = $this->library->store($original, ['en' => 'A street']);

        foreach ($media->variants as $variant) {
            $bytes = $this->public->read($variant->path);
            $this->assertStringNotContainsString('GPSLatitude', $bytes, $variant->path);
            $this->assertStringNotContainsString('Exif', $bytes, $variant->path);
        }
    }

    public function testAltTextIsRequiredAndOnlyImagesAreAccepted(): void
    {
        try {
            $this->library->store(self::photo(100, 100), ['en' => '  ']);
            $this->fail('Expected a MediaException.');
        } catch (MediaException $e) {
            $this->assertStringContainsString('alt text is required', $e->getMessage());
        }

        $this->expectExceptionMessage('The file is not a JPEG, PNG, WebP, GIF or AVIF image.');
        $this->library->store('<?php echo "not an image";', ['en' => 'x']);
    }

    public function testUploadsAreAccepted(): void
    {
        $bytes = self::photo(300, 200);
        $media = $this->library->store(new UploadedFile(Stream::create($bytes), strlen($bytes), UPLOAD_ERR_OK, 'photo.jpg'), ['en' => 'Upload']);

        $this->assertSame(300, $media->width);
    }

    public function testMediaCanBeFoundDescribedAndDeleted(): void
    {
        $media = $this->library->store(self::photo(600, 400), ['en' => 'Office']);

        $found = $this->library->find($media->id);
        $this->assertEquals($media, $found);
        $this->assertNull($this->library->find('../../etc'));

        $described = $this->library->describe($media, ['fr' => 'Bureau']);
        $this->assertSame(['en' => 'Office', 'fr' => 'Bureau'], $this->library->find($media->id)?->alt);
        $this->assertEquals($described, Media::fromArray($described->toArray()));

        $this->library->delete($media);
        $this->assertNull($this->library->find($media->id));
        $this->assertSame([], $this->public->listContents('media', true));
    }

    public function testThePictureElement(): void
    {
        $media = $this->library->store(self::photo(1000, 500), ['en' => 'Tom & Jerry']);

        $html = (new Picture($this->library))->html($media, 'fr', '(min-width: 60em) 50vw, 100vw');

        $base = "https://cdn.example.com/media/{$media->id}";
        $this->assertStringStartsWith('<picture><source type="image/avif" srcset="' . "{$base}/480.avif 480w, {$base}/960.avif 960w, {$base}/1000.avif 1000w" . '" sizes="(min-width: 60em) 50vw, 100vw">', $html);
        $this->assertStringContainsString('<source type="image/webp"', $html);
        $this->assertStringEndsWith('<img src="' . "{$base}/1000.jpg" . '" width="1000" height="500" alt="Tom &amp; Jerry" loading="lazy" decoding="async"></picture>', $html);
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private static function photo(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height, (int) imagecolorallocate($image, 200, 60, 40));
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    /**
     * Insert an APP1 Exif segment with a GPS tag name right after the JPEG's SOI marker.
     */
    private static function withExif(string $jpeg): string
    {
        $payload = "Exif\0\0" . 'MM' . "\0\x2A\0\0\0\x08" . str_pad('GPSLatitude 4.3217S', 40, "\0");
        $segment = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

        return substr($jpeg, 0, 2) . $segment . substr($jpeg, 2);
    }
}
