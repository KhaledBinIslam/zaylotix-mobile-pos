<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform-level reference data, deliberately NOT tenant-scoped (no BelongsToTenant) — every shop searches the same shared catalog. */
class MedicineCatalog extends Model
{
    protected $table = 'medicine_catalog';

    protected $fillable = ['name', 'generic_name', 'company', 'form', 'strength'];
}
