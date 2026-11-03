<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\AdminController;
use App\Http\Resources\CursorPage;
use App\Models\AuditLog;
use App\Modules\Finance\Http\Requests\AdminListAuditLogsRequest;
use App\Modules\Finance\Http\Resources\AuditLogEntryResource;
use App\Modules\Identity\Models\AdminUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;

/**
 * Who did what, newest first. Subjects and admins are named by their
 * public IDs, as everywhere else in the API.
 */
final class AdminAuditLogController extends AdminController
{
    public function index(AdminListAuditLogsRequest $request): JsonResponse
    {
        $query = AuditLog::query()->with(AuditLogEntryResource::RELATIONS);

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->validated('subject_type'));
        }

        if ($request->filled('subject_id')) {
            /** @var class-string<Model> $class */
            $class = Relation::getMorphedModel($request->validated('subject_type'));
            // An unknown subject matches nothing (never `subject_id IS NULL`).
            $query->where('subject_id', $class::query()->where('uuid', $request->validated('subject_id'))->value('id') ?? 0);
        }

        if ($request->filled('actor_admin_id')) {
            $query->where('actor_type', 'admin')
                ->where('actor_id', AdminUser::query()->where('uuid', $request->validated('actor_admin_id'))->value('id') ?? 0);
        }

        $page = $query
            ->when($request->validated('action'), fn (Builder $query, string $action) => $query->where('action', $action))
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->validated('per_page', 20));

        $this->namePublicly($page->getCollection());

        return CursorPage::response($page, AuditLogEntryResource::class);
    }

    /**
     * Sets `subject_public_id` on each entry: the subject's UUID, or its row
     * ID when it has none (or is gone).
     *
     * @param  iterable<AuditLog>  $entries
     */
    private function namePublicly(iterable $entries): void
    {
        $byType = [];

        foreach ($entries as $entry) {
            if ($entry->subject_type !== null && $entry->subject_id !== null) {
                $byType[$entry->subject_type][] = $entry->subject_id;
            }
        }

        $uuids = [];

        foreach ($byType as $type => $ids) {
            $class = Relation::getMorphedModel($type);

            if ($class === null || ! AdminListAuditLogsRequest::hasPublicId($class)) {
                continue;
            }

            $uuids[$type] = $class::query()->whereKey(array_unique($ids))->pluck('uuid', 'id')->all();
        }

        foreach ($entries as $entry) {
            $entry->setAttribute('subject_public_id', $entry->subject_id === null
                ? null
                : ($uuids[$entry->subject_type][$entry->subject_id] ?? (string) $entry->subject_id));
        }
    }
}
