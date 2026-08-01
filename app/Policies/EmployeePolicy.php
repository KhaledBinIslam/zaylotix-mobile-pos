<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksTenantOwnership;

class EmployeePolicy
{
    use ChecksTenantOwnership;
}
