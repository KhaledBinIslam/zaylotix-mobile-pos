<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksTenantOwnership;

class SalesChannelPolicy
{
    use ChecksTenantOwnership;
}
