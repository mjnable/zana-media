<?php

declare(strict_types=1);

namespace Zana\Media;

use Psr\Http\Message\UploadedFileInterface;
use Zana\Filesystem\FilesystemInterface;

/**
 * Stores images for the web (FE-31):
 *
 *     $media = $library->store($request->getUploadedFiles()['photo'], ['en' => 'The team', 'fr' => 'L’équipe']);
 *
 * - The original goes to the private disk, never published.
 * - Variants go to the public disk: each width (no wider than the original)
 *   in each format (AVIF and WebP by default), plus a JPEG fallback at the
 *   largest width. Re-encoding drops EXIF metadata.
 * - Alt text is required, per locale.
 */
final readonly class MediaLibrary
{
    /**
     * @param list<int> $widths
     * @param list<'avif'|'webp'> $formats
     */
    public function __construct(
        private FilesystemInterface $public,
        private FilesystemInterface $private,
        private array $widths = [480, 960, 1600],
        private array $formats = ['avif', 'webp'],
        private int $quality = 75,
        private string $directory = 'media',
    ) {
    }

    /**
     * @param array<string, string> $alt locale => text, at least one
     * @throws MediaException For missing alt text, or a file that is not an accepted image
     */
    public function store(UploadedFileInterface|string $image, array $alt): Media
    {
        $alt = array_filter(array_map('trim', $alt), static fn (string $text): bool => $text !== '');
        if ($alt === []) {
            throw new MediaException('Describe the image: alt text is required in at least one locale.');
        }

        $bytes = $image instanceof UploadedFileInterface ? self::uploadedBytes($image) : $image;
        $source = ImageProcessor::decode($bytes);
        $id = bin2hex(random_bytes(12));
        $base = "{$this->directory}/{$id}";

        $this->private->write("{$base}/original", $bytes);

        $widths = array_values(array_unique(array_map(static fn (int $w): int => min($w, imagesx($source)), $this->widths === [] ? [imagesx($source)] : $this->widths)));
        sort($widths);
        $variants = [];
        foreach ($widths as $width) {
            $resized = ImageProcessor::resize($source, $width);
            foreach ($this->formats as $format) {
                $variants[] = $this->write($resized, $format, "{$base}/{$width}.{$format}");
            }
        }
        $widest = max([1, ...$widths]);
        $variants[] = $this->write(ImageProcessor::resize($source, $widest), 'jpeg', "{$base}/{$widest}.jpg");

        $media = new Media($id, imagesx($source), imagesy($source), $alt, $variants);
        $this->private->write("{$base}/media.json", json_encode($media->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $media;
    }

    public function find(string $id): ?Media
    {
        $file = "{$this->directory}/{$id}/media.json";
        if (preg_match('/^[0-9a-f]{24}$/', $id) !== 1 || !$this->private->exists($file)) {
            return null;
        }
        /** @var array{id: string, width: int, height: int, alt: array<string, string>, variants: list<array{width: int, height: int, format: string, path: string}>} $data */
        $data = json_decode($this->private->read($file), true, flags: JSON_THROW_ON_ERROR);

        return Media::fromArray($data);
    }

    /**
     * Change the alt text, keeping the image.
     *
     * @param array<string, string> $alt
     */
    public function describe(Media $media, array $alt): Media
    {
        $updated = new Media($media->id, $media->width, $media->height, [...$media->alt, ...$alt], $media->variants);
        $this->private->write("{$this->directory}/{$media->id}/media.json", json_encode($updated->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $updated;
    }

    public function delete(Media $media): void
    {
        foreach ($media->variants as $variant) {
            $this->public->delete($variant->path);
        }
        $this->private->deleteDirectory("{$this->directory}/{$media->id}");
    }

    public function url(Variant $variant): string
    {
        return $this->public->url($variant->path);
    }

    /**
     * @param 'avif'|'webp'|'jpeg' $format
     */
    private function write(\GdImage $image, string $format, string $path): Variant
    {
        $this->public->write($path, ImageProcessor::encode($image, $format, $this->quality));

        return new Variant(imagesx($image), imagesy($image), $format, $path);
    }

    private static function uploadedBytes(UploadedFileInterface $file): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new MediaException("The upload failed with error code {$file->getError()}.");
        }
        $stream = $file->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $stream->getContents();
    }
}
