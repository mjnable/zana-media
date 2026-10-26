<?php

declare(strict_types=1);

namespace Zana\Media;

final readonly class Variant
{
    /**
     * @param 'avif'|'webp'|'jpeg' $format
     */
    public function __construct(
        public int $width,
        public int $height,
        public string $format,
        public string $path,
    ) {
    }

    public function mimeType(): string
    {
        return 'image/' . $this->format;
    }
}
