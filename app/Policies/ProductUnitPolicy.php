<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksTenantOwnership;

class ProductUnitPolicy
{
    use ChecksTenantOwnership;
}
