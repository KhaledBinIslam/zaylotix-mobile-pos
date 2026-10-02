<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksTenantOwnership;

class ShopBackupPolicy
{
    use ChecksTenantOwnership;
}
