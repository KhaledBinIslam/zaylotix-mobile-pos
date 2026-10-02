<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksTenantOwnership;

class HeldCartPolicy
{
    use ChecksTenantOwnership;
}
