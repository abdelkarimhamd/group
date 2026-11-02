<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * Refuses updates and deletes in the application before the database
 * trigger does, so a mistake fails with a clear message.
 */
trait AppendOnlyModel
{
    public static function bootAppendOnlyModel(): void
    {
        static::updating(function ($model): void {
            throw new LogicException(class_basename($model).' records are append-only and cannot be updated.');
        });

        static::deleting(function ($model): void {
            throw new LogicException(class_basename($model).' records are append-only and cannot be deleted.');
        });
    }
}
