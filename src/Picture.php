<?php

declare(strict_types=1);

namespace Zana\Media;

/**
 * Prints the responsive <picture> element for a Media: AVIF and WebP
 * sources with srcset, the JPEG fallback, width and height (no layout
 * shift), lazy loading, and the alt text in the locale.
 */
final readonly class Picture
{
    public function __construct(private MediaLibrary $library)
    {
    }

    /**
     * @param string $sizes The "sizes" attribute: the displayed width, e.g. "(min-width: 60em) 50vw, 100vw"
     */
    public function html(Media $media, string $locale, string $sizes = '100vw', bool $lazy = true, string $class = ''): string
    {
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = '<picture>';
        foreach (['avif', 'webp'] as $format) {
            $variants = $media->variants($format);
            if ($variants === []) {
                continue;
            }
            $srcset = implode(', ', array_map(fn (Variant $v): string => $this->library->url($v) . " {$v->width}w", $variants));
            $html .= sprintf('<source type="image/%s" srcset="%s" sizes="%s">', $format, $escape($srcset), $escape($sizes));
        }

        $fallback = $media->fallback();
        $html .= sprintf(
            '<img src="%s" width="%d" height="%d" alt="%s"%s%s decoding="async">',
            $escape($this->library->url($fallback)),
            $fallback->width,
            $fallback->height,
            $escape($media->alt($locale)),
            $lazy ? ' loading="lazy"' : '',
            $class === '' ? '' : ' class="' . $escape($class) . '"',
        );

        return $html . '</picture>';
    }
}
