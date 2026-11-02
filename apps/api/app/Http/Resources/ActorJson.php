<?php

namespace App\Http\Resources;

use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\User;
use App\Support\ActorType;

/**
 * The contract's `Actor` schema: who did something, by public ID and name.
 * Records store the actor's type and internal ID; load the matching admin or
 * user (both relations, one per type) and pass them here.
 */
final class ActorJson
{
    /**
     * @return array{type: string, id: string|null, name: string|null}
     */
    public static function from(string $type, ?AdminUser $admin = null, ?User $user = null): array
    {
        return match (ActorType::from($type)) {
            ActorType::Admin => ['type' => 'admin', 'id' => $admin?->uuid, 'name' => $admin?->name],
            ActorType::User => ['type' => 'user', 'id' => $user?->uuid, 'name' => $user?->name],
            ActorType::System => ['type' => 'system', 'id' => null, 'name' => null],
        };
    }

    /**
     * @return array{type: string, id: string|null, name: string|null}|null
     */
    public static function admin(?AdminUser $admin): ?array
    {
        return $admin === null ? null : self::from('admin', $admin);
    }
}
