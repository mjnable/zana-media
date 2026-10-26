<?php

declare(strict_types=1);

namespace Zana\Media;

/**
 * Decodes, orients, resizes and encodes images with GD. Encoding writes only
 * pixels, so EXIF metadata (GPS position, camera serial) never survives.
 *
 * @internal
 */
final class ImageProcessor
{
    private const array ACCEPTED = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF, IMAGETYPE_AVIF];

    /**
     * @throws MediaException When the bytes are not an image we accept
     */
    public static function decode(string $bytes): \GdImage
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info[2], self::ACCEPTED, true)) {
            throw new MediaException('The file is not a JPEG, PNG, WebP, GIF or AVIF image.');
        }
        if ($info[0] * $info[1] > 50_000_000) {
            throw new MediaException('The image is too large (more than 50 megapixels).');
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new MediaException('The image cannot be decoded.');
        }

        return $info[2] === IMAGETYPE_JPEG ? self::orient($image, $bytes) : $image;
    }

    public static function resize(\GdImage $image, int $width): \GdImage
    {
        $width = max(1, $width);
        if ($width >= imagesx($image)) {
            return $image;
        }
        $height = max(1, (int) round(imagesy($image) * $width / imagesx($image)));
        $resized = imagecreatetruecolor($width, $height);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        return $resized;
    }

    /**
     * @param 'avif'|'webp'|'jpeg' $format
     */
    public static function encode(\GdImage $image, string $format, int $quality): string
    {
        ob_start();
        $done = match ($format) {
            'avif' => imageavif($image, null, $quality),
            'webp' => imagewebp($image, null, $quality),
            'jpeg' => self::jpeg($image, $quality),
        };
        $bytes = (string) ob_get_clean();
        if (!$done || $bytes === '') {
            throw new MediaException("The image cannot be encoded as {$format}.");
        }

        return $bytes;
    }

    private static function jpeg(\GdImage $image, int $quality): bool
    {
        // JPEG has no transparency: flatten on white.
        $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        imageinterlace($flat, true);

        return imagejpeg($flat, null, $quality);
    }

    /**
     * Phones store photos sideways with an EXIF Orientation; apply it, as the
     * metadata is about to be dropped.
     */
    private static function orient(\GdImage $image, string $bytes): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated === false ? $image : $rotated;
    }
}
