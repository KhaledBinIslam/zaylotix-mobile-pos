<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksTenantOwnership;

class PartnerPolicy
{
    use ChecksTenantOwnership;
}
