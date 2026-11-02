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
            'roles' => $this->resource->getRoleNames()->sort()->values()->all(),
            'permissions' => $this->resource->getAllPermissions()->pluck('name')->sort()->values()->all(),
        ];
    }
}
