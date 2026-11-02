<?php

namespace App\Modules\Catalog\Enums;

/**
 * Mirrors the CHECK constraint on products.status.
 */
enum ProductStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
