<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `AdminUser` schema in openapi/v1.yaml. The portal uses `permissions`
 * to hide actions; the API enforces them regardless.
 *
 * @mixin AdminUser
 */
class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'email' => $this->email,
            'name' => $this->name,
            'phone' => $this->phone_e164,
            'status' => $this->status->value,
            'roles' => $this->resource->getRoleNames()->sort()->values()->all(),
            'permissions' => $this->resource->getAllPermissions()->pluck('name')->unique()->sort()->values()->all(),
            'last_login_at' => $this->last_login_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
