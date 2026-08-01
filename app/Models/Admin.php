<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Admin extends Authenticatable
{
    use Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = ['password', 'remember_token'];

    // Mirrors the DB-level default (see the migration) at the PHP layer —
    // SQLite (used in tests) doesn't reliably apply an ALTER-TABLE-added
    // column's default to new inserts the way MySQL (production) does, so
    // relying on the DB default alone left every admin created without an
    // explicit role silently NULL under SQLite.
    protected $attributes = [
        'role' => 'super_admin',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }
}
