<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksTenantOwnership;

class SupplierPolicy
{
    use ChecksTenantOwnership;
}
