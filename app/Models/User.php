<?php

namespace App\Models;

use App\Models\Concerns\StampsTenantOnCreate;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, StampsTenantOnCreate;

    protected $fillable = [
        'shop_id',
        'name',
        'email',
        'phone',
        'password',
        'role',
        'permissions',
        'lang',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
        ];
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /**
     * The owner always has every permission — this column only restricts
     * `role = staff` (cashier) accounts, and only for the sections the
     * owner explicitly ticked when creating/editing that cashier.
     */
    public function hasPermission(string $key): bool
    {
        if ($this->isOwner()) {
            return true;
        }

        return in_array($key, $this->permissions ?? [], true);
    }

    public function permissionKeys(): array
    {
        return $this->isOwner()
            ? array_keys(config('staff_permissions'))
            : ($this->permissions ?? []);
    }
}
