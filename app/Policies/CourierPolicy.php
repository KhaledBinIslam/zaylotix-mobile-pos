<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksTenantOwnership;

class CourierPolicy
{
    use ChecksTenantOwnership;
}
