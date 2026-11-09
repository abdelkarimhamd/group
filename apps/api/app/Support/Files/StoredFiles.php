<?php

namespace App\Support\Files;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Uploads are written before the row that points at them, and removed if
 * that row is never written. Removing a file nothing refers to any more is
 * quiet: the S3 disks throw on a failed delete, but by then the database
 * has already moved on (the row is gone, or points at the new file), so a
 * failure is reported and logged for cleanup instead of failing a request
 * that did what it was asked, or hiding the error that made us clean up in
 * the first place.
 */
final class StoredFiles
{
    /**
     * Store the upload as `{directory}/{name}.{extension}` (a fresh UUIDv7
     * unless a name is given), then run `$then` with its path; if `$then`
     * throws, the file is deleted and the error passed on. `$options` go to
     * the disk with the file (for S3, object settings such as CacheControl).
     *
     * @template T
     *
     * @param  Closure(string): T  $then
     * @param  array<string, string>  $options
     * @return T
     */
    public static function storeThen(UploadedFile $file, string $directory, string $disk, Closure $then, ?string $name = null, array $options = []): mixed
    {
        $path = $file->storeAs($directory, ($name ?? (string) Str::uuid7()).'.'.$file->extension(), ['disk' => $disk] + $options);

        if ($path === false) {
            throw new RuntimeException("Could not store an upload under {$directory} on the {$disk} disk.");
        }

        try {
            return $then($path);
        } catch (Throwable $e) {
            self::deleteQuietly($disk, $path);

            throw $e;
        }
    }

    public static function deleteQuietly(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable $e) {
            report($e);
            Log::warning('storage.orphan_file', ['disk' => $disk, 'path' => $path]);
        }
    }
}
