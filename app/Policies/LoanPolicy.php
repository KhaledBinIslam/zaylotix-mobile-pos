<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksTenantOwnership;

class LoanPolicy
{
    use ChecksTenantOwnership;
}
