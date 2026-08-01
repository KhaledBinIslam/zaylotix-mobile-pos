<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksTenantOwnership;

class ProductPolicy
{
    use ChecksTenantOwnership;
}
