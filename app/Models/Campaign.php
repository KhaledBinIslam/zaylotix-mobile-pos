<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved bundle of (an existing Promotion + a customer segment + a
 * message template) — see the migration's own docblock and
 * docs/FIX_PLAN.md's "সিজন ক্যাম্পেইন কিট" design note. Launching one
 * (CampaignController::launch()) just activates its linked Promotion (if
 * any) and hands the frontend the resolved segment + rendered template so
 * it can open the existing WhatsApp send queue — this model holds no
 * discount or messaging logic of its own.
 */
class Campaign extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'season_id', 'promotion_id', 'name', 'segment', 'message_template', 'last_launched_at'];

    protected function casts(): array
    {
        return [
            'last_launched_at' => 'datetime',
        ];
    }

    public function season()
    {
        return $this->belongsTo(Season::class);
    }

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }
}
