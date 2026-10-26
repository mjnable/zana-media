<?php

declare(strict_types=1);

namespace Zana\Media;

use Zana\Configuration\ConfigurationInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Core\ServiceProvider;
use Zana\Filesystem\StorageManager;

/**
 * Registers MediaLibrary and Picture. Reads media.disk (the public disk,
 * which needs a "url"), media.private_disk (originals), media.widths,
 * media.formats (avif, webp) and media.quality. With mj-zana/view, templates
 * print images with picture().
 */
final class MediaServiceProvider extends ServiceProvider
{
    public function register(ServiceCollectionInterface $services): void
    {
        $services
            ->singleton(MediaLibrary::class, static function (ServiceResolverInterface $r): MediaLibrary {
                $config = $r->get(ConfigurationInterface::class);
                $storage = $r->get(StorageManager::class);
                assert($storage instanceof StorageManager);

                /** @var list<int> $widths */
                $widths = $config->array('media.widths', [480, 960, 1600]);
                /** @var list<'avif'|'webp'> $formats */
                $formats = $config->array('media.formats', ['avif', 'webp']);

                return new MediaLibrary(
                    $storage->disk($config->has('media.disk') ? $config->string('media.disk') : null),
                    $storage->disk($config->has('media.private_disk') ? $config->string('media.private_disk') : null),
                    $widths,
                    $formats,
                    $config->int('media.quality', 75),
                );
            })
            ->singleton(Picture::class);
    }

    public function origin(): string
    {
        return 'package:mj-zana/media';
    }
}
