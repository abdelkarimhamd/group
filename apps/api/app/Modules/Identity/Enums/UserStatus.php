<?php

namespace App\Modules\Identity\Enums;

/**
 * Mirrors the CHECK constraint on users.status.
 */
enum UserStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
    case Anonymized = 'anonymized';
}
