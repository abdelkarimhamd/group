<?php

namespace App\Modules\Catalog\Services;

use GdImage;
use Illuminate\Http\UploadedFile;

/**
 * Product photos are stored as WebP, no larger than groupbuy.media.photo_max_px
 * on their long side, before anyone downloads them. Listing photos often weigh
 * several megabytes, and a customer on a phone over a weak network waits for
 * every byte of the first campaign card.
 *
 * A photo is kept exactly as uploaded when GD cannot read it, when it has more
 * pixels than PHP's memory allows decoding (photo_max_source_pixels), or when
 * it already fits and re-encoding would not make it smaller.
 */
final class ProductPhotos
{
    /**
     * The file to store: a new WebP file in the temp directory, or the upload
     * itself. Call discard() with the result once it is stored.
     */
    public static function optimize(UploadedFile $upload): UploadedFile
    {
        $path = $upload->getRealPath();
        $info = $path === false || ! function_exists('imagewebp') ? false : @getimagesize($path);

        if ($info === false || (int) config('groupbuy.media.photo_max_source_pixels') < $info[0] * $info[1]) {
            return $upload;
        }

        $source = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };

        if ($source === false) {
            return $upload;
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $source = self::upright($source, $path);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $max = (int) config('groupbuy.media.photo_max_px');
        $scale = min(1, $max / max($width, $height));
        $resized = $scale < 1;
        $photo = $resized ? self::scaled($source, (int) round($width * $scale), (int) round($height * $scale)) : $source;

        if (! imageistruecolor($photo)) {
            imagepalettetotruecolor($photo);
        }
        imagealphablending($photo, false);
        imagesavealpha($photo, true);

        $out = tempnam(sys_get_temp_dir(), 'photo');
        $written = $out !== false && imagewebp($photo, $out, (int) config('groupbuy.media.photo_webp_quality'));

        if (! $written || (! $resized && filesize($out) >= $upload->getSize())) {
            if ($out !== false) {
                @unlink($out);
            }

            return $upload;
        }

        $name = pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME) ?: 'photo';

        return new UploadedFile($out, $name.'.webp', 'image/webp', null, true);
    }

    /** Removes the temp file optimize() made, if it made one. */
    public static function discard(UploadedFile $stored, UploadedFile $upload): void
    {
        if ($stored !== $upload && is_file($stored->getPathname())) {
            @unlink($stored->getPathname());
        }
    }

    /**
     * Phone photos keep their pixels sideways and say how to turn them in
     * EXIF. The WebP file has no EXIF, so the turn is applied to the pixels.
     */
    private static function upright(GdImage $image, string $path): GdImage
    {
        $exif = function_exists('exif_read_data') ? @exif_read_data($path) : false;
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $turn = fn (int $degrees): GdImage => imagerotate($image, $degrees, 0) ?: $image;

        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $image = $turn(180);
                break;
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 5:
                $image = $turn(90);
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 6:
                $image = $turn(270);
                break;
            case 7:
                $image = $turn(90);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 8:
                $image = $turn(90);
                break;
        }

        return $image;
    }

    private static function scaled(GdImage $source, int $width, int $height): GdImage
    {
        $target = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), imagesx($source), imagesy($source));

        return $target;
    }
}
