<?php

namespace App\Modules\Identity\Enums;

/**
 * What a sign-in code unlocks. A code issued for one purpose is refused for
 * the other, so a customer code can never complete an admin sign-in.
 */
enum OtpPurpose: string
{
    case CustomerLogin = 'customer_login';
    case AdminLogin = 'admin_login';
}
