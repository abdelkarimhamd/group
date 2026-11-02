<?php

namespace App\Modules\Campaign\Enums;

/**
 * Mirrors the CHECK constraint on campaigns.closing_step: where the closing
 * job is, so a re-run resumes instead of repeating work.
 */
enum ClosingStep: string
{
    case SettlingJoins = 'settling_joins';
    case AwaitingSupplier = 'awaiting_supplier';
    case Capturing = 'capturing';
    case NeedsAdmin = 'needs_admin';
    case Notifying = 'notifying';
    case Done = 'done';
}
