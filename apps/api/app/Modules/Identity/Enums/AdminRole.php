<?php

namespace App\Modules\Identity\Enums;

/**
 * Admin roles. The names are part of the API contract (AdminUser.roles).
 * Which permissions each role holds is defined in AdminPermissions.
 */
enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case Sourcing = 'sourcing';
    case CampaignManager = 'campaign_manager';
    case Finance = 'finance';
    case Operations = 'operations';
    case Support = 'support';
}
