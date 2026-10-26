<?php

declare(strict_types=1);

namespace Zana\Media;

/**
 * A stored image: its size, its alt text per locale, and its variants.
 * Persist it with toArray() (e.g. as JSON next to the content that uses it)
 * and restore it with fromArray(), or find it again with MediaLibrary::find().
 */
final readonly class Media
{
    /**
     * @param array<string, string> $alt locale => text
     * @param list<Variant> $variants
     */
    public function __construct(
        public string $id,
        public int $width,
        public int $height,
        public array $alt,
        public array $variants,
    ) {
    }

    /**
     * The alt text in a locale, else the first one.
     */
    public function alt(string $locale): string
    {
        return $this->alt[$locale] ?? (string) (array_values($this->alt)[0] ?? '');
    }

    /**
     * @param 'avif'|'webp'|'jpeg' $format
     * @return list<Variant> smallest first
     */
    public function variants(string $format): array
    {
        $variants = array_values(array_filter($this->variants, static fn (Variant $v): bool => $v->format === $format));
        usort($variants, static fn (Variant $a, Variant $b): int => $a->width <=> $b->width);

        return $variants;
    }

    /**
     * The fallback image for browsers without AVIF or WebP: the JPEG variant.
     */
    public function fallback(): Variant
    {
        $jpeg = $this->variants('jpeg');

        return $jpeg[array_key_last($jpeg) ?? 0] ?? throw new MediaException("Media {$this->id} has no fallback variant.");
    }

    /**
     * @return array{id: string, width: int, height: int, alt: array<string, string>, variants: list<array{width: int, height: int, format: string, path: string}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'width' => $this->width,
            'height' => $this->height,
            'alt' => $this->alt,
            'variants' => array_map(static fn (Variant $v): array => ['width' => $v->width, 'height' => $v->height, 'format' => $v->format, 'path' => $v->path], $this->variants),
        ];
    }

    /**
     * @param array{id: string, width: int, height: int, alt: array<string, string>, variants: list<array{width: int, height: int, format: string, path: string}>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['width'], $data['height'], $data['alt'], array_map(static function (array $v): Variant {
            if (!in_array($v['format'], ['avif', 'webp', 'jpeg'], true)) {
                throw new MediaException("Unknown image format {$v['format']}.");
            }

            return new Variant($v['width'], $v['height'], $v['format'], $v['path']);
        }, $data['variants']));
    }
}
