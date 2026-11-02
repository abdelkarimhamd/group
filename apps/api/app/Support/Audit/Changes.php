<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * The before and after values of a model's unsaved changes, for the audit log.
 * Call it after filling the model and before saving it.
 */
final class Changes
{
    /**
     * @param  list<string>  $hidden  attributes to leave out (secrets)
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function pending(Model $model, array $hidden = []): array
    {
        $keys = array_diff(array_keys($model->getDirty()), [$model->getUpdatedAtColumn(), ...$hidden]);
        $before = $after = [];

        foreach ($keys as $key) {
            $old = $model->exists ? $model->getOriginal($key) : null;
            $new = $model->getAttribute($key);

            // JSON columns come back from jsonb with their keys reordered; the
            // same map in another order is not a change (array == ignores order).
            if ($model->exists && is_array($old) && is_array($new) && $old == $new) {
                continue;
            }

            $before[$key] = $old;
            $after[$key] = $new;
        }

        return [$before, $after];
    }
}
