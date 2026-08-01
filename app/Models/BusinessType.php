<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessType extends Model
{
    protected $fillable = ['slug', 'label_bn', 'label_en', 'fields', 'is_active'];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function shops()
    {
        return $this->hasMany(Shop::class);
    }

    public function hasField(string $field): bool
    {
        return in_array($field, $this->fields ?? [], true);
    }
}
