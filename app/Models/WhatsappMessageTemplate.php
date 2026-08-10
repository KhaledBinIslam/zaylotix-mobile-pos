<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A shop's own saved, reusable WhatsApp message — see the migration's docblock for why this is distinct from a Meta-approved Template. */
class WhatsappMessageTemplate extends Model
{
    use BelongsToTenant;

    protected $fillable = ['shop_id', 'label', 'send_type', 'template_name', 'language_code', 'message'];
}
