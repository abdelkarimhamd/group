<?php

namespace App\Modules\Identity\Erasure;

use App\Modules\Identity\Models\User;

/**
 * Something that keeps a customer's account from being erased: money still
 * held or moving, or an order not yet settled. Each module that owns such
 * records registers one (tag `identity.erasure_blockers` in
 * AppServiceProvider), so Identity never reads their tables itself.
 *
 * Called inside the erasure transaction, with the customer's row locked and
 * their addresses already deleted, so no new join can start meanwhile.
 */
interface ErasureBlocker
{
    /**
     * The reason in `AccountErasure::BLOCKED_BY`, or null when nothing here
     * stands in the way.
     */
    public function blocks(User $user): ?string;
}
