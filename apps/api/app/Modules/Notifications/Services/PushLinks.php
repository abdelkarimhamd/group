<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Push\PushSubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Where a tap on a push message takes the customer in the app: the order,
 * the join or the campaign the news is about, by the IDs the API uses
 * (`order_id`, `participation_id`, `campaign_id`). The app opens the most
 * specific one it has. Read at send time, so the IDs are current. Each
 * module says what its models link to (PushSubject).
 */
final class PushLinks
{
    /**
     * @return array<string, string> empty when the subject is gone or links nowhere
     */
    public function for(Notification $notification): array
    {
        $class = $notification->subject_type === null ? null : Relation::getMorphedModel($notification->subject_type);

        if ($class === null || $notification->subject_id === null || ! is_subclass_of($class, PushSubject::class)) {
            return [];
        }

        /** @var class-string<Model&PushSubject> $class */
        $subject = $class::query()->find($notification->subject_id);

        if (! $subject instanceof PushSubject) {
            return [];
        }

        return array_filter($subject->pushLinks(), fn (mixed $id): bool => is_string($id) && $id !== '');
    }
}
