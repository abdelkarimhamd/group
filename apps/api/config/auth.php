<?php

use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\User;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| Two kinds of people sign in, with separate tables, guards and tokens:
|
| - customer: users table, phone number plus SMS code.
| - admin: admin_users table, password plus SMS code.
|
| Both guards read Sanctum bearer tokens. Each guard accepts only tokens that
| belong to its own model, so a customer token is refused on admin routes and
| the reverse. Token lifetimes live in config/groupbuy.php.
|
*/

return [

    'defaults' => [
        'guard' => 'customer',
    ],

    'guards' => [
        'customer' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],

        'admin' => [
            'driver' => 'sanctum',
            'provider' => 'admins',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],

        'admins' => [
            'driver' => 'eloquent',
            'model' => AdminUser::class,
        ],
    ],

    'password_timeout' => 10800,

];
