# mj-zana/media

Responsive images for Zana applications: WebP and AVIF variants in several
widths, metadata stripped, and alt text per locale. Uses GD only.

```
composer require mj-zana/media
```

Register `MediaServiceProvider` and configure two disks
([filesystem](https://github.com/mjnable/zana-filesystem)): a public one
with a `url`, and a private one for the originals.

```php
// config/media.php
return ['disk' => 'public', 'private_disk' => 'private', 'widths' => [480, 960, 1600]];
```

```php
$media = $library->store($request->getUploadedFiles()['photo'], ['en' => 'The team', 'fr' => 'L’équipe']);
$post->imageJson = json_encode($media->toArray());
```

With `mj-zana/view`, a template prints the image with
`<?= $this->picture($media, sizes: '(min-width: 60em) 50vw, 100vw') ?>`:
a `<picture>` with AVIF and WebP sources, a JPEG fallback, width and height,
lazy loading, and the alt text in the current locale.

## Rules

- **Originals stay private.** Only the variants are published.
- **Metadata is gone.** Variants are re-encoded, so EXIF data such as the
  GPS position and camera serial is never published. JPEG orientation is
  applied first (with `ext-exif`).
- **Widths:** no variant is wider than the original.
- **Alt text** is required in at least one locale.
- **Accepted formats:** JPEG, PNG, WebP, GIF and AVIF, up to 50 megapixels.

## License

MIT
