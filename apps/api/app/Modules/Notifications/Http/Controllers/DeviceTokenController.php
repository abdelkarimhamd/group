<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Http\Requests\DeviceTokenRequest;
use App\Modules\Notifications\Services\DeviceTokens;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The mobile app registers its Firebase token after sign-in and whenever
 * Firebase gives it a new one. Signing out removes it.
 */
final class DeviceTokenController extends Controller
{
    public function store(DeviceTokenRequest $request, DeviceTokens $tokens): Response
    {
        $accessToken = $request->user()->currentAccessToken();

        $tokens->register(
            $request->user(),
            $accessToken instanceof PersonalAccessToken ? $accessToken->id : null,
            $request->string('platform')->value(),
            $request->string('token')->value(),
        );

        return response()->noContent();
    }
}
