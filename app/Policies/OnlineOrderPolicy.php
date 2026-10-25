<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksTenantOwnership;

class OnlineOrderPolicy
{
    use ChecksTenantOwnership;
}
