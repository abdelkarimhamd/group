<?php

namespace App\Modules\Catalog\Enums;

/**
 * Mirrors the CHECK constraint on product_variants.status.
 */
enum VariantStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
