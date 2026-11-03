<?php

namespace Tests\Fakes;

use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use League\Flysystem\UnableToDeleteFile;

/**
 * Storage::fake(), except that deleting a file fails, as it can on S3 (the
 * real disks are set to throw).
 */
final class UndeletableDisk
{
    public static function fake(string $disk): LocalFilesystemAdapter
    {
        $fake = Storage::fake($disk);

        $broken = new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends LocalFilesystemAdapter
        {
            public function delete($paths)
            {
                $path = is_array($paths) ? (string) reset($paths) : (string) $paths;

                throw UnableToDeleteFile::atLocation($path, 'Storage is not answering (fake).');
            }
        };
        $broken->buildTemporaryUrlsUsing(fn (string $path, $expiration) => URL::to($path.'?expiration='.$expiration->getTimestamp()));
        Storage::set($disk, $broken);

        return $broken;
    }
}
