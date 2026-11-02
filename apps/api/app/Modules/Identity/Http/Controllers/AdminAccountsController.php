<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\CursorPage;
use App\Modules\Identity\Admins\AdminAccounts;
use App\Modules\Identity\Http\Requests\CreateAdminRequest;
use App\Modules\Identity\Http\Requests\ListAdminsRequest;
use App\Modules\Identity\Http\Requests\UpdateAdminRequest;
use App\Modules\Identity\Http\Resources\AdminUserResource;
use App\Modules\Identity\Models\AdminUser;
use Illuminate\Http\JsonResponse;

/**
 * Admin accounts, for admins with admins.manage (checked on the routes).
 */
final class AdminAccountsController extends Controller
{
    public function __construct(private readonly AdminAccounts $accounts) {}

    public function index(ListAdminsRequest $request): JsonResponse
    {
        $page = AdminUser::query()
            ->with(['roles.permissions', 'permissions'])
            ->when($request->validated('status'), fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('id')
            ->cursorPaginate((int) $request->validated('per_page', 20));

        return CursorPage::response($page, AdminUserResource::class);
    }

    public function store(CreateAdminRequest $request): JsonResponse
    {
        $admin = $this->accounts->create([
            'email' => $request->validated('email'),
            'name' => $request->validated('name'),
            'phone' => $request->validated('phone'),
            'password' => $request->validated('password'),
            'roles' => $request->validated('roles'),
        ], $request->user());

        return AdminUserResource::make($admin)->response()->setStatusCode(201);
    }

    public function update(UpdateAdminRequest $request, string $admin): AdminUserResource
    {
        $target = AdminUser::query()->where('uuid', $admin)->firstOrFail();

        $updated = $this->accounts->update($target, $request->validated(), $request->user());

        return AdminUserResource::make($updated->fresh());
    }
}
