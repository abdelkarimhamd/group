<?php

namespace App\Support\Files;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Removing a file nothing refers to any more. The S3 disks throw on a failed
 * delete, but by then the database has already moved on (the row is gone, or
 * points at the new file), so a failure is reported and logged for cleanup
 * instead of failing a request that did what it was asked, or hiding the
 * error that made us clean up in the first place.
 */
final class StoredFiles
{
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
