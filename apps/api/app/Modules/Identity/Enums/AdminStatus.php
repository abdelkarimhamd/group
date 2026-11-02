<?php

namespace App\Modules\Identity\Enums;

/**
 * Mirrors the CHECK constraint on admin_users.status.
 */
enum AdminStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
}
