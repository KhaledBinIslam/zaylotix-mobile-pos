<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform-wide, admin-managed -- every shop user sees the same FAQ list. */
class Faq extends Model
{
    protected $fillable = ['question_bn', 'question_en', 'answer_bn', 'answer_en', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
