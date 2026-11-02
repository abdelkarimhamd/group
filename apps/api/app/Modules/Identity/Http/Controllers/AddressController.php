<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Requests\AddressRequest;
use App\Modules\Identity\Http\Resources\AddressResource;
use App\Modules\Identity\Models\Address;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in customer's delivery addresses. A customer only ever sees and
 * changes their own; anyone else's address is a 404.
 *
 * Writes lock the customer's row first, so the one-default rule and the
 * address limit hold when two requests arrive together.
 */
final class AddressController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $addresses = $this->customer($request)->addresses()
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return AddressResource::collection($addresses);
    }

    public function store(AddressRequest $request): JsonResponse
    {
        $user = $this->customer($request);

        $address = DB::transaction(function () use ($request, $user): Address {
            $this->lock($user);

            $count = $user->addresses()->count();

            if ($count >= (int) config('groupbuy.account.max_addresses')) {
                throw ApiException::unprocessable('address_limit_reached');
            }

            $address = $user->addresses()->make($request->safe()->except('is_default'));

            // The first address is the default whatever the client sent.
            $this->save($user, $address, $count === 0 || $request->boolean('is_default'));

            return $address;
        });

        return AddressResource::make($address)->response()->setStatusCode(201);
    }

    public function update(AddressRequest $request, string $address): AddressResource
    {
        $user = $this->customer($request);

        $updated = DB::transaction(function () use ($request, $user, $address): Address {
            $this->lock($user);

            $model = $this->find($user, $address);

            // An update sends the whole address: optional fields left out are cleared.
            $model->fill([
                ...array_fill_keys(AddressRequest::OPTIONAL, null),
                ...$request->safe()->except('is_default'),
            ]);

            // The default only moves when another address is made the default,
            // so a customer with addresses always has one.
            $this->save($user, $model, $model->is_default || $request->boolean('is_default'));

            return $model;
        });

        return AddressResource::make($updated);
    }

    public function destroy(Request $request, string $address): Response
    {
        $user = $this->customer($request);

        DB::transaction(function () use ($user, $address): void {
            $this->lock($user);

            $model = $this->find($user, $address);
            $model->delete();

            // Keep a default while any address remains: the newest takes over.
            if ($model->is_default) {
                $user->addresses()->orderByDesc('id')->first()?->forceFill(['is_default' => true])->save();
            }
        });

        return response()->noContent();
    }

    private function save(User $user, Address $address, bool $isDefault): void
    {
        if ($isDefault) {
            $user->addresses()
                ->where('is_default', true)
                ->when($address->exists, fn ($query) => $query->whereKeyNot($address->id))
                ->update(['is_default' => false]);
        }

        $address->is_default = $isDefault;
        $address->save();
    }

    private function find(User $user, string $uuid): Address
    {
        return $user->addresses()->where('uuid', $uuid)->firstOrFail();
    }

    private function lock(User $user): void
    {
        User::query()->whereKey($user->id)->lockForUpdate()->value('id');
    }

    private function customer(Request $request): User
    {
        return $request->user();
    }
}
